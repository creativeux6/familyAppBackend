<?php

namespace App\Console\Commands;

use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\User;
use App\Modules\FamilyTree\Services\FamilyMemberGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-attach grandparents / parent-siblings / in-laws that still exist as
 * family_members but lost relationship_edges after a destructive family-info sync.
 */
class RepairDetachedFamilyLinksCommand extends Command
{
    protected $signature = 'family-tree:repair-detached-links
                            {--phone= : Limit to one user phone}
                            {--user= : Limit to one user id}
                            {--all : Repair every user that has a family member}
                            {--diagnose : Only print members/edges/orphans, do not repair}
                            {--dry-run : Show planned edge repairs without writing}';

    protected $description = 'Diagnose and repair detached grandparents, aunts/uncles, and in-laws still present in the family.';

    private int $edgesAdded = 0;

    private int $duplicatesMerged = 0;

    public function handle(FamilyMemberGraphService $graph): int
    {
        $users = $this->resolveUsers();
        if ($users->isEmpty()) {
            $this->error('No users found. Pass --phone=, --user=, or --all.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $diagnoseOnly = (bool) $this->option('diagnose');
        if ($dryRun) {
            $this->warn('Dry run — no changes will be written.');
        }

        foreach ($users as $user) {
            if ($diagnoseOnly) {
                $this->diagnoseUser($user, $graph);

                continue;
            }
            $this->repairUser($user, $graph, $dryRun);
        }

        $this->newLine();
        $this->info("Done. edges_added={$this->edgesAdded} duplicates_merged={$this->duplicatesMerged}");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(): Collection
    {
        if ($this->option('user')) {
            $user = User::query()->find((int) $this->option('user'));

            return $user ? collect([$user]) : collect();
        }

        $phone = trim((string) $this->option('phone'));
        if ($phone !== '') {
            $user = User::query()->where('phone', $phone)->first();

            return $user ? collect([$user]) : collect();
        }

        if ($this->option('all')) {
            return User::query()
                ->whereIn('id', FamilyMember::query()->whereNotNull('user_id')->select('user_id'))
                ->orderBy('id')
                ->get();
        }

        return collect();
    }

    private function diagnoseUser(User $user, FamilyMemberGraphService $graph): void
    {
        $self = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $self) {
            $this->warn("Skip {$user->display_name}: no member row");

            return;
        }

        $members = FamilyMember::query()
            ->where('family_uuid', $self->family_uuid)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $reachable = $this->reachableMemberUuids($self->uuid);
        $this->newLine();
        $this->info("Diagnose {$user->display_name} ({$user->phone}) members={$members->count()} reachable=".count($reachable));

        foreach ($members as $member) {
            $edgeCount = RelationshipEdge::query()
                ->where(function ($q) use ($member) {
                    $q->where('from_member_uuid', $member->uuid)
                        ->orWhere('to_member_uuid', $member->uuid);
                })
                ->count();
            $flag = isset($reachable[$member->uuid]) ? '' : ' [DETACHED]';
            $reg = $member->user_id ? ' registered' : '';
            $this->line(sprintf(
                '  %s %s | edges=%d%s%s | %s',
                $member->first_name,
                $member->last_name,
                $edgeCount,
                $reg,
                $flag,
                $member->uuid,
            ));
        }

        $info = $graph->familyInfoForMember($self, $user);
        $this->line(json_encode([
            'father' => $info['father']['first_name'] ?? null,
            'mother' => $info['mother']['first_name'] ?? null,
            'paternal_grandfather' => $info['paternal_grandfather']['first_name'] ?? null,
            'paternal_grandmother' => $info['paternal_grandmother']['first_name'] ?? null,
            'maternal_grandfather' => $info['maternal_grandfather']['first_name'] ?? null,
            'maternal_grandmother' => $info['maternal_grandmother']['first_name'] ?? null,
            'paternal_uncles' => array_column($info['paternal_uncles'] ?? [], 'first_name'),
            'paternal_aunts' => array_column($info['paternal_aunts'] ?? [], 'first_name'),
            'spouse_father' => $info['spouses'][0]['father']['first_name'] ?? null,
            'spouse_mother' => $info['spouses'][0]['mother']['first_name'] ?? null,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, true>
     */
    private function reachableMemberUuids(string $startUuid): array
    {
        $seen = [$startUuid => true];
        $queue = [$startUuid];
        while ($queue !== []) {
            $current = array_shift($queue);
            $neighbors = RelationshipEdge::query()
                ->where('from_member_uuid', $current)
                ->orWhere('to_member_uuid', $current)
                ->get(['from_member_uuid', 'to_member_uuid']);
            foreach ($neighbors as $edge) {
                foreach ([$edge->from_member_uuid, $edge->to_member_uuid] as $uuid) {
                    if (! isset($seen[$uuid])) {
                        $seen[$uuid] = true;
                        $queue[] = $uuid;
                    }
                }
            }
        }

        return $seen;
    }

    private function repairUser(User $user, FamilyMemberGraphService $graph, bool $dryRun): void
    {
        $self = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $self) {
            $this->warn("Skip {$user->display_name}: no member row");

            return;
        }

        $this->newLine();
        $this->info("Repairing {$user->display_name} ({$user->phone}) family={$self->family_uuid}");

        if (! $dryRun) {
            DB::transaction(function () use ($self, $graph) {
                $this->mergeDuplicateNames($self, $graph);
                $self = $self->fresh();
                $this->repairParentLine($self, 'father', $graph);
                $this->repairParentLine($self, 'mother', $graph);
                $this->repairSpouseInLaws($self, $graph);
            });
        } else {
            $this->mergeDuplicateNames($self, $graph, true);
            $this->repairParentLine($self, 'father', $graph, true);
            $this->repairParentLine($self, 'mother', $graph, true);
            $this->repairSpouseInLaws($self, $graph, true);
        }

        $info = $graph->familyInfoForMember($self->fresh(), $user);
        $this->line(json_encode([
            'father' => $info['father']['first_name'] ?? null,
            'mother' => $info['mother']['first_name'] ?? null,
            'paternal_grandfather' => $info['paternal_grandfather']['first_name'] ?? null,
            'paternal_grandmother' => $info['paternal_grandmother']['first_name'] ?? null,
            'maternal_grandfather' => $info['maternal_grandfather']['first_name'] ?? null,
            'maternal_grandmother' => $info['maternal_grandmother']['first_name'] ?? null,
            'paternal_uncles' => array_column($info['paternal_uncles'] ?? [], 'first_name'),
            'paternal_aunts' => array_column($info['paternal_aunts'] ?? [], 'first_name'),
            'maternal_uncles' => array_column($info['maternal_uncles'] ?? [], 'first_name'),
            'maternal_aunts' => array_column($info['maternal_aunts'] ?? [], 'first_name'),
            'spouse' => $info['spouses'][0]['first_name'] ?? null,
            'spouse_father' => $info['spouse_father']['first_name'] ?? ($info['spouses'][0]['father']['first_name'] ?? null),
            'spouse_mother' => $info['spouse_mother']['first_name'] ?? ($info['spouses'][0]['mother']['first_name'] ?? null),
        ], JSON_PRETTY_PRINT));
    }

    private function mergeDuplicateNames(
        FamilyMember $self,
        FamilyMemberGraphService $graph,
        bool $dryRun = false,
    ): void {
        $members = FamilyMember::query()
            ->where('family_uuid', $self->family_uuid)
            ->get();

        $groups = $members->groupBy(
            fn (FamilyMember $m) => mb_strtolower(trim($m->first_name.'|'.$m->last_name)),
        );

        foreach ($groups as $key => $group) {
            if ($key === '|' || $group->count() < 2) {
                continue;
            }

            // Prefer the member that is already linked to a registered user, else most edges.
            $canonical = $group->sortByDesc(function (FamilyMember $m) {
                $edges = RelationshipEdge::query()
                    ->where(function ($q) use ($m) {
                        $q->where('from_member_uuid', $m->uuid)
                            ->orWhere('to_member_uuid', $m->uuid);
                    })
                    ->count();

                return ($m->user_id ? 1000 : 0) + $edges;
            })->first();

            foreach ($group as $dup) {
                if ($dup->uuid === $canonical->uuid) {
                    continue;
                }
                $this->line("  merge duplicate {$dup->first_name} {$dup->last_name} {$dup->uuid} → {$canonical->uuid}");
                if (! $dryRun) {
                    $graph->mergeMemberInto($dup, $canonical);
                    $dup->forceDelete();
                }
                $this->duplicatesMerged++;
            }
        }
    }

    private function repairParentLine(
        FamilyMember $self,
        string $line,
        FamilyMemberGraphService $graph,
        bool $dryRun = false,
    ): void {
        $parents = $graph->parentsOf($self->uuid);
        $parent = $parents[$line] ?? null;
        if (! $parent instanceof FamilyMember) {
            $this->line("  no {$line} linked — skip {$line} line");

            return;
        }

        $existingGps = $graph->parentsOf($parent->uuid);
        $lastName = trim((string) $parent->last_name);

        $blocked = collect([
            $self->uuid,
            $parent->uuid,
            ...array_values(array_map(
                static fn (?FamilyMember $p) => $p?->uuid,
                $parents,
            )),
            ...$graph->childrenOf($self->uuid)->pluck('uuid')->all(),
            ...$graph->siblingsOf($self->uuid)->pluck('uuid')->all(),
            ...$graph->spouseUuidsFor($self->uuid),
        ])->filter()->unique();

        // People who look like the parent's siblings (same last name, not in viewer's generation).
        $peerUuids = FamilyMember::query()
            ->where('family_uuid', $self->family_uuid)
            ->when($lastName !== '', fn ($q) => $q->where('last_name', $lastName))
            ->where('uuid', '!=', $parent->uuid)
            ->pluck('uuid')
            ->reject(fn (string $uuid) => $blocked->contains($uuid))
            ->values();

        $grandparentUuids = collect();
        if ($peerUuids->isNotEmpty()) {
            $grandparentUuids = RelationshipEdge::query()
                ->whereIn('to_member_uuid', $peerUuids)
                ->whereHas('edgeType', fn ($q) => $q->whereIn('code', [
                    'parent_of', 'adoptive_parent_of', 'step_parent_of',
                ]))
                ->pluck('from_member_uuid');
        }

        // Also accept members who already parent this parent (no-op) or parent
        // existing graph-siblings of the parent, excluding blocked generation.
        foreach ($graph->siblingsOf($parent->uuid) as $sibling) {
            if ($blocked->contains($sibling->uuid)) {
                continue;
            }
            foreach ($graph->parentsOf($sibling->uuid) as $gp) {
                $grandparentUuids->push($gp->uuid);
            }
        }

        foreach ($grandparentUuids->unique()->values() as $gpUuid) {
            if ($blocked->contains($gpUuid)) {
                continue;
            }
            $gp = FamilyMember::query()->find($gpUuid);
            if (! $gp || $gp->family_uuid !== $self->family_uuid) {
                continue;
            }

            // Never promote someone who is already a parent of the viewer.
            if ($graph->childrenOf($gp->uuid)->contains('uuid', $self->uuid)) {
                continue;
            }

            $already = collect($existingGps)->contains(
                fn (?FamilyMember $p) => $p && $p->uuid === $gp->uuid,
            );
            if ($already) {
                continue;
            }

            $this->line("  link {$line}-parent {$gp->first_name} {$gp->last_name} → {$parent->first_name}");
            if (! $dryRun) {
                $graph->ensureParentEdge($gp, $parent, (int) $self->user_id);
            }
            $this->edgesAdded++;
        }

        $gps = $graph->parentsOf($parent->uuid);
        if ($gps === []) {
            $this->line("  {$line} still has no grandparents after candidate scan");

            return;
        }

        // Father's/mother's siblings = other children of those grandparents,
        // excluding the viewer's own generation.
        $knownSiblingUuids = $graph->siblingsOf($parent->uuid)->pluck('uuid')->all();
        foreach ($gps as $gp) {
            foreach ($graph->childrenOf($gp->uuid) as $child) {
                if ($child->uuid === $parent->uuid || $blocked->contains($child->uuid)) {
                    continue;
                }
                if (in_array($child->uuid, $knownSiblingUuids, true)) {
                    continue;
                }
                foreach ($gps as $sharedGp) {
                    $this->line("  ensure {$line}-sibling via {$sharedGp->first_name}: {$child->first_name} {$child->last_name}");
                    if (! $dryRun) {
                        $graph->ensureParentEdge($sharedGp, $child, (int) $self->user_id);
                        $graph->ensureParentEdge($sharedGp, $parent, (int) $self->user_id);
                    }
                    $this->edgesAdded++;
                }
                $knownSiblingUuids[] = $child->uuid;
            }
        }
    }

    private function repairSpouseInLaws(
        FamilyMember $self,
        FamilyMemberGraphService $graph,
        bool $dryRun = false,
    ): void {
        $spouses = $graph->findSpouseMembers($self);
        $blocked = collect([
            $self->uuid,
            ...array_values(array_map(
                static fn (?FamilyMember $p) => $p?->uuid,
                $graph->parentsOf($self->uuid),
            )),
            ...$graph->childrenOf($self->uuid)->pluck('uuid')->all(),
            ...$graph->siblingsOf($self->uuid)->pluck('uuid')->all(),
        ])->filter()->unique();

        foreach ($spouses as $spouse) {
            $spouseParents = $graph->parentsOf($spouse->uuid);
            $lastName = trim((string) $spouse->last_name);

            // Prefer people who already parent this spouse, or parent other
            // same-last-name peers that are not the viewer's generation.
            $peerUuids = FamilyMember::query()
                ->where('family_uuid', $self->family_uuid)
                ->when($lastName !== '', fn ($q) => $q->where('last_name', $lastName))
                ->pluck('uuid')
                ->push($spouse->uuid)
                ->reject(fn (string $uuid) => $blocked->contains($uuid))
                ->unique()
                ->values();

            $inLawUuids = RelationshipEdge::query()
                ->whereIn('to_member_uuid', $peerUuids)
                ->whereHas('edgeType', fn ($q) => $q->whereIn('code', [
                    'parent_of', 'adoptive_parent_of', 'step_parent_of',
                ]))
                ->pluck('from_member_uuid')
                ->unique();

            foreach ($inLawUuids as $inLawUuid) {
                if ($blocked->contains($inLawUuid) || $inLawUuid === $spouse->uuid) {
                    continue;
                }
                $inLaw = FamilyMember::query()->find($inLawUuid);
                if (! $inLaw || $inLaw->family_uuid !== $self->family_uuid) {
                    continue;
                }

                // In-laws should not already be parents of the viewer.
                if ($graph->childrenOf($inLaw->uuid)->contains('uuid', $self->uuid)) {
                    continue;
                }

                $already = collect($spouseParents)->contains(
                    fn (?FamilyMember $p) => $p && $p->uuid === $inLaw->uuid,
                );
                if ($already) {
                    continue;
                }

                $this->line("  link spouse-parent {$inLaw->first_name} {$inLaw->last_name} → {$spouse->first_name}");
                if (! $dryRun) {
                    $graph->ensureParentEdge($inLaw, $spouse, (int) $self->user_id);
                }
                $this->edgesAdded++;
            }
        }
    }
}
