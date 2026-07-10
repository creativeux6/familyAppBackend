<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Str;

class FamilyGraphMaterializationService
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
        private readonly FamilyMatcherService $matcher,
        private readonly DeclaredRelativeService $declaredRelatives,
    ) {}

    /**
     * Merge onboarding answers into the canonical family graph after the user joins.
     * Matches existing members (e.g. registered child Waheed) once; creates stubs for others (e.g. Adeel, parents).
     *
     * @param  array<int, array<string, mixed>>  $answers
     */
    public function materializeOnboardingAnswers(
        User $user,
        FamilyMember $selfMember,
        array $answers,
    ): void {
        $membersBySlot = ['self' => $selfMember];

        foreach ($answers as $answer) {
            $slot = $answer['relative_slot'] ?? null;

            if ($slot === 'self' || ! $this->graph->relativeHasInfo($answer)) {
                continue;
            }

            $key = $this->memberKeyForAnswer($answer);
            $membersBySlot[$key] = $this->findOrCreateMemberInFamily($selfMember, $answer);
        }

        $this->wireRelationships($membersBySlot, $user->id);
        $this->linkDeclaredRelatives($user, $membersBySlot);
    }

    /** @param  array<string, mixed>  $answer */
    private function findOrCreateMemberInFamily(FamilyMember $anchor, array $answer): FamilyMember
    {
        $existing = FamilyMember::query()
            ->where('family_uuid', $anchor->family_uuid)
            ->get()
            ->sortByDesc(fn (FamilyMember $candidate) => $this->matcher->scoreAnswer($answer, $candidate))
            ->first(
                fn (FamilyMember $candidate) => $this->matcher->scoreAnswer($answer, $candidate)
                    >= FamilyMatcherService::SELF_STUB_THRESHOLD
            );

        if ($existing) {
            if ($existing->user_id === null) {
                $existing->update($this->memberAttributesFromAnswer($anchor, $answer));
            }

            return $existing->fresh();
        }

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            ...$this->memberAttributesFromAnswer($anchor, $answer),
            'user_id' => null,
            'match_confidence' => 0,
        ]);
    }

    /** @param  array<string, FamilyMember>  $membersBySlot */
    private function wireRelationships(array $membersBySlot, int $userId): void
    {
        $parentChild = [
            ['father', 'self'],
            ['mother', 'self'],
            ['paternal_grandfather', 'father'],
            ['paternal_grandmother', 'father'],
            ['maternal_grandfather', 'mother'],
            ['maternal_grandmother', 'mother'],
            ['spouse_father', 'spouse'],
            ['spouse_mother', 'spouse'],
        ];

        foreach ($parentChild as [$parentSlot, $childSlot]) {
            if (! isset($membersBySlot[$parentSlot], $membersBySlot[$childSlot])) {
                continue;
            }

            $this->graph->ensureParentEdge(
                $membersBySlot[$parentSlot],
                $membersBySlot[$childSlot],
                $userId,
            );
        }

        if (isset($membersBySlot['self'], $membersBySlot['spouse'])) {
            $this->graph->ensureSpouseEdge(
                $membersBySlot['self'],
                $membersBySlot['spouse'],
                $userId,
            );
        }

        foreach ($membersBySlot as $key => $childMember) {
            if (! str_starts_with($key, 'child_')) {
                continue;
            }

            if (isset($membersBySlot['self'])) {
                $this->graph->ensureParentEdge(
                    $membersBySlot['self'],
                    $childMember,
                    $userId,
                );
            }

            if (isset($membersBySlot['spouse'])) {
                $this->graph->ensureParentEdge(
                    $membersBySlot['spouse'],
                    $childMember,
                    $userId,
                );
            }
        }
    }

    /** @param  array<string, FamilyMember>  $membersBySlot */
    private function linkDeclaredRelatives(User $user, array $membersBySlot): void
    {
        foreach ($membersBySlot as $key => $member) {
            if ($key === 'self') {
                continue;
            }

            if (str_starts_with($key, 'child_')) {
                $index = (int) str_replace('child_', '', $key);
                $this->declaredRelatives->linkMemberToDeclared($user, $member, 'child', $index);

                continue;
            }

            $relationType = match ($key) {
                'father', 'mother', 'spouse', 'spouse_father', 'spouse_mother' => $key,
                'paternal_grandfather', 'paternal_grandmother',
                'maternal_grandfather', 'maternal_grandmother', 'other_relative' => $key,
                default => null,
            };

            if ($relationType) {
                $this->declaredRelatives->linkMemberToDeclared($user, $member, $relationType);
            }
        }
    }

    /** @param  array<string, mixed>  $answer */
    private function memberKeyForAnswer(array $answer): string
    {
        $slot = $answer['relative_slot'];

        if (in_array($slot, ['child', 'sibling', 'other_relative'], true)) {
            return $slot.'_'.($answer['relation_index'] ?? 0);
        }

        return $slot;
    }

    /** @param  array<string, mixed>  $answer */
    private function memberAttributesFromAnswer(FamilyMember $anchor, array $answer): array
    {
        return [
            'family_uuid' => $anchor->family_uuid,
            'first_name' => $answer['first_name'] ?? 'Unknown',
            'last_name' => $answer['last_name'] ?? 'Unknown',
            'maiden_name' => $answer['maiden_name'] ?? null,
            'date_of_birth' => $answer['date_of_birth'] ?? null,
            'birthplace' => $answer['birthplace'] ?? null,
            'gender' => $answer['gender'] ?? $this->defaultGenderForSlot($answer['relative_slot'] ?? ''),
            'is_living' => $answer['is_living'] ?? true,
            'date_of_death' => ($answer['is_living'] ?? true)
                ? null
                : ($answer['date_of_death'] ?? null),
        ];
    }

    private function defaultGenderForSlot(string $slot): string
    {
        return match ($slot) {
            'father', 'paternal_grandfather', 'maternal_grandfather', 'spouse_father' => 'male',
            'mother', 'paternal_grandmother', 'maternal_grandmother', 'spouse_mother' => 'female',
            default => 'unknown',
        };
    }
}
