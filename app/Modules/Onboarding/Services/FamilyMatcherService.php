<?php

namespace App\Modules\Onboarding\Services;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FamilyMatcherService
{
    public const MATCH_THRESHOLD = 0.55;

    public const SELF_STUB_THRESHOLD = 0.5;

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array{family: ?Family, score: float, candidates: array}
     */
    public function match(array $answers): array
    {
        $scores = [];

        foreach ($answers as $answer) {
            if ($answer['relative_slot'] === 'self') {
                continue;
            }

            $query = FamilyMember::query()
                ->when($answer['last_name'] ?? null, fn ($q, $v) => $q->where('last_name', 'like', $v))
                ->when($answer['first_name'] ?? null, fn ($q, $v) => $q->where('first_name', 'like', $v))
                ->when($answer['date_of_birth'] ?? null, fn ($q, $v) => $q->whereDate('date_of_birth', $v));

            foreach ($query->get() as $member) {
                $scores[$member->family_uuid] = ($scores[$member->family_uuid] ?? 0) + $this->scoreAnswer($answer, $member);
            }
        }

        if (empty($scores)) {
            return ['family' => null, 'score' => 0.0, 'candidates' => []];
        }

        arsort($scores);
        $topFamilyUuid = array_key_first($scores);
        $maxPossible = max(1, count($answers) - 1);
        $normalizedScore = min(1.0, ($scores[$topFamilyUuid] ?? 0) / $maxPossible);

        $bestSingleMatch = 0.0;
        $bestSingleFamilyUuid = null;
        foreach ($answers as $answer) {
            if (($answer['relative_slot'] ?? null) === 'self') {
                continue;
            }

            $members = FamilyMember::query()
                ->when($answer['last_name'] ?? null, fn ($q, $v) => $q->where('last_name', 'like', $v))
                ->when($answer['first_name'] ?? null, fn ($q, $v) => $q->where('first_name', 'like', $v))
                ->when($answer['date_of_birth'] ?? null, fn ($q, $v) => $q->whereDate('date_of_birth', $v))
                ->get();

            foreach ($members as $member) {
                $answerScore = $this->scoreAnswer($answer, $member);
                if ($answerScore > $bestSingleMatch) {
                    $bestSingleMatch = $answerScore;
                    $bestSingleFamilyUuid = $member->family_uuid;
                }
            }
        }

        $candidates = collect($scores)->take(5)->map(fn ($score, $uuid) => [
            'family_uuid' => $uuid,
            'score' => round(min(1.0, $score / $maxPossible), 4),
        ])->values()->all();

        $family = null;
        $finalScore = $normalizedScore;

        if ($normalizedScore >= self::MATCH_THRESHOLD) {
            $family = Family::query()->find($topFamilyUuid);
        } elseif ($bestSingleMatch >= 0.85 && $bestSingleFamilyUuid) {
            $family = Family::query()->find($bestSingleFamilyUuid);
            $finalScore = $bestSingleMatch;
        }

        return [
            'family' => $family,
            'score' => round($finalScore, 4),
            'candidates' => $candidates,
        ];
    }

