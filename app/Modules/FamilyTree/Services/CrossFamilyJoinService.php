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

            $selfInTarget = FamilyMember::query()
                ->where('family_uuid', $targetFamily->uuid)
                ->where('user_id', $user->id)
                ->first();

            if (! $selfInTarget) {
                $selfInTarget = $this->matcher->joinExistingFamily($user, $targetFamily, [
                    'first_name' => $selfMember->first_name,
                    'last_name' => $selfMember->last_name,
                    'date_of_birth' => $selfMember->date_of_birth?->format('Y-m-d'),
                    'birthplace' => $selfMember->birthplace,
                    'gender' => $selfMember->gender,
                    'is_living' => $selfMember->is_living,
                ]);

                if ($selfInTarget->uuid !== $selfMember->uuid) {
                    $this->graph->mergeMemberInto($selfMember, $selfInTarget);
                    $selfMember->forceDelete();
                }
            }

            $this->wireRelation($selfInTarget, $existingRelative, $relationType, $userId, $wireContext);
            $this->purgeLeftoverStubs($oldFamilyUuid, $targetFamily->uuid);

            return $selfInTarget->fresh();
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
