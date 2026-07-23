<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\OnboardingSession;
use App\Models\User;
use App\Modules\FamilyTree\Events\FamilyMemberJoined;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyJoinService
{
    public function __construct(
        private readonly MemberCodeService $memberCodes,
        private readonly FamilyMatcherService $matcher,
        private readonly JoinRelationOptionsResolver $relationOptions,
        private readonly JoinRelationVerificationService $joinVerification,
        private readonly FamilyJoinDisambiguationService $disambiguation,
        private readonly DeclaredRelativeService $declaredRelatives,
        private readonly JoinParentGraphMaterializer $parentMaterializer,
    ) {}

    private function graph(): FamilyMemberGraphService
    {
        return app(FamilyMemberGraphService::class);
    }

    private function wiring(): JoinRelationWiringService
    {
        return app(JoinRelationWiringService::class);
    }

    /** @return array<string, mixed> */
    public function ensureSoloFamily(User $user): array
    {
        $existing = FamilyMember::query()->where('user_id', $user->id)->first();
        if ($existing) {
            $session = $this->ensureConfirmedSession($user, $existing->family_uuid);

            return $this->statusPayload($user, $existing, $session, includePeople: true);
        }

        return DB::transaction(function () use ($user) {
            $family = Family::create([
                'uuid' => (string) Str::uuid(),
                'name' => $this->displayFamilyLabel($user),
                'slug' => Str::slug(($user->display_name ?: 'family').'-'.Str::random(6)),
                'member_count' => 1,
            ]);

            [$first, $last] = $this->splitDisplayName($user->display_name);

            $member = FamilyMember::create([
                'uuid' => (string) Str::uuid(),
                'family_uuid' => $family->uuid,
                'user_id' => $user->id,
                'first_name' => $first,
                'last_name' => $last,
                'gender' => 'unknown',
                'is_living' => true,
                'match_confidence' => 1,
            ]);

            $session = OnboardingSession::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => 'confirmed',
                'matched_family_uuid' => $family->uuid,
                'top_match_score' => 1,
                'match_candidates' => [],
                'completed_at' => now(),
            ]);

            return $this->statusPayload($user, $member, $session, includePeople: true);
        });
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $member = FamilyMember::query()
            ->select(['uuid', 'family_uuid', 'user_id', 'first_name', 'last_name', 'member_code'])
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            return [
                'has_family' => false,
                'needs_join_choice' => true,
                'can_connect_to_family' => true,
                'solo_tree' => false,
                'session_status' => null,
                'member' => null,
                'family' => null,
                ...$this->parentContextPayload($user),
            ];
        }

        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first(['uuid', 'status', 'matched_family_uuid']);

        return $this->statusPayload($user, $member, $session, includePeople: false);
    }

    /** @return array<string, mixed> */
    public function lookupByMemberCode(User $user, string $rawCode, ?string $searchSlot = null): array
    {
        $code = $this->memberCodes->normalize($rawCode);
        if ($code === '') {
            throw ValidationException::withMessages([
                'member_code' => ['Enter a valid member code.'],
            ]);
        }

        $target = FamilyMember::query()
            ->select(['uuid', 'family_uuid', 'user_id', 'first_name', 'last_name', 'member_code', 'gender', 'date_of_birth', 'is_living'])
            ->where('member_code', $code)
            ->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'member_code' => ['No person found with that code.'],
            ]);
        }

        $viewer = FamilyMember::query()->where('user_id', $user->id)->first();
        if ($viewer && $viewer->uuid === $target->uuid) {
            throw ValidationException::withMessages([
                'member_code' => ['That is your own member code.'],
            ]);
        }

        $options = $this->relationOptions->enrichOptions(
            $this->relationOptions->optionsForSearchSlot($searchSlot),
        );

        return [
            'target' => $this->formatPersonCard($target),
            'relatives' => $this->familyPeoplePreview($target),
            'allowed_relations' => array_column($options, 'code'),
            'join_relation_options' => $options,
            'join_hint' => 'If you recognize the people below, choose how you are related to '.$this->fullName($target).'. We do not show the family name — only member names.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function joinByMemberCode(User $user, array $data): array
    {
        $code = $this->memberCodes->normalize((string) ($data['member_code'] ?? ''));
        $relation = (string) ($data['relation_to_member'] ?? '');

        if (! in_array($relation, $this->relationOptions->allAllowedCodes(), true)) {
            throw ValidationException::withMessages([
                'relation_to_member' => ['Choose a valid relationship to this person.'],
            ]);
        }

        $target = FamilyMember::query()->where('member_code', $code)->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'member_code' => ['No person found with that code.'],
            ]);
        }

        $parentContext = (array) ($data['parent_context'] ?? []);
        $wireContext = ['parent_names' => $parentContext];

        if ($relation !== 'self') {
            $this->declaredRelatives->storeParentContext($user, $parentContext);
            $verification = $this->joinVerification->verify($target, $relation, $parentContext);
            if (! $verification['ok']) {
                throw ValidationException::withMessages([
                    'relation_to_member' => [$verification['message']],
                ]);
            }

            $wireContext = [
                ...$wireContext,
                'mother' => $verification['resolved']['mother'] ?? null,
                'father' => $verification['resolved']['father'] ?? null,
                'spouse' => $verification['resolved']['spouse'] ?? null,
            ];
        }

        return DB::transaction(function () use ($user, $target, $relation, $data, $wireContext, $parentContext) {
            $viewer = FamilyMember::query()->where('user_id', $user->id)->first();
            $family = Family::query()->findOrFail($target->family_uuid);

            if ($relation === 'self') {
                if ($target->user_id !== null && $target->user_id !== $user->id) {
                    throw ValidationException::withMessages([
                        'relation_to_member' => ['This person is already linked to another account.'],
                    ]);
                }

                if ($viewer && $viewer->uuid !== $target->uuid) {
                    $this->graph()->mergeMemberInto($viewer, $target);
                    $viewer->forceDelete();
                }

                $target->update([
                    'user_id' => $user->id,
                    'first_name' => $data['first_name'] ?? $target->first_name,
                    'last_name' => $data['last_name'] ?? $target->last_name,
                ]);
                $self = $target->fresh();
            } else {
                if (! $viewer) {
                    $self = $this->matcher->joinExistingFamily($user, $family, [
                        'first_name' => $data['first_name'] ?? explode(' ', $user->display_name ?? 'Unknown')[0],
                        'last_name' => $data['last_name'] ?? (explode(' ', $user->display_name ?? 'Unknown')[1] ?? 'Unknown'),
                        'gender' => $data['gender'] ?? 'unknown',
                        'is_living' => true,
                    ]);
                    $this->wireJoinRelation($self, $target, $relation, $user->id, $wireContext);
                } elseif ($viewer->family_uuid !== $family->uuid) {
                    $self = app(CrossFamilyJoinService::class)->joinThroughRelative(
                        $viewer,
                        $target,
                        $relation,
                        $user->id,
                        $wireContext,
                    );
                } else {
                    $self = $viewer;
                    $this->wireJoinRelation($self, $target, $relation, $user->id, $wireContext);
                }

                if ($relation !== 'self') {
                    $this->parentMaterializer->materialize(
                        $user,
                        $self,
                        $wireContext,
                        $parentContext,
                    );
                }
            }

            $this->ensureConfirmedSession($user, $family->uuid);
            $this->ensureSingleUserMembership($user, $self);
            $family->update(['member_count' => FamilyMember::query()->where('family_uuid', $family->uuid)->count()]);

            if ($relation !== 'self') {
                DB::afterCommit(function () use ($user, $family, $self) {
                    event(new FamilyMemberJoined($user, $family, $self));
                });
            }

            return [
                'status' => 'confirmed',
                'family_uuid' => $family->uuid,
                'member_uuid' => $self->uuid,
                'member_code' => $self->member_code,
                'message' => 'You are now connected to this family tree.',
                'people' => $this->familyPeoplePreview($self),
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    public function findFamiliesByRelatives(User $user, array $answers, array $parentContext = []): array
    {
        $requiredParents = $this->relationOptions->requiredParentsForSearch($answers);
        if ($requiredParents !== []) {
            $this->joinVerification->assertParentContextForSlots($requiredParents, $parentContext);
        }

        if ($parentContext !== []) {
            $this->declaredRelatives->storeParentContext($user, $parentContext);
        }

        $relativeMatches = $this->matcher->findCrossFamilyRelativeMatches($answers);

        if ($relativeMatches === []) {
            return [
                'people_matches' => [],
                'message' => 'No matching people found. You can start your own tree or try a member code.',
            ];
        }

        $memberUuids = collect($relativeMatches)->pluck('member_uuid')->unique()->values()->all();
        $members = FamilyMember::query()
            ->select(['uuid', 'family_uuid', 'member_code', 'first_name', 'last_name', 'gender', 'date_of_birth', 'user_id', 'is_living'])
            ->whereIn('uuid', $memberUuids)
            ->get()
            ->keyBy('uuid');

        $familyUuids = $members->pluck('family_uuid')->unique()->values()->all();
        $previewByFamily = $this->batchFamilyPeoplePreview($familyUuids, $memberUuids, limitPerFamily: 6);

        $people = [];
        foreach ($relativeMatches as $match) {
            $member = $members->get($match['member_uuid']);
            if (! $member) {
                continue;
            }

            $disambiguation = $this->disambiguation->scoreMatch(
                $member,
                (string) ($match['relative_slot'] ?? ''),
                $parentContext,
            );

            if ($parentContext !== [] && ! $disambiguation['fits']) {
                continue;
            }

            $people[] = [
                ...$match,
                'person' => $this->formatPersonCard($member),
                'relatives' => $previewByFamily[$member->family_uuid] ?? [],
                'join_relation_options' => $this->relationOptions->enrichOptions(
                    $this->relationOptions->optionsForSearchSlot($match['relative_slot'] ?? null),
                ),
                'connection_path' => $disambiguation['connection_path'],
                'suggested_join_code' => $disambiguation['suggested_join_code'] ?? null,
                'match_score' => max(
                    (float) ($match['match_score'] ?? 0),
                    (float) $disambiguation['score'],
                ),
            ];
        }

        $people = array_map(function (array $row) {
            unset($row['family_name']);

            return $row;
        }, $people);

        usort($people, fn (array $a, array $b) => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

        return [
            'people_matches' => $people,
            'requires_parents' => $requiredParents,
            'message' => $people === []
                ? ($parentContext === []
                    ? 'Add your mother and father names, then search again to find the correct family line.'
                    : 'No family line matched your parents with these relatives. Check parent names or try another cousin path.')
                : 'Select the people you recognize. Same names can belong to different people — check the connection path.',
        ];
    }

    private function ensureSingleUserMembership(User $user, FamilyMember $canonical): void
    {
        FamilyMember::query()
            ->where('user_id', $user->id)
            ->where('uuid', '!=', $canonical->uuid)
            ->get()
            ->each(function (FamilyMember $duplicate) use ($canonical) {
                $this->graph()->mergeMemberInto($duplicate, $canonical);
                $duplicate->forceDelete();
            });

        $canonical->update(['user_id' => $user->id]);
        app(\App\Modules\Avatars\Services\AvatarService::class)
            ->clearMemberAvatarOnClaim($canonical->fresh());
    }

    private function wireJoinRelation(
        FamilyMember $selfMember,
        FamilyMember $target,
        string $relation,
        int $userId,
        array $context = [],
    ): void {
        $this->wiring()->wire($selfMember, $target, $relation, $userId, $context);
    }

    /** @return list<array<string, mixed>> */
    private function familyPeoplePreview(FamilyMember $anchor, int $limit = 12): array
    {
        return $this->batchFamilyPeoplePreview(
            [$anchor->family_uuid],
            [$anchor->uuid],
            limitPerFamily: $limit,
        )[$anchor->family_uuid] ?? [];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function batchFamilyPeoplePreview(
        array $familyUuids,
        array $excludeMemberUuids,
        int $limitPerFamily = 6,
    ): array {
        if ($familyUuids === []) {
            return [];
        }

        $rows = FamilyMember::query()
            ->select(['uuid', 'family_uuid', 'member_code', 'first_name', 'last_name', 'gender', 'date_of_birth', 'user_id', 'is_living'])
            ->whereIn('family_uuid', $familyUuids)
            ->whereNotIn('uuid', $excludeMemberUuids)
            ->orderBy('family_uuid')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $result = [];
        foreach ($rows->groupBy('family_uuid') as $familyUuid => $group) {
            $result[$familyUuid] = $group
                ->take($limitPerFamily)
                ->map(fn (FamilyMember $member) => $this->formatPersonCard($member))
                ->values()
                ->all();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function formatPersonCard(FamilyMember $member): array
    {
        return [
            'member_uuid' => $member->uuid,
            'member_code' => $member->member_code,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'full_name' => $this->fullName($member),
            'gender' => $member->gender,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'is_registered' => $member->user_id !== null,
            'is_living' => $member->is_living,
        ];
    }

    private function fullName(FamilyMember $member): string
    {
        return trim($member->first_name.' '.$member->last_name);
    }

    /** @return array<string, mixed> */
    private function statusPayload(
        User $user,
        FamilyMember $member,
        ?OnboardingSession $session,
        bool $includePeople = false,
    ): array {
        $family = Family::query()
            ->select(['uuid', 'name'])
            ->find($member->family_uuid);

        $familyPayload = null;
        $soloTree = $this->isSoloTree($member);

        if ($family) {
            $familyPayload = [
                'uuid' => $family->uuid,
            ];
            if ($includePeople) {
                $familyPayload['people'] = $this->batchFamilyPeoplePreview(
                    [$family->uuid],
                    [$member->uuid],
                    limitPerFamily: 12,
                )[$family->uuid] ?? [];
            }
        }

        return [
            'has_family' => true,
            'needs_join_choice' => false,
            'can_connect_to_family' => true,
            'solo_tree' => $soloTree,
            'session_status' => $session?->status ?? 'confirmed',
            'member' => [
                'member_uuid' => $member->uuid,
                'member_code' => $member->member_code,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'full_name' => $this->fullName($member),
            ],
            'family' => $familyPayload,
            'join_options' => [
                'member_code',
                'find_by_relatives',
            ],
            ...$this->parentContextPayload($user),
        ];
    }

    /** @return array{parent_context: array<string, array<string, string>>, has_parent_anchors: bool} */
    private function parentContextPayload(User $user): array
    {
        $parentContext = $this->declaredRelatives->getParentContext($user);

        return [
            'parent_context' => $parentContext,
            'has_parent_anchors' => $this->declaredRelatives->hasParentAnchors($user),
        ];
    }

    private function isSoloTree(FamilyMember $member): bool
    {
        return FamilyMember::query()
            ->where('family_uuid', $member->family_uuid)
            ->count() <= 1;
    }

    private function ensureConfirmedSession(User $user, string $familyUuid): OnboardingSession
    {
        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->latest()
            ->first();

        if ($session) {
            if ($session->matched_family_uuid !== $familyUuid) {
                $session->update([
                    'matched_family_uuid' => $familyUuid,
                    'completed_at' => now(),
                ]);
            }

            return $session->fresh();
        }

        OnboardingSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['in_progress', 'matched'])
            ->update(['status' => 'rejected', 'completed_at' => now()]);

        return OnboardingSession::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => 'confirmed',
            'matched_family_uuid' => $familyUuid,
            'top_match_score' => 1,
            'match_candidates' => [],
            'completed_at' => now(),
        ]);
    }

    private function displayFamilyLabel(User $user): string
    {
        [, $last] = $this->splitDisplayName($user->display_name);

        return ($last !== '' && $last !== 'Unknown' ? $last : 'Family').' tree';
    }

    /** @return array{0: string, 1: string} */
    private function splitDisplayName(?string $displayName): array
    {
        $parts = preg_split('/\s+/', trim((string) $displayName)) ?: [];
        if ($parts === []) {
            return ['Unknown', 'Unknown'];
        }
        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