    /**
     * Find relative answers that match members in other families.
     *
     * @param  array<int, array<string, mixed>>  $answers
     * @return list<array<string, mixed>>
     */
    public function findCrossFamilyRelativeMatches(array $answers, ?string $excludeFamilyUuid = null): array
    {
        $validAnswers = collect($answers)
            ->filter(function (array $answer) {
                $slot = $answer['relative_slot'] ?? null;

                return $slot !== 'self' && $slot !== null && $this->answerHasInfo($answer);
            })
            ->values();

        if ($validAnswers->isEmpty()) {
            return [];
        }

        $candidates = FamilyMember::query()
            ->select(['uuid', 'family_uuid', 'first_name', 'last_name', 'date_of_birth'])
            ->with(['family:uuid,name'])
            ->when($excludeFamilyUuid, fn ($q) => $q->where('family_uuid', '!=', $excludeFamilyUuid))
            ->where(function ($query) use ($validAnswers) {
                foreach ($validAnswers as $answer) {
                    $firstName = trim((string) ($answer['first_name'] ?? ''));
                    $lastName = trim((string) ($answer['last_name'] ?? ''));

                    $query->orWhere(function ($sub) use ($firstName, $lastName, $answer) {
                        if ($lastName !== '') {
                            $sub->where('last_name', $lastName);
                        }
                        if ($firstName !== '') {
                            $sub->where('first_name', $firstName);
                        }
                        if (! empty($answer['date_of_birth'])) {
                            $sub->whereDate('date_of_birth', $answer['date_of_birth']);
                        }
                    });
                }
            })
            ->limit(40)
            ->get();

        $matches = [];
        foreach ($validAnswers as $answer) {
            $slot = (string) $answer['relative_slot'];
            foreach ($candidates as $member) {
                $score = $this->scoreAnswer($answer, $member);
                if ($score < self::SELF_STUB_THRESHOLD) {
                    continue;
                }

                $matches[] = [
                    'relative_slot' => $slot,
                    'member_uuid' => $member->uuid,
                    'family_uuid' => $member->family_uuid,
                    'family_name' => $member->family?->name,
                    'first_name' => $member->first_name,
                    'last_name' => $member->last_name,
                    'match_score' => round($score, 2),
                    'relationship_hints' => [],
                ];
            }
        }

        return collect($matches)
            ->sortByDesc('match_score')
            ->unique('member_uuid')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $memberUuids
     * @return array<string, list<string>>
     */
    private function batchDescribeMembersInFamily(array $memberUuids): array
    {
        if ($memberUuids === []) {
            return [];
        }

        $hints = array_fill_keys($memberUuids, []);
        $parentTypeCodes = ['parent_of', 'adoptive_parent_of', 'step_parent_of'];

        $parentEdges = RelationshipEdge::query()
            ->whereIn('to_member_uuid', $memberUuids)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', $parentTypeCodes))
            ->get(['from_member_uuid', 'to_member_uuid']);

        $childEdges = RelationshipEdge::query()
            ->whereIn('from_member_uuid', $memberUuids)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', $parentTypeCodes))
            ->get(['from_member_uuid', 'to_member_uuid']);

        $spouseEdges = RelationshipEdge::query()
            ->where(function ($query) use ($memberUuids) {
                $query->whereIn('from_member_uuid', $memberUuids)
                    ->orWhereIn('to_member_uuid', $memberUuids);
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->get(['from_member_uuid', 'to_member_uuid']);

        $relatedUuids = collect()
            ->merge($parentEdges->pluck('from_member_uuid'))
            ->merge($childEdges->pluck('to_member_uuid'))
            ->merge($spouseEdges->flatMap(fn ($edge) => [$edge->from_member_uuid, $edge->to_member_uuid]))
            ->unique()
            ->diff($memberUuids)
            ->values()
            ->all();

        $membersByUuid = FamilyMember::query()
            ->whereIn('uuid', [...$memberUuids, ...$relatedUuids])
            ->get(['uuid', 'first_name', 'last_name', 'gender'])
            ->keyBy('uuid');

        foreach ($parentEdges as $edge) {
            $parent = $membersByUuid->get($edge->from_member_uuid);
            if (! $parent) {
                continue;
            }

            $parentName = trim($parent->first_name.' '.$parent->last_name);
            $label = $this->parentLabelForGender($parent->gender);
            $hints[$edge->to_member_uuid][] = "{$label}: {$parentName}";
        }

        foreach ($childEdges as $edge) {
            $child = $membersByUuid->get($edge->to_member_uuid);
            if (! $child) {
                continue;
            }

            $childName = trim($child->first_name.' '.$child->last_name);
            $hints[$edge->from_member_uuid][] = "Parent of {$childName}";
        }

        foreach ($spouseEdges as $edge) {
            foreach ([$edge->from_member_uuid, $edge->to_member_uuid] as $memberUuid) {
                if (! in_array($memberUuid, $memberUuids, true)) {
                    continue;
                }

                $otherUuid = $edge->from_member_uuid === $memberUuid
                    ? $edge->to_member_uuid
                    : $edge->from_member_uuid;
                $spouse = $membersByUuid->get($otherUuid);
                if (! $spouse) {
                    continue;
                }

                $spouseName = trim($spouse->first_name.' '.$spouse->last_name);
                $hints[$memberUuid][] = "Spouse of {$spouseName}";
            }
        }

        $childrenByParent = [];
        foreach ($parentEdges as $edge) {
            $childrenByParent[$edge->from_member_uuid][] = $edge->to_member_uuid;
        }

        foreach ($memberUuids as $memberUuid) {
            foreach ($parentEdges->where('to_member_uuid', $memberUuid) as $edge) {
                foreach ($childrenByParent[$edge->from_member_uuid] ?? [] as $siblingUuid) {
                    if ($siblingUuid === $memberUuid) {
                        continue;
                    }

                    $sibling = $membersByUuid->get($siblingUuid);
                    if (! $sibling) {
                        continue;
                    }

                    $siblingName = trim($sibling->first_name.' '.$sibling->last_name);
                    $hints[$memberUuid][] = "Sibling of {$siblingName}";
                }
            }
        }

        foreach ($hints as $memberUuid => $memberHints) {
            $hints[$memberUuid] = array_slice(array_values(array_unique($memberHints)), 0, 5);
        }

        return $hints;
    }

    /** @return list<string> */
    private function describeMemberInFamily(FamilyMember $member): array
    {
        $hints = [];
        $linked = $this->linkedRegisteredRelatives($member);

        foreach ($linked as $item) {
            $name = $item['display_name'];
            $relation = $item['relationship'];
            if ($relation === 'child of') {
                $hints[] = "Parent of {$name}";
            } elseif (str_ends_with($relation, ' of')) {
                $hints[] = ucfirst(trim($relation)).' '.$name;
            }
        }

        $parentEdges = RelationshipEdge::query()
            ->where('to_member_uuid', $member->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($parentEdges as $edge) {
            $parent = FamilyMember::query()->find($edge->from_member_uuid);
            if (! $parent) {
                continue;
            }

            $parentName = trim($parent->first_name.' '.$parent->last_name);
            $label = $this->parentLabelForGender($parent->gender);
            $hints[] = "{$label}: {$parentName}";
        }

        $childEdges = RelationshipEdge::query()
            ->where('from_member_uuid', $member->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($childEdges as $edge) {
            $child = FamilyMember::query()->find($edge->to_member_uuid);
            if (! $child) {
                continue;
            }

            $childName = trim($child->first_name.' '.$child->last_name);
            $hints[] = "Parent of {$childName}";
        }

        $spouseUuid = $this->spouseUuidFor($member->uuid);
        if ($spouseUuid) {
            $spouse = FamilyMember::query()->find($spouseUuid);
            if ($spouse) {
                $spouseName = trim($spouse->first_name.' '.$spouse->last_name);
                $hints[] = "Spouse of {$spouseName}";
            }
        }

        $siblingHints = RelationshipEdge::query()
            ->where('to_member_uuid', $member->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get()
            ->flatMap(function ($edge) use ($member) {
                return RelationshipEdge::query()
                    ->where('from_member_uuid', $edge->from_member_uuid)
                    ->where('to_member_uuid', '!=', $member->uuid)
                    ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                        'parent_of', 'adoptive_parent_of', 'step_parent_of',
                    ]))
                    ->get()
                    ->map(function ($siblingEdge) use ($member) {
                        $sibling = FamilyMember::query()->find($siblingEdge->to_member_uuid);
                        if (! $sibling) {
                            return null;
                        }

                        $siblingName = trim($sibling->first_name.' '.$sibling->last_name);

                        return "Sibling of {$siblingName}";
                    })
                    ->filter();
            })
            ->unique()
            ->values()
            ->all();

        return array_values(array_unique([...$hints, ...$siblingHints]));
    }

    /** @param  array<string, mixed>  $answer */
    private function answerHasInfo(array $answer): bool
    {
        foreach (['first_name', 'last_name', 'date_of_birth'] as $field) {
            if (! empty($answer[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match the registering user against unclaimed family member stubs.
     *
     * @param  array<string, mixed>  $selfAnswer
     * @return list<array<string, mixed>>
     */
    public function findSelfStubMatches(array $selfAnswer): array
    {
        if (($selfAnswer['first_name'] ?? null) === null && ($selfAnswer['last_name'] ?? null) === null) {
            return [];
        }

        $query = FamilyMember::query()
            ->whereNull('user_id')
            ->when(
                $selfAnswer['last_name'] ?? null,
                fn ($q, $value) => $q->where('last_name', 'like', $value),
            );

        return $query->get()
            ->map(function (FamilyMember $stub) use ($selfAnswer) {
                $score = $this->scoreAnswer($selfAnswer, $stub);

                if ($score < self::SELF_STUB_THRESHOLD) {
                    return null;
                }

                $family = Family::query()->find($stub->family_uuid);

                return [
                    'member_uuid' => $stub->uuid,
                    'family_uuid' => $stub->family_uuid,
                    'family_name' => $family?->name,
                    'score' => round($score, 4),
                    'relationship_hint' => $this->describeStubRelationship($stub),
                    'linked_relatives' => $this->linkedRegisteredRelatives($stub),
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->take(5)
            ->all();
    }

    public function claimSelfStub(User $user, Family $family, FamilyMember $temporarySelfMember): FamilyMember
    {
        $stub = FamilyMember::query()
            ->where('family_uuid', $family->uuid)
            ->whereNull('user_id')
            ->where('uuid', '!=', $temporarySelfMember->uuid)
            ->get()
            ->sortByDesc(fn (FamilyMember $candidate) => $this->scoreAnswer([
                'first_name' => $temporarySelfMember->first_name,
                'last_name' => $temporarySelfMember->last_name,
                'date_of_birth' => $temporarySelfMember->date_of_birth?->format('Y-m-d'),
            ], $candidate))
            ->first(fn (FamilyMember $candidate) => $this->scoreAnswer([
                'first_name' => $temporarySelfMember->first_name,
                'last_name' => $temporarySelfMember->last_name,
                'date_of_birth' => $temporarySelfMember->date_of_birth?->format('Y-m-d'),
            ], $candidate) >= self::SELF_STUB_THRESHOLD);

        if (! $stub) {
            if ($temporarySelfMember->family_uuid !== $family->uuid) {
                $temporarySelfMember->update(['family_uuid' => $family->uuid]);
            }

            return $temporarySelfMember->fresh();
        }

        $stubAttributes = [
            'first_name' => $temporarySelfMember->first_name,
            'last_name' => $temporarySelfMember->last_name,
            'date_of_birth' => $temporarySelfMember->date_of_birth,
            'birthplace' => $temporarySelfMember->birthplace ?? $stub->birthplace,
            'gender' => $temporarySelfMember->gender !== 'unknown'
                ? $temporarySelfMember->gender
                : $stub->gender,
            'is_living' => $temporarySelfMember->is_living,
        ];

        $this->mergeMemberGraph($temporarySelfMember, $stub);
        $temporarySelfMember->forceDelete();

        $stub->update([
            'user_id' => $user->id,
            ...$stubAttributes,
        ]);

        return $stub->fresh();
    }

    /**
     * Join an existing family when the user has no temporary member row yet.
     *
     * @param  array<string, mixed>  $selfAnswer
     */
    public function joinExistingFamily(User $user, Family $family, array $selfAnswer): FamilyMember
    {
        $stub = FamilyMember::query()
            ->where('family_uuid', $family->uuid)
            ->whereNull('user_id')
            ->get()
            ->sortByDesc(fn (FamilyMember $candidate) => $this->scoreAnswer($selfAnswer, $candidate))
            ->first(fn (FamilyMember $candidate) => $this->scoreAnswer($selfAnswer, $candidate) >= self::SELF_STUB_THRESHOLD);

        if ($stub) {
            $stub->update([
                'user_id' => $user->id,
                'first_name' => $selfAnswer['first_name'] ?? $stub->first_name,
                'last_name' => $selfAnswer['last_name'] ?? $stub->last_name,
                'date_of_birth' => $selfAnswer['date_of_birth'] ?? $stub->date_of_birth?->format('Y-m-d'),
                'birthplace' => $selfAnswer['birthplace'] ?? $stub->birthplace,
                'gender' => ($selfAnswer['gender'] ?? 'unknown') !== 'unknown'
                    ? $selfAnswer['gender']
                    : $stub->gender,
                'is_living' => $selfAnswer['is_living'] ?? $stub->is_living,
            ]);

            return $stub->fresh();
        }

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $family->uuid,
            'user_id' => $user->id,
            'first_name' => $selfAnswer['first_name'] ?? 'Unknown',
            'last_name' => $selfAnswer['last_name'] ?? 'Unknown',
            'date_of_birth' => $selfAnswer['date_of_birth'] ?? null,
            'birthplace' => $selfAnswer['birthplace'] ?? null,
            'gender' => $selfAnswer['gender'] ?? 'unknown',
            'is_living' => $selfAnswer['is_living'] ?? true,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function activeMembersForFamily(Family $family): Collection
    {
        return FamilyMember::query()
            ->where('family_uuid', $family->uuid)
            ->where('is_anonymous', false)
            ->whereNotNull('user_id')
            ->with('user:id,uuid,phone,display_name,is_anonymous')
            ->get()
            ->filter(fn (FamilyMember $m) => $m->user !== null && ! $m->user->is_anonymous)
            ->map(fn (FamilyMember $m) => [
                'user_uuid' => $m->user->uuid,
                'display_name' => $m->user->display_name,
                'phone' => $m->user->phone,
                'member_uuid' => $m->uuid,
            ]);
    }

    /** @return list<array<string, string>> */
    private function linkedRegisteredRelatives(FamilyMember $stub): array
    {
        $linked = [];

        $childEdges = RelationshipEdge::query()
            ->where('from_member_uuid', $stub->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($childEdges as $edge) {
            $child = FamilyMember::query()->with('user')->find($edge->to_member_uuid);
            if (! $child?->user) {
                continue;
            }

            $linked[] = [
                'member_uuid' => $child->uuid,
                'display_name' => $child->user->display_name,
                'relationship' => $this->parentLabelForGender($stub->gender).' of',
            ];
        }

        $parentEdges = RelationshipEdge::query()
            ->where('to_member_uuid', $stub->uuid)
            ->whereHas('edgeType', fn ($query) => $query->whereIn('code', [
                'parent_of', 'adoptive_parent_of', 'step_parent_of',
            ]))
            ->get();

        foreach ($parentEdges as $edge) {
            $parent = FamilyMember::query()->with('user')->find($edge->from_member_uuid);
            if (! $parent?->user) {
                continue;
            }

            $linked[] = [
                'member_uuid' => $parent->uuid,
                'display_name' => $parent->user->display_name,
                'relationship' => 'child of',
            ];
        }

        $spouseUuid = $this->spouseUuidFor($stub->uuid);
        if ($spouseUuid) {
            $spouse = FamilyMember::query()->with('user')->find($spouseUuid);
            if ($spouse?->user) {
                $linked[] = [
                    'member_uuid' => $spouse->uuid,
                    'display_name' => $spouse->user->display_name,
                    'relationship' => 'spouse of',
                ];
            }
        }

        return $linked;
    }

    private function describeStubRelationship(FamilyMember $stub): string
    {
        $linked = $this->linkedRegisteredRelatives($stub);

        if ($linked !== []) {
            $first = $linked[0];
            $name = $first['display_name'];
            $relation = $first['relationship'];

            if ($relation === 'child of') {
                return "You may be the parent of {$name}";
            }

            if (str_ends_with($relation, ' of')) {
                return 'You may be the '.trim($relation).' '.$name;
            }

            return "You may be related to {$name}";
        }

        return 'Someone in this family may already have added you as a relative';
    }

    private function parentLabelForGender(string $gender): string
    {
        return match ($gender) {
            'female' => 'mother',
            'male' => 'father',
            default => 'parent',
        };
    }

    private function spouseUuidFor(string $memberUuid): ?string
    {
        $edge = RelationshipEdge::query()
            ->where(function ($query) use ($memberUuid) {
                $query->where('from_member_uuid', $memberUuid)
                    ->orWhere('to_member_uuid', $memberUuid);
            })
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->first();

        if (! $edge) {
            return null;
        }

        return $edge->from_member_uuid === $memberUuid
            ? $edge->to_member_uuid
            : $edge->from_member_uuid;
    }

    private function mergeMemberGraph(FamilyMember $from, FamilyMember $to): void
    {
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

    /** @param  array<string, mixed>  $answer */
    public function scoreAnswer(array $answer, FamilyMember $member): float
    {
        $score = 0.0;

        if (! empty($answer['last_name']) && strcasecmp($answer['last_name'], $member->last_name) === 0) {
            $score += 0.5;
        }
        if (! empty($answer['first_name']) && strcasecmp($answer['first_name'], $member->first_name) === 0) {
            $score += 0.35;
        }
        if (! empty($answer['date_of_birth']) && $member->date_of_birth?->format('Y-m-d') === $answer['date_of_birth']) {
            $score += 0.15;
        }

        return $score;
    }

    /**
     * Exact full-name match (first + last), case-insensitive.
     * Used to block duplicate siblings/children — never last-name-only.
     *
     * @param  array<string, mixed>  $answer
     */
    public function isSameNamedPerson(array $answer, FamilyMember $member): bool
    {
        $first = trim((string) ($answer['first_name'] ?? ''));
        $last = trim((string) ($answer['last_name'] ?? ''));
        $memberFirst = trim((string) ($member->first_name ?? ''));
        $memberLast = trim((string) ($member->last_name ?? ''));

        if ($first === '' || $last === '' || $memberFirst === '' || $memberLast === '') {
            return false;
        }

        return strcasecmp($first, $memberFirst) === 0
            && strcasecmp($last, $memberLast) === 0;
    }
}
