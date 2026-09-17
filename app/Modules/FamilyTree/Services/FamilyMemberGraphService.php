<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\RelationshipEdgeType;
use App\Models\User;
use App\Models\UserDeclaredRelative;
use App\Modules\FamilyTree\Exceptions\DuplicateMemberCandidateException;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyMemberGraphService
{
    public function __construct(
        private readonly FamilyMatcherService $matcher,
        private readonly FamilyMemberCandidateService $candidates,
    ) {}
    /** @param  array<string, mixed>|null  $data */
    public function relativeHasInfo(?array $data): bool
    {
        if ($data === null) {
            return false;
        }

        foreach (['first_name', 'last_name', 'date_of_birth'] as $field) {
            if (! empty($data[$field])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function formatRelative(FamilyMember $member): array
    {
        return [
            'uuid' => $member->uuid,
            'member_code' => $member->member_code,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'date_of_death' => $member->date_of_death?->format('Y-m-d'),
            'gender' => $member->gender,
            'is_living' => $member->is_living,
            'is_registered' => $member->user_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function familyInfoForMember(FamilyMember $selfMember, ?User $user = null): array
    {
        $selfParents = $this->parentsOf($selfMember->uuid, $user);
        $spouses = $this->findSpouseMembers($selfMember);
        $spousePayloads = [];

        foreach ($spouses as $spouse) {
            $spouseParents = $this->parentsOf($spouse->uuid, $user);
            $spousePayloads[] = [
                ...$this->formatRelative($spouse),
                'marriage_date' => $this->marriageDateBetween($selfMember, $spouse)?->format('Y-m-d'),
                'father' => isset($spouseParents['father'])
                    ? $this->formatRelative($spouseParents['father'])
                    : null,
                'mother' => isset($spouseParents['mother'])
                    ? $this->formatRelative($spouseParents['mother'])
                    : null,
            ];
        }

        $primarySpouse = $spouses->first();
        $primaryParents = $primarySpouse ? $this->parentsOf($primarySpouse->uuid, $user) : [];
        $spouseUuids = $spouses->pluck('uuid')->all();

        return [
            'father' => isset($selfParents['father'])
                ? $this->formatRelative($selfParents['father'])
                : null,
            'mother' => isset($selfParents['mother'])
                ? $this->formatRelative($selfParents['mother'])
                : null,
            'has_multiple_partners' => $spouses->count() > 1,
            'spouses' => $spousePayloads,
            // Legacy singular fields mirror the first spouse for older clients.
            'spouse' => $primarySpouse ? $this->formatRelative($primarySpouse) : null,
            'spouse_father' => isset($primaryParents['father'])
                ? $this->formatRelative($primaryParents['father'])
                : null,
            'spouse_mother' => isset($primaryParents['mother'])
                ? $this->formatRelative($primaryParents['mother'])
                : null,
            'children' => $this->childrenOf($selfMember->uuid)
                ->map(function (FamilyMember $child) use ($selfMember, $spouseUuids) {
                    $payload = $this->formatRelative($child);
                    $payload['other_parent_uuid'] = $this->otherParentUuidAmong(
                        $child->uuid,
                        $selfMember->uuid,
                        $spouseUuids,
                    );

                    return $payload;
                })
                ->values()
                ->all(),
            'siblings' => $this->siblingsOf($selfMember->uuid, $user)
                ->map(fn (FamilyMember $sibling) => $this->formatRelative($sibling))
                ->values()
                ->all(),
            'marriage_date' => $primarySpouse
                ? $this->marriageDateBetween($selfMember, $primarySpouse)?->format('Y-m-d')
                : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function syncMatchingInfo(FamilyMember $selfMember, array $data, User $user): void
    {
        $this->syncParentStub(
            $selfMember,
            $selfMember,
            'father',
            $data['father'] ?? null,
            'male',
            $user,
        );
        $this->syncParentStub(
            $selfMember,
            $selfMember,
            'mother',
            $data['mother'] ?? null,
            'female',
            $user,
        );

        $this->syncSpouses($selfMember, $this->normalizeSpousesPayload($data), $user);

        $this->syncChildren($selfMember, $data['children'] ?? [], $user->id);

        if (array_key_exists('siblings', $data)) {
            $this->syncSiblings($selfMember, $data['siblings'] ?? [], $user->id);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowedRelationTypes
     */
    public function addMember(
        FamilyMember $selfMember,
        string $relationType,
        array $data,
        int $userId,
        array $allowedRelationTypes,
    ): FamilyMember {
        if (! in_array($relationType, $allowedRelationTypes, true)) {
            throw new \InvalidArgumentException("Unsupported relation type: {$relationType}");
        }

        if (! $this->relativeHasInfo($data)) {
            throw new \InvalidArgumentException('At least a name or date of birth is required.');
        }

        return match ($relationType) {
            'father', 'mother' => $this->addParent($selfMember, $relationType, $data, $userId),
            'spouse' => $this->addSpouse($selfMember, $data, $userId),
            'child' => $this->addChild($selfMember, $data, $userId),
            'sibling' => $this->addSibling($selfMember, $data, $userId),
            'spouse_father', 'spouse_mother' => $this->addSpouseInLaw($selfMember, $relationType, $data, $userId),
            default => throw new \InvalidArgumentException("Unsupported relation type: {$relationType}"),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function addParent(
        FamilyMember $selfMember,
        string $slot,
        array $data,
        int $userId,
    ): FamilyMember {
        $defaultGender = $slot === 'father' ? 'male' : 'female';
        $parent = $this->upsertMatchingRelative($selfMember, $slot, $data, $defaultGender, userId: $userId);
        $activeSelf = $this->activeSelfForUser($userId, $selfMember);
        $this->ensureParentEdge($parent, $activeSelf, $userId);

        return $parent;
    }

    /** @param  array<string, mixed>  $data */
    private function addSpouse(FamilyMember $selfMember, array $data, int $userId): FamilyMember
    {
        $spouse = $this->upsertMatchingRelative(
            $selfMember,
            'spouse',
            $data,
            $this->defaultGenderForSlot('spouse'),
            userId: $userId,
        );
        $activeSelf = $this->activeSelfForUser($userId, $selfMember);
        $this->ensureSpouseEdge($activeSelf, $spouse, $userId);
        $this->syncMarriageDate($activeSelf, $spouse, $data['marriage_date'] ?? null);

        return $spouse;
    }

    /** @param  array<string, mixed>  $data */
    private function addChild(FamilyMember $selfMember, array $data, int $userId): FamilyMember
    {
        $child = $this->upsertChild($selfMember, $data);
        $activeSelf = $this->activeSelfForUser($userId, $selfMember);
        $this->ensureParentEdge($activeSelf, $child, $userId);
        $this->syncChildOtherParent($activeSelf, $child, $data, $userId);

        return $child;
    }

    /** @param  array<string, mixed>  $data */
    private function addSibling(FamilyMember $selfMember, array $data, int $userId): FamilyMember
    {
        $parents = $this->parentsOf($selfMember->uuid);
        if ($parents === []) {
            throw new \InvalidArgumentException('Add at least one parent before adding a sibling.');
        }

        $sibling = $this->upsertSibling($selfMember, $data);

        foreach ($parents as $parent) {
            $this->ensureParentEdge($parent, $sibling, $userId);
        }

        return $sibling;
    }

    /** @param  array<string, mixed>  $data */
    private function addSpouseInLaw(
        FamilyMember $selfMember,
        string $slot,
        array $data,
        int $userId,
    ): FamilyMember {
        $activeSelf = $this->activeSelfForUser($userId, $selfMember);
        $spouse = $this->findSpouseMember($activeSelf);
        if (! $spouse) {
            throw new \InvalidArgumentException('Add a spouse before adding in-laws.');
        }

        $defaultGender = $slot === 'spouse_father' ? 'male' : 'female';
        $inLaw = $this->upsertMatchingRelative($selfMember, $slot, $data, $defaultGender, userId: $userId);
        $spouse = $this->findSpouseMember($this->activeSelfForUser($userId, $selfMember));
        if ($spouse) {
            $this->ensureParentEdge($inLaw, $spouse, $userId);
        }

        return $inLaw;
    }

    private function activeSelfForUser(int $userId, FamilyMember $fallback): FamilyMember
    {
        return FamilyMember::query()->where('user_id', $userId)->first() ?? $fallback;
    }

    public function findSpouseMember(FamilyMember $selfMember): ?FamilyMember
    {
        return $this->findSpouseMembers($selfMember)->first();
    }

    /** @return Collection<int, FamilyMember> */
    public function findSpouseMembers(FamilyMember $selfMember): Collection
    {
        $uuids = $this->spouseUuidsFor($selfMember->uuid);
        if ($uuids === []) {
            return collect();
        }

        $members = FamilyMember::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        return collect($uuids)
            ->map(fn (string $uuid) => $members->get($uuid))
            ->filter()
            ->values();
    }

    public function spouseUuidFor(string $memberUuid): ?string
    {
        return $this->spouseUuidsFor($memberUuid)[0] ?? null;
    }

    /** @return list<string> */
    public function spouseUuidsFor(string $memberUuid): array
    {
        $edges = RelationshipEdge::query()
            ->where(function ($query) use ($memberUuid) {
                $query->where('from_member_uuid', $memberUuid)
                    ->orWhere('to_member_uuid', $memberUuid);
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->orderBy('marriage_date')
            ->orderBy('created_at')
            ->get();

        $uuids = [];
        foreach ($edges as $edge) {
            $other = $edge->from_member_uuid === $memberUuid
                ? $edge->to_member_uuid
                : $edge->from_member_uuid;
            if (! in_array($other, $uuids, true)) {
                $uuids[] = $other;
            }
        }

        return $uuids;
    }

    /** @return array<string, FamilyMember> */
    public function parentsOf(string $childUuid, ?User $user = null): array
    {
        $parents = [];
        $spouseUuids = $this->spouseUuidsFor($childUuid);

        if ($user) {
            foreach (['father', 'mother'] as $slot) {
                $declared = UserDeclaredRelative::query()
                    ->where('user_id', $user->id)
                    ->where('relation_type', $slot)
                    ->where('relation_index', 0)
                    ->first();

                if (! $declared?->member_uuid) {
                    continue;
                }

                $member = FamilyMember::query()->find($declared->member_uuid);
                if (
                    $member
                    && $this->isParentOf($member->uuid, $childUuid)
                    && ! in_array($member->uuid, $spouseUuids, true)
                ) {
                    $parents[$slot] = $member;
                }
            }
        }

        $parentEdges = RelationshipEdge::query()
            ->where('to_member_uuid', $childUuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($parentEdges as $edge) {
            $fromMember = FamilyMember::query()->find($edge->from_member_uuid);
            if (! $fromMember) {
                continue;
            }

            if (in_array($fromMember->uuid, $spouseUuids, true)) {
                continue;
            }

            if ($this->memberAlreadySlotted($fromMember, $parents)) {
                continue;
            }

            $gender = $fromMember->gender;
            if ($gender === 'female' && ! isset($parents['mother'])) {
                $parents['mother'] = $fromMember;
            } elseif ($gender === 'male' && ! isset($parents['father'])) {
                $parents['father'] = $fromMember;
            } elseif (! isset($parents['mother'])) {
                $parents['mother'] = $fromMember;
            } elseif (! isset($parents['father'])) {
                $parents['father'] = $fromMember;
            }
        }

        return $parents;
    }

    /** @param  array<string, mixed>  $data */
    private function upsertMatchingRelative(
        FamilyMember $selfMember,
        string $slot,
        array $data,
        string $defaultGender,
        ?User $user = null,
        ?int $userId = null,
    ): FamilyMember {
        $actorUserId = $userId ?? $user?->id;

        if (! empty($data['uuid'])) {
            $byUuid = FamilyMember::query()->find($data['uuid']);
            if ($byUuid && $byUuid->family_uuid !== $selfMember->family_uuid && $actorUserId) {
                app(CrossFamilyJoinService::class)->joinThroughRelative(
                    $selfMember,
                    $byUuid,
                    $slot,
                    $actorUserId,
                );

                return $byUuid->fresh();
            }

            if (
                $byUuid
                && $byUuid->family_uuid === $selfMember->family_uuid
                && ! $this->isInvalidParentCandidate($selfMember, $slot, $byUuid)
            ) {
                $byUuid->update($this->matchingAttributes($selfMember, $data, $defaultGender, $byUuid));

                return $byUuid->fresh();
            }
        }

        $existing = $this->findMatchingRelative($selfMember, $slot, $user);
        $attributes = $this->matchingAttributes($selfMember, $data, $defaultGender, $existing);

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        $this->guardAgainstDuplicateCreate($selfMember, $data, $slot);

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function matchingAttributes(
        FamilyMember $selfMember,
        array $data,
        string $defaultGender,
        ?FamilyMember $existing = null,
    ): array {
        return [
            'family_uuid' => $selfMember->family_uuid,
            'first_name' => $this->stringField($data, 'first_name', $existing),
            'last_name' => $this->stringField($data, 'last_name', $existing),
            'date_of_birth' => $data['date_of_birth'] ?? $existing?->date_of_birth,
            'gender' => $data['gender'] ?? $defaultGender,
            ...$this->vitalityAttributes($data, $existing),
            'user_id' => $existing?->user_id,
            'match_confidence' => $existing?->match_confidence ?? 0,
        ];
    }

    private function findMatchingRelative(
        FamilyMember $selfMember,
        string $slot,
        ?User $user = null,
    ): ?FamilyMember {
        if ($user) {
            $declaredMember = $this->declaredMemberForSlot($user, $slot, $selfMember->family_uuid);
            if ($declaredMember && ! $this->isInvalidParentCandidate($selfMember, $slot, $declaredMember)) {
                return $declaredMember;
            }
        }

        if (in_array($slot, ['father', 'mother'], true)) {
            return $this->parentsOf($selfMember->uuid, $user)[$slot] ?? null;
        }

        if ($slot === 'spouse') {
            return $this->findSpouseMember($selfMember);
        }

        $spouse = $this->findSpouseMember($selfMember);
        if (! $spouse) {
            return null;
        }

        $parents = $this->parentsOf($spouse->uuid, $user);

        return match ($slot) {
            'spouse_father' => $parents['father'] ?? null,
            'spouse_mother' => $parents['mother'] ?? null,
            default => null,
        };
    }

    public function ensureSpouseEdge(
        FamilyMember $selfMember,
        FamilyMember $spouseMember,
        int $userId,
    ): void {
        $existing = RelationshipEdge::query()
            ->where(function ($query) use ($selfMember, $spouseMember) {
                $query->where(function ($inner) use ($selfMember, $spouseMember) {
                    $inner->where('from_member_uuid', $selfMember->uuid)
                        ->where('to_member_uuid', $spouseMember->uuid);
                })->orWhere(function ($inner) use ($selfMember, $spouseMember) {
                    $inner->where('from_member_uuid', $spouseMember->uuid)
                        ->where('to_member_uuid', $selfMember->uuid);
                });
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->exists();

        if ($existing) {
            return;
        }

        $edgeType = RelationshipEdgeType::query()->where('code', 'spouse_of')->firstOrFail();

        RelationshipEdge::create([
            'uuid' => (string) Str::uuid(),
            'from_member_uuid' => $selfMember->uuid,
            'to_member_uuid' => $spouseMember->uuid,
            'edge_type_id' => $edgeType->id,
            'created_by_user_id' => $userId,
        ]);
    }

    public function marriageDateFor(FamilyMember $member): ?\Carbon\Carbon
    {
        $spouse = $this->findSpouseMember($member);

        return $spouse ? $this->marriageDateBetween($member, $spouse) : null;
    }

    public function marriageDateBetween(FamilyMember $left, FamilyMember $right): ?\Carbon\Carbon
    {
        $edge = RelationshipEdge::query()
            ->where(function ($query) use ($left, $right) {
                $query->where(function ($inner) use ($left, $right) {
                    $inner->where('from_member_uuid', $left->uuid)
                        ->where('to_member_uuid', $right->uuid);
                })->orWhere(function ($inner) use ($left, $right) {
                    $inner->where('from_member_uuid', $right->uuid)
                        ->where('to_member_uuid', $left->uuid);
                });
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->first();

        return $edge?->marriage_date;
    }

    public function syncMarriageDate(
        FamilyMember $left,
        FamilyMember $right,
        ?string $marriageDate,
    ): void {
        $edge = RelationshipEdge::query()
            ->where(function ($query) use ($left, $right) {
                $query->where(function ($inner) use ($left, $right) {
                    $inner->where('from_member_uuid', $left->uuid)
                        ->where('to_member_uuid', $right->uuid);
                })->orWhere(function ($inner) use ($left, $right) {
                    $inner->where('from_member_uuid', $right->uuid)
                        ->where('to_member_uuid', $left->uuid);
                });
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->first();

        if (! $edge) {
            return;
        }

        $edge->marriage_date = $marriageDate ?: null;
        $edge->save();
    }

    /** @param  array<string, mixed>|null  $data */
    private function syncDirectParent(
        FamilyMember $selfMember,
        FamilyMember $childMember,
        ?array $data,
        string $defaultGender,
        User $user,
    ): void {
        $slot = $defaultGender === 'male' ? 'father' : 'mother';
        $existingParents = $this->parentsOf($childMember->uuid, $user);
        $existing = $existingParents[$slot] ?? null;

        if (! $this->relativeHasInfo($data)) {
            if ($existing && $existing->user_id === null) {
                $this->deleteMemberGraph($existing);
            }

            return;
        }

        if (! empty($data['uuid'])) {
            $byUuid = FamilyMember::query()->find($data['uuid']);
            if ($byUuid && $byUuid->family_uuid === $selfMember->family_uuid) {
                $byUuid->update($this->matchingAttributes($selfMember, $data, $defaultGender, $byUuid));
                $this->ensureParentEdge($byUuid->fresh(), $childMember, $user->id);

                return;
            }
        }

        $attributes = $this->matchingAttributes($selfMember, $data ?? [], $defaultGender, $existing);
        if ($existing) {
            $existing->update($attributes);
            $parent = $existing->fresh();
        } else {
            $this->guardAgainstDuplicateCreate($selfMember, $data ?? [], $slot);
            $parent = FamilyMember::create([
                'uuid' => (string) Str::uuid(),
                ...$attributes,
            ]);
        }

        $this->ensureSlotGender($parent, $slot);
        $this->ensureParentEdge($parent, $childMember, $user->id);
    }

    /** @param  array<string, mixed>|null  $data */
    private function syncParentStub(
        FamilyMember $selfMember,
        FamilyMember $childMember,
        string $slot,
        ?array $data,
        string $defaultGender,
        User $user,
    ): void {
        if (! $this->relativeHasInfo($data)) {
            $this->removeParentStub($childMember, $slot, $user);

            return;
        }

        $parent = $this->upsertMatchingRelative(
            $selfMember,
            $slot,
            $data ?? [],
            $defaultGender,
            $user,
        );

        $this->ensureSlotGender($parent, $slot);
        if (in_array($slot, ['father', 'mother'], true)) {
            $this->removeSpouseParentEdge($childMember);
        }
        $this->ensureParentEdge($parent, $childMember, $user->id);
    }

    public function ensureParentEdge(
        FamilyMember $parent,
        FamilyMember $child,
        int $userId,
    ): void {
        $exists = RelationshipEdge::query()
            ->where('from_member_uuid', $parent->uuid)
            ->where('to_member_uuid', $child->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->exists();

        if ($exists) {
            return;
        }

        $edgeType = RelationshipEdgeType::query()->where('code', 'parent_of')->firstOrFail();

        RelationshipEdge::create([
            'uuid' => (string) Str::uuid(),
            'from_member_uuid' => $parent->uuid,
            'to_member_uuid' => $child->uuid,
            'edge_type_id' => $edgeType->id,
            'created_by_user_id' => $userId,
        ]);
    }

    private function removeParentStub(FamilyMember $childMember, string $slot, ?User $user = null): void
    {
        $parents = $this->parentsOf($childMember->uuid, $user);
        $target = match ($slot) {
            'father' => $parents['father'] ?? null,
            'mother' => $parents['mother'] ?? null,
            'spouse_father' => $parents['father'] ?? null,
            'spouse_mother' => $parents['mother'] ?? null,
            default => null,
        };

        if (! $target || $target->user_id !== null) {
            return;
        }

        $this->deleteMemberGraph($target);
    }

    private function removeMatchingRelative(FamilyMember $anchorMember, string $slot): void
    {
        $target = $slot === 'spouse'
            ? $this->findSpouseMember($anchorMember)
            : $this->findMatchingRelative($anchorMember, $slot);

        if (! $target) {
            return;
        }

        if ($target->user_id !== null) {
            return;
        }

        if ($slot === 'spouse') {
            $parents = $this->parentsOf($target->uuid);
            foreach ($parents as $parent) {
                if ($parent->user_id === null) {
                    $this->deleteMemberGraph($parent);
                }
            }
        }

        $this->deleteMemberGraph($target);
    }

    public function deleteMemberGraph(FamilyMember $member): void
    {
        RelationshipEdge::query()
            ->where('from_member_uuid', $member->uuid)
            ->orWhere('to_member_uuid', $member->uuid)
            ->delete();

        $member->forceDelete();
    }

    public function mergeMemberInto(FamilyMember $from, FamilyMember $to): void
    {
        if ($from->user_id !== null && $to->user_id === null) {
            $to->update(['user_id' => $from->user_id]);
            $from->update(['user_id' => null]);
        }

        RelationshipEdge::query()
            ->where('from_member_uuid', $from->uuid)
            ->update(['from_member_uuid' => $to->uuid]);

        RelationshipEdge::query()
            ->where('to_member_uuid', $from->uuid)
            ->update(['to_member_uuid' => $to->uuid]);

        RelationshipEdge::query()
            ->where('from_member_uuid', $to->uuid)
            ->where('to_member_uuid', $to->uuid)
            ->delete();
    }

    /** @param  list<array<string, mixed>>  $children */
    private function syncChildren(FamilyMember $selfMember, array $children, int $userId): void
    {
        $existing = $this->childrenOf($selfMember->uuid);
        $keptUuids = [];
        $spouseUuids = $this->spouseUuidsFor($selfMember->uuid);

        foreach ($children as $childData) {
            if (! $this->relativeHasInfo($childData)) {
                continue;
            }

            if (count($spouseUuids) > 1 && empty($childData['other_parent_uuid'])) {
                throw ValidationException::withMessages([
                    'children' => ['Each child must select a mother/other parent when you have multiple partners.'],
                ]);
            }

            $child = $this->upsertChild($selfMember, $childData);
            $this->ensureParentEdge($selfMember, $child, $userId);
            $this->syncChildOtherParent($selfMember, $child, $childData, $userId);

            $keptUuids[] = $child->uuid;
        }

        foreach ($existing as $child) {
            if ($child->user_id !== null || in_array($child->uuid, $keptUuids, true)) {
                continue;
            }

            $this->deleteMemberGraph($child);
        }
    }

    /**
     * Link child to the selected co-parent (or the sole spouse when unspecified).
     * Never attaches every co-wife as a mother.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncChildOtherParent(
        FamilyMember $selfMember,
        FamilyMember $child,
        array $data,
        int $userId,
    ): void {
        $spouseUuids = $this->spouseUuidsFor($selfMember->uuid);
        $requested = $data['other_parent_uuid'] ?? null;

        if ($requested !== null && $requested !== '') {
            if (! in_array($requested, $spouseUuids, true)) {
                throw ValidationException::withMessages([
                    'children' => ['other_parent_uuid must be one of your partners.'],
                ]);
            }
            $otherParentUuid = $requested;
        } elseif (count($spouseUuids) === 1) {
            $otherParentUuid = $spouseUuids[0];
        } else {
            $otherParentUuid = null;
        }

        foreach ($spouseUuids as $spouseUuid) {
            if ($otherParentUuid !== null && $spouseUuid === $otherParentUuid) {
                continue;
            }

            RelationshipEdge::query()
                ->where('from_member_uuid', $spouseUuid)
                ->where('to_member_uuid', $child->uuid)
                ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                    'parent_of', 'adoptive_parent_of', 'step_parent_of',
                ]))
                ->delete();
        }

        if ($otherParentUuid === null) {
            return;
        }

        $otherParent = FamilyMember::query()->find($otherParentUuid);
        if ($otherParent) {
            $this->ensureParentEdge($otherParent, $child, $userId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeSpousesPayload(array $data): array
    {
        if (array_key_exists('spouses', $data) && is_array($data['spouses'])) {
            return array_values(array_filter(
                $data['spouses'],
                fn ($row) => is_array($row) && $this->relativeHasInfo($row),
            ));
        }

        $spouseData = $data['spouse'] ?? null;
        if (! $this->relativeHasInfo($spouseData)) {
            return [];
        }

        return [[
            ...$spouseData,
            'marriage_date' => $data['marriage_date'] ?? ($spouseData['marriage_date'] ?? null),
            'father' => $data['spouse_father'] ?? ($spouseData['father'] ?? null),
            'mother' => $data['spouse_mother'] ?? ($spouseData['mother'] ?? null),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $spouses
     */
    private function syncSpouses(FamilyMember $selfMember, array $spouses, User $user): void
    {
        $existing = $this->findSpouseMembers($selfMember);
        $keptUuids = [];

        foreach ($spouses as $index => $spouseData) {
            $spouseMember = $this->upsertSpouseAtIndex(
                $selfMember,
                $spouseData,
                $index,
                $user,
            );
            $this->ensureSpouseEdge($selfMember, $spouseMember, $user->id);
            $this->syncMarriageDate(
                $selfMember,
                $spouseMember,
                $spouseData['marriage_date'] ?? null,
            );

            $this->syncDirectParent(
                $selfMember,
                $spouseMember,
                $spouseData['father'] ?? null,
                'male',
                $user,
            );
            $this->syncDirectParent(
                $selfMember,
                $spouseMember,
                $spouseData['mother'] ?? null,
                'female',
                $user,
            );

            $keptUuids[] = $spouseMember->uuid;
        }

        foreach ($existing as $spouse) {
            if (in_array($spouse->uuid, $keptUuids, true)) {
                continue;
            }

            $this->detachSpouse($selfMember, $spouse);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function upsertSpouseAtIndex(
        FamilyMember $selfMember,
        array $data,
        int $index,
        User $user,
    ): FamilyMember {
        if (! empty($data['uuid'])) {
            $byUuid = FamilyMember::query()->find($data['uuid']);
            if ($byUuid && $byUuid->family_uuid !== $selfMember->family_uuid) {
                app(CrossFamilyJoinService::class)->joinThroughRelative(
                    $selfMember,
                    $byUuid,
                    'spouse',
                    $user->id,
                );

                return $byUuid->fresh();
            }

            if ($byUuid && $byUuid->family_uuid === $selfMember->family_uuid) {
                $byUuid->update($this->matchingAttributes(
                    $selfMember,
                    $data,
                    $this->defaultGenderForSlot('spouse'),
                    $byUuid,
                ));

                return $byUuid->fresh();
            }
        }

        $existingSpouses = $this->findSpouseMembers($selfMember);
        $existing = $existingSpouses->get($index);
        $attributes = $this->matchingAttributes(
            $selfMember,
            $data,
            $this->defaultGenderForSlot('spouse'),
            $existing,
        );

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        $this->guardAgainstDuplicateCreate($selfMember, $data, 'spouse');

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            ...$attributes,
        ]);
    }

    private function detachSpouse(FamilyMember $selfMember, FamilyMember $spouse): void
    {
        RelationshipEdge::query()
            ->where(function ($query) use ($selfMember, $spouse) {
                $query->where(function ($inner) use ($selfMember, $spouse) {
                    $inner->where('from_member_uuid', $selfMember->uuid)
                        ->where('to_member_uuid', $spouse->uuid);
                })->orWhere(function ($inner) use ($selfMember, $spouse) {
                    $inner->where('from_member_uuid', $spouse->uuid)
                        ->where('to_member_uuid', $selfMember->uuid);
                });
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->delete();

        if ($spouse->user_id !== null) {
            return;
        }

        $hasOtherEdges = RelationshipEdge::query()
            ->where(function ($query) use ($spouse) {
                $query->where('from_member_uuid', $spouse->uuid)
                    ->orWhere('to_member_uuid', $spouse->uuid);
            })
            ->exists();

        if (! $hasOtherEdges) {
            $parents = $this->parentsOf($spouse->uuid);
            foreach ($parents as $parent) {
                if ($parent->user_id === null) {
                    $this->deleteMemberGraph($parent);
                }
            }
            $this->deleteMemberGraph($spouse);
        }
    }

    /**
     * @param  list<string>  $candidateParentUuids
     */
    private function otherParentUuidAmong(
        string $childUuid,
        string $selfUuid,
        array $candidateParentUuids,
    ): ?string {
        $parentEdges = RelationshipEdge::query()
            ->where('to_member_uuid', $childUuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($parentEdges as $edge) {
            if (
                $edge->from_member_uuid !== $selfUuid
                && in_array($edge->from_member_uuid, $candidateParentUuids, true)
            ) {
                return $edge->from_member_uuid;
            }
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $siblings */
    private function syncSiblings(FamilyMember $selfMember, array $siblings, int $userId): void
    {
        $existing = $this->siblingsOf($selfMember->uuid);
        $parents = $this->parentsOf($selfMember->uuid);
        $keptUuids = [];

        foreach ($siblings as $siblingData) {
            if (! $this->relativeHasInfo($siblingData)) {
                continue;
            }

            $sibling = $this->upsertSibling($selfMember, $siblingData);

            foreach ($parents as $parent) {
                $this->ensureParentEdge($parent, $sibling, $userId);
            }

            $keptUuids[] = $sibling->uuid;
        }

        foreach ($existing as $sibling) {
            if ($sibling->user_id !== null || in_array($sibling->uuid, $keptUuids, true)) {
                continue;
            }

            $this->deleteMemberGraph($sibling);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function upsertSibling(FamilyMember $selfMember, array $data): FamilyMember
    {
        if (! empty($data['uuid'])) {
            $existing = FamilyMember::query()->find($data['uuid']);
            if ($existing && $existing->family_uuid === $selfMember->family_uuid) {
                $this->assertNoFullNameCollision(
                    $this->siblingsOf($selfMember->uuid),
                    $data,
                    'sibling',
                    $existing->uuid,
                );

                $existing->update([
                    'first_name' => $data['first_name'] ?? $existing->first_name,
                    'last_name' => $data['last_name'] ?? $existing->last_name,
                    'date_of_birth' => $data['date_of_birth'] ?? $existing->date_of_birth,
                    'gender' => $data['gender'] ?? $existing->gender,
                    ...$this->vitalityAttributes($data, $existing),
                ]);

                return $existing->fresh();
            }
        }

        $this->assertNoFullNameCollision(
            $this->siblingsOf($selfMember->uuid),
            $data,
            'sibling',
        );

        $this->guardAgainstDuplicateCreate($selfMember, $data, 'sibling');

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $selfMember->family_uuid,
            'first_name' => $data['first_name'] ?? 'Unknown',
            'last_name' => $data['last_name'] ?? 'Unknown',
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? 'unknown',
            ...$this->vitalityAttributes($data),
            'user_id' => null,
            'match_confidence' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function upsertChild(FamilyMember $selfMember, array $data): FamilyMember
    {
        if (! empty($data['uuid'])) {
            $existing = FamilyMember::query()->find($data['uuid']);
            if ($existing && $existing->family_uuid === $selfMember->family_uuid) {
                $this->assertNoFullNameCollision(
                    $this->childrenOf($selfMember->uuid),
                    $data,
                    'child',
                    $existing->uuid,
                );

                $existing->update([
                    'first_name' => $data['first_name'] ?? $existing->first_name,
                    'last_name' => $data['last_name'] ?? $existing->last_name,
                    'date_of_birth' => $data['date_of_birth'] ?? $existing->date_of_birth,
                    'gender' => $data['gender'] ?? $existing->gender,
                    ...$this->vitalityAttributes($data, $existing),
                ]);

                return $existing->fresh();
            }
        }

        $this->assertNoFullNameCollision(
            $this->childrenOf($selfMember->uuid),
            $data,
            'child',
        );

        $this->guardAgainstDuplicateCreate($selfMember, $data, 'child');

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $selfMember->family_uuid,
            'first_name' => $data['first_name'] ?? 'Unknown',
            'last_name' => $data['last_name'] ?? 'Unknown',
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'gender' => $data['gender'] ?? 'unknown',
            ...$this->vitalityAttributes($data),
            'user_id' => null,
            'match_confidence' => 0,
        ]);
    }

    /**
     * Never auto-replace siblings/children that share the same full name.
     *
     * @param  Collection<int, FamilyMember>|iterable<FamilyMember>  $relatives
     * @param  array<string, mixed>  $data
     */
    private function assertNoFullNameCollision(
        iterable $relatives,
        array $data,
        string $relationLabel,
        ?string $ignoreUuid = null,
    ): void {
        $match = collect($relatives)->first(
            function (FamilyMember $candidate) use ($data, $ignoreUuid) {
                if ($ignoreUuid !== null && $candidate->uuid === $ignoreUuid) {
                    return false;
                }

                return $this->matcher->isSameNamedPerson($data, $candidate);
            }
        );

        if (! $match) {
            return;
        }

        $fullName = trim($match->first_name.' '.$match->last_name);
        $label = $relationLabel === 'child' ? 'child' : 'sibling';

        throw ValidationException::withMessages([
            'first_name' => [
                "{$fullName} is already added as your {$label}. Use a different full name, or edit the existing person.",
            ],
        ]);
    }

    /** @return Collection<int, FamilyMember> */
    public function childrenOf(string $parentUuid): Collection
    {
        $childUuids = RelationshipEdge::query()
            ->where('from_member_uuid', $parentUuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->pluck('to_member_uuid');

        return FamilyMember::query()->whereIn('uuid', $childUuids)->get();
    }

    /** @return Collection<int, FamilyMember> */
    public function siblingsOf(string $memberUuid, ?User $user = null): Collection
    {
        $graphSiblings = $this->graphSiblingsOf($memberUuid);

        if (! $user) {
            return $graphSiblings;
        }

        $declared = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', 'sibling')
            ->orderBy('relation_index')
            ->get();

        $declaredMembers = $declared
            ->map(function (UserDeclaredRelative $relative) use ($memberUuid) {
                if ($relative->member_uuid) {
                    $member = FamilyMember::query()->find($relative->member_uuid);

                    return $member && $member->uuid !== $memberUuid ? $member : null;
                }

                if (! $relative->first_name && ! $relative->last_name && ! $relative->date_of_birth) {
                    return null;
                }

                return new FamilyMember([
                    'uuid' => $relative->member_uuid ?? $relative->uuid,
                    'first_name' => $relative->first_name ?? 'Unknown',
                    'last_name' => $relative->last_name ?? 'Unknown',
                    'date_of_birth' => $relative->date_of_birth,
                    'gender' => $relative->gender ?? 'unknown',
                    'is_living' => $relative->is_living ?? true,
                    'date_of_death' => $relative->date_of_death,
                ]);
            })
            ->filter()
            ->values();

        return $graphSiblings
            ->merge($declaredMembers)
            ->unique('uuid')
            ->reject(fn (FamilyMember $member) => $member->uuid === $memberUuid)
            ->values();
    }

    /** @return Collection<int, FamilyMember> */
    private function graphSiblingsOf(string $memberUuid): Collection
    {
        $parents = $this->parentsOf($memberUuid);
        if ($parents === []) {
            return collect();
        }

        $siblingUuids = collect();
        foreach ($parents as $parent) {
            $siblingUuids = $siblingUuids->merge(
                $this->childrenOf($parent->uuid)->pluck('uuid')
            );
        }

        return FamilyMember::query()
            ->whereIn('uuid', $siblingUuids->unique()->all())
            ->where('uuid', '!=', $memberUuid)
            ->get()
            ->values();
    }

    private function defaultGenderForSlot(string $slot): string
    {
        return match ($slot) {
            'father', 'spouse_father' => 'male',
            'mother', 'spouse_mother' => 'female',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{is_living: bool, date_of_death: ?string}
     */
    private function vitalityAttributes(array $data, ?FamilyMember $existing = null): array
    {
        $isLiving = array_key_exists('is_living', $data)
            ? (bool) $data['is_living']
            : ($existing?->is_living ?? true);

        return [
            'is_living' => $isLiving,
            'date_of_death' => $isLiving
                ? null
                : ($data['date_of_death'] ?? $existing?->date_of_death?->format('Y-m-d')),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function stringField(array $data, string $key, ?FamilyMember $existing = null): string
    {
        $value = $data[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $existing?->$key ?? 'Unknown';
    }

    private function isParentOf(string $parentUuid, string $childUuid): bool
    {
        return RelationshipEdge::query()
            ->where('from_member_uuid', $parentUuid)
            ->where('to_member_uuid', $childUuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->exists();
    }

    /** @param  array<string, FamilyMember>  $parents */
    private function memberAlreadySlotted(FamilyMember $member, array $parents): bool
    {
        foreach ($parents as $slotted) {
            if ($slotted->uuid === $member->uuid) {
                return true;
            }
        }

        return false;
    }

    private function declaredMemberForSlot(User $user, string $slot, string $familyUuid): ?FamilyMember
    {
        $relationType = match ($slot) {
            'father', 'mother', 'spouse', 'spouse_father', 'spouse_mother' => $slot,
            default => null,
        };

        if ($relationType === null) {
            return null;
        }

        $declared = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', $relationType)
            ->where('relation_index', 0)
            ->first();

        if (! $declared?->member_uuid) {
            return null;
        }

        $member = FamilyMember::query()->find($declared->member_uuid);

        return ($member && $member->family_uuid === $familyUuid) ? $member : null;
    }

    private function ensureSlotGender(FamilyMember $member, string $slot): void
    {
        $expectedGender = match ($slot) {
            'father', 'spouse_father' => 'male',
            'mother', 'spouse_mother' => 'female',
            default => null,
        };

        if ($expectedGender === null || $member->gender === $expectedGender) {
            return;
        }

        $member->update(['gender' => $expectedGender]);
    }

    private function isInvalidParentCandidate(
        FamilyMember $selfMember,
        string $slot,
        FamilyMember $candidate,
    ): bool {
        if (! in_array($slot, ['father', 'mother'], true)) {
            return false;
        }

        $spouseUuids = $this->spouseUuidsFor($selfMember->uuid);

        return $spouseUuids !== [] && in_array($candidate->uuid, $spouseUuids, true);
    }

    /** @param  array<string, mixed>  $data */
    private function guardAgainstDuplicateCreate(
        FamilyMember $selfMember,
        array $data,
        ?string $relationType = null,
    ): void {
        if (! empty($data['uuid']) || ! empty($data['confirm_create_new'])) {
            return;
        }

        $matches = $this->candidates->findCandidates(
            $selfMember,
            $data,
            null,
            $relationType,
        );

        if ($matches !== []) {
            throw new DuplicateMemberCandidateException($matches);
        }
    }

    private function removeSpouseParentEdge(FamilyMember $childMember): void
    {
        $spouseUuid = $this->spouseUuidFor($childMember->uuid);
        if (! $spouseUuid) {
            return;
        }

        RelationshipEdge::query()
            ->where('from_member_uuid', $spouseUuid)
            ->where('to_member_uuid', $childMember->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->delete();
    }
}
