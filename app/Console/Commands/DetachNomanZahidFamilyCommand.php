<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\RelationshipEdgeType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Noman Zahid was wrongly attached under Asgree Bibi during a Waheed tree rebuild.
 * He is an independent family (father: Zahid Akhtar) with no kin link to
 * Asgree / Khurshid Alam / Abdul Waheed.
 */
class DetachNomanZahidFamilyCommand extends Command
{
    protected $signature = 'family-tree:detach-noman-zahid
                            {--phone=+923087119409 : Waheed account phone (source family)}
                            {--dry-run : Print plan only}';

    protected $description = 'Move Noman Zahid household out of Waheed/Khurshid/Asgree family into their own family.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $phone = (string) $this->option('phone');

        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            $this->error("User not found for phone {$phone}");

            return self::FAILURE;
        }

        $self = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $self) {
            $this->error('No family member for user');

            return self::FAILURE;
        }

        $sourceFamily = $self->family_uuid;
        $this->info("Source family {$sourceFamily}");

        $noman = $this->findInFamily($sourceFamily, 'Noman', 'Zahid')
            ?? $this->findInFamily($sourceFamily, 'Noman', '');
        if (! $noman) {
            $this->warn('Noman Zahid not found in Waheed family — nothing to detach.');

            return self::SUCCESS;
        }

        $cluster = $this->collectHousehold($noman);
        $this->line('Household to move:');
        foreach ($cluster as $m) {
            $this->line('  - '.trim($m->first_name.' '.$m->last_name).' ('.$m->uuid.')');
        }

        $asgree = $this->findInFamily($sourceFamily, 'Asgree', 'Bibi')
            ?? $this->findInFamily($sourceFamily, 'Asgari', 'Bibi')
            ?? $this->findInFamily($sourceFamily, 'Asgaree', 'Bibi');

        $run = function () use ($cluster, $noman, $asgree, $sourceFamily, $dry) {
            $clusterUuids = $cluster->pluck('uuid')->all();

            // Drop any parent edges from Waheed-family people INTO the cluster
            // (e.g. Asgree → Noman) and the reverse if somehow present.
            $outsiders = FamilyMember::query()
                ->where('family_uuid', $sourceFamily)
                ->whereNotIn('uuid', $clusterUuids)
                ->pluck('uuid')
                ->all();

            $parentCodes = ['parent_of', 'adoptive_parent_of', 'step_parent_of'];
            $parentTypeIds = RelationshipEdgeType::query()
                ->whereIn('code', $parentCodes)
                ->pluck('id');

            $cut = RelationshipEdge::query()
                ->whereIn('edge_type_id', $parentTypeIds)
                ->where(function ($q) use ($clusterUuids, $outsiders) {
                    $q->where(function ($q2) use ($clusterUuids, $outsiders) {
                        $q2->whereIn('from_member_uuid', $outsiders)
                            ->whereIn('to_member_uuid', $clusterUuids);
                    })->orWhere(function ($q2) use ($clusterUuids, $outsiders) {
                        $q2->whereIn('from_member_uuid', $clusterUuids)
                            ->whereIn('to_member_uuid', $outsiders);
                    });
                });

            $this->line('Cross-family parent edges to delete: '.$cut->count());
            if ($asgree) {
                $this->line('Asgree uuid: '.$asgree->uuid.' (will no longer be Noman\'s mother)');
            }

            if ($dry) {
                return;
            }

            $cut->delete();

            $newFamilyUuid = (string) Str::uuid();
            Family::query()->create([
                'uuid' => $newFamilyUuid,
                'name' => 'Noman Zahid Family',
                'member_count' => $cluster->count(),
            ]);

            FamilyMember::query()
                ->whereIn('uuid', $clusterUuids)
                ->update(['family_uuid' => $newFamilyUuid]);

            // Ensure father Zahid Akhtar exists in Noman's own family (not Waheed's).
            $father = FamilyMember::query()
                ->where('family_uuid', $newFamilyUuid)
                ->whereRaw('LOWER(TRIM(first_name)) = ?', ['zahid'])
                ->whereRaw('LOWER(TRIM(last_name)) = ?', ['akhtar'])
                ->first();

            if (! $father) {
                $father = FamilyMember::create([
                    'uuid' => (string) Str::uuid(),
                    'family_uuid' => $newFamilyUuid,
                    'first_name' => 'Zahid',
                    'last_name' => 'Akhtar',
                    'gender' => 'male',
                    'is_living' => true,
                    'user_id' => null,
                    'match_confidence' => 0,
                ]);
                $this->line('Created father Zahid Akhtar in Noman family');
            }

            $already = RelationshipEdge::query()
                ->where('from_member_uuid', $father->uuid)
                ->where('to_member_uuid', $noman->uuid)
                ->whereIn('edge_type_id', $parentTypeIds)
                ->exists();

            if (! $already) {
                $parentType = RelationshipEdgeType::query()->where('code', 'parent_of')->firstOrFail();
                RelationshipEdge::create([
                    'uuid' => (string) Str::uuid(),
                    'from_member_uuid' => $father->uuid,
                    'to_member_uuid' => $noman->uuid,
                    'edge_type_id' => $parentType->id,
                    'created_by_user_id' => null,
                ]);
                $this->line('Linked Zahid Akhtar → Noman Zahid');
            }

            Family::query()->where('uuid', $newFamilyUuid)->update([
                'member_count' => FamilyMember::query()->where('family_uuid', $newFamilyUuid)->count(),
            ]);
            Family::query()->where('uuid', $sourceFamily)->update([
                'member_count' => FamilyMember::query()->where('family_uuid', $sourceFamily)->count(),
            ]);

            $this->info("Moved household to new family {$newFamilyUuid}");
        };

        if ($dry) {
            $this->warn('Dry run — no writes');
            $run();
        } else {
            DB::transaction($run);
        }

        return self::SUCCESS;
    }

    private function findInFamily(string $familyUuid, string $first, string $last): ?FamilyMember
    {
        $q = FamilyMember::query()
            ->where('family_uuid', $familyUuid)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower(trim($first))]);

        if (trim($last) !== '') {
            $q->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower(trim($last))]);
        }

        return $q->first();
    }

    /** @return \Illuminate\Support\Collection<int, FamilyMember> */
    private function collectHousehold(FamilyMember $noman)
    {
        $uuids = collect([$noman->uuid]);

        // Spouse(s)
        $spouseTypeIds = RelationshipEdgeType::query()
            ->where('code', 'spouse_of')
            ->pluck('id');
        $spouseEdges = RelationshipEdge::query()
            ->whereIn('edge_type_id', $spouseTypeIds)
            ->where(function ($q) use ($noman) {
                $q->where('from_member_uuid', $noman->uuid)
                    ->orWhere('to_member_uuid', $noman->uuid);
            })
            ->get();
        foreach ($spouseEdges as $edge) {
            $uuids->push(
                $edge->from_member_uuid === $noman->uuid
                    ? $edge->to_member_uuid
                    : $edge->from_member_uuid
            );
        }

        $parentTypeIds = RelationshipEdgeType::query()
            ->whereIn('code', ['parent_of', 'adoptive_parent_of', 'step_parent_of'])
            ->pluck('id');

        // Children of Noman or his spouses
        $parents = $uuids->all();
        $childEdges = RelationshipEdge::query()
            ->whereIn('edge_type_id', $parentTypeIds)
            ->whereIn('from_member_uuid', $parents)
            ->get();
        foreach ($childEdges as $edge) {
            $uuids->push($edge->to_member_uuid);
        }

        // Known name fallbacks in same family (Urooj/Hafsa/Ahmad Noman)
        $named = FamilyMember::query()
            ->where('family_uuid', $noman->family_uuid)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereRaw('LOWER(TRIM(first_name)) = ?', ['urooj'])
                        ->whereRaw('LOWER(TRIM(last_name)) = ?', ['noman']);
                })->orWhere(function ($q2) {
                    $q2->whereRaw('LOWER(TRIM(first_name)) = ?', ['hafsa'])
                        ->whereRaw('LOWER(TRIM(last_name)) = ?', ['noman']);
                })->orWhere(function ($q2) {
                    $q2->whereRaw('LOWER(TRIM(first_name)) = ?', ['ahmad'])
                        ->whereRaw('LOWER(TRIM(last_name)) = ?', ['noman']);
                });
            })
            ->pluck('uuid');
        $uuids = $uuids->merge($named)->unique()->values();

        return FamilyMember::query()->whereIn('uuid', $uuids->all())->get();
    }
}
