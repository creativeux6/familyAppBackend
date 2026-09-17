<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Facades\DB;

class CrossFamilyJoinService
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
        private readonly FamilyMatcherService $matcher,
    ) {}

    public function joinThroughRelative(
        FamilyMember $selfMember,
        FamilyMember $existingRelative,
        string $relationType,
        int $userId,
        array $wireContext = [],
    ): FamilyMember {
        if ($selfMember->user_id === null) {
            throw new \InvalidArgumentException('Only registered members can join another family.');
        }

        return DB::transaction(function () use ($selfMember, $existingRelative, $relationType, $userId, $wireContext) {
            $targetFamily = Family::query()->findOrFail($existingRelative->family_uuid);
            $oldFamilyUuid = $selfMember->family_uuid;
            $user = User::query()->findOrFail($selfMember->user_id);

            $selfAnswer = [
                'first_name' => $selfMember->first_name,
                'last_name' => $selfMember->last_name,
                'date_of_birth' => $selfMember->date_of_birth?->format('Y-m-d'),
                'birthplace' => $selfMember->birthplace,
                'gender' => $selfMember->gender,
                'is_living' => $selfMember->is_living,
            ];

            $selfInTarget = FamilyMember::query()
                ->where('family_uuid', $targetFamily->uuid)
                ->where('user_id', $user->id)
                ->first();

            if (! $selfInTarget) {
                // Prefer the stub the graph already implies (e.g. spouse of the
                // matched mother = father of her children), not a name-only match.
                $preferredStub = $this->findImpliedSelfStub(
                    $existingRelative,
                    $relationType,
                    $selfAnswer,
                );

                $selfInTarget = $this->matcher->joinExistingFamily(
                    $user,
                    $targetFamily,
                    $selfAnswer,
                    $preferredStub,
                );

                if ($selfInTarget->uuid !== $selfMember->uuid) {
                    $this->graph->mergeMemberInto($selfMember, $selfInTarget);
                    $selfMember->forceDelete();
                }
            }

            $this->wireRelation($selfInTarget, $existingRelative, $relationType, $userId, $wireContext);
            $this->mergeSameNameDuplicates($selfInTarget);
            $this->purgeLeftoverStubs($oldFamilyUuid, $targetFamily->uuid);

            return $selfInTarget->fresh();
        });
    }

    /**
     * When linking to an existing relative, find the unregistered member who
     * already fills "me" in that family (spouse of that relative, or co-parent
     * of their children). That prevents a second Khurshid when Waheed already
     * listed Khurshid as father and the real Khurshid adds Nazira as spouse.
     *
     * @param  array<string, mixed>  $selfAnswer
     */
    private function findImpliedSelfStub(
        FamilyMember $relative,
        string $relationType,
        array $selfAnswer,
    ): ?FamilyMember {
        $candidates = collect();

        if ($relationType === 'spouse') {
            $spouseUuid = $this->graph->spouseUuidFor($relative->uuid);
            if ($spouseUuid) {
                $spouse = FamilyMember::query()->find($spouseUuid);
                if ($spouse && $spouse->user_id === null) {
                    $candidates->push($spouse);
                }
            }

            foreach ($this->graph->childrenOf($relative->uuid) as $child) {
                foreach ($this->graph->parentsOf($child->uuid) as $parent) {
                    if ($parent->uuid === $relative->uuid || $parent->user_id !== null) {
                        continue;
                    }
                    $candidates->push($parent);
                }
            }
        }

        if (in_array($relationType, ['father', 'mother'], true)) {
            // Adding a parent: any unregistered "other parent" co-parenting the
            // matched person's siblings is not "me". Self is the child — no
            // implied stub beyond name match in joinExistingFamily.
            return null;
        }

        if (in_array($relationType, ['child'], true)) {
            // Adding a child from another family: self may already be their
            // unregistered parent in that family.
            foreach ($this->graph->parentsOf($relative->uuid) as $parent) {
                if ($parent->user_id === null) {
                    $candidates->push($parent);
                }
            }
        }

        $candidates = $candidates->unique('uuid')->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer exact name match; otherwise if only one implied stub, use it.
        $named = $candidates->first(
            fn (FamilyMember $candidate) => $this->matcher->isSameNamedPerson($selfAnswer, $candidate)
                || $this->matcher->scoreAnswer($selfAnswer, $candidate) >= FamilyMatcherService::SELF_STUB_THRESHOLD
                || (
                    ! empty($selfAnswer['first_name'])
                    && strcasecmp((string) $selfAnswer['first_name'], (string) $candidate->first_name) === 0
                ),
        );

        if ($named) {
            return $named;
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Safety net: collapse unregistered same-name duplicates in the viewer's
     * family into the registered self row (fixes prior bad joins).
     */
    public function mergeSameNameDuplicates(FamilyMember $selfMember): void
    {
        if ($selfMember->user_id === null) {
            return;
        }

        $answer = [
            'first_name' => $selfMember->first_name,
            'last_name' => $selfMember->last_name,
            'date_of_birth' => $selfMember->date_of_birth?->format('Y-m-d'),
        ];

        FamilyMember::query()
            ->where('family_uuid', $selfMember->family_uuid)
            ->where('uuid', '!=', $selfMember->uuid)
            ->whereNull('user_id')
            ->get()
            ->filter(function (FamilyMember $candidate) use ($answer) {
                if ($this->matcher->isSameNamedPerson($answer, $candidate)) {
                    return true;
                }

                $first = trim((string) ($answer['first_name'] ?? ''));
                $candidateFirst = trim((string) ($candidate->first_name ?? ''));

                return $first !== ''
                    && $candidateFirst !== ''
                    && strcasecmp($first, $candidateFirst) === 0
                    && $this->matcher->scoreAnswer($answer, $candidate) >= 0.35;
            })
            ->each(function (FamilyMember $duplicate) use ($selfMember) {
                $this->graph->mergeMemberInto($duplicate, $selfMember);
                $duplicate->forceDelete();
            });
    }

    private function wireRelation(
        FamilyMember $selfMember,
        FamilyMember $relative,
        string $relationType,
        int $userId,
        array $wireContext = [],
    ): void {
        app(JoinRelationWiringService::class)->wire($selfMember, $relative, $relationType, $userId, $wireContext);
    }

    private function purgeLeftoverStubs(string $oldFamilyUuid, string $newFamilyUuid): void
    {
        $oldFamilyHasUsers = FamilyMember::query()
            ->where('family_uuid', $oldFamilyUuid)
            ->whereNotNull('user_id')
            ->exists();

        FamilyMember::query()
            ->where('family_uuid', $oldFamilyUuid)
            ->whereNull('user_id')
            ->get()
            ->each(fn (FamilyMember $member) => $this->graph->deleteMemberGraph($member));

        if (! $oldFamilyHasUsers) {
            Family::query()->where('uuid', $oldFamilyUuid)->delete();
        }

        foreach ([$oldFamilyUuid, $newFamilyUuid] as $familyUuid) {
            if (! Family::query()->where('uuid', $familyUuid)->exists()) {
                continue;
            }

            Family::query()
                ->where('uuid', $familyUuid)
                ->update([
                    'member_count' => FamilyMember::query()
                        ->where('family_uuid', $familyUuid)
                        ->count(),
                ]);
        }
    }
}
