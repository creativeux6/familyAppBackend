<?php

namespace App\Repositories\FamilyGraph;

use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\User;
use App\Modules\FamilyTree\Enums\TreeViewMode;
use App\Modules\FamilyTree\Services\KinshipResolverService;
use Illuminate\Support\Collection;

class MysqlFamilyGraphRepository implements FamilyGraphRepositoryInterface
{
    public function __construct(
        private readonly KinshipResolverService $kinshipResolver,
    ) {}

    public function loadFamilyGraph(string $familyUuid): array
    {
        $members = FamilyMember::query()
            ->where('family_uuid', $familyUuid)
            ->with(['user' => fn ($query) => $query->select(User::FAMILY_GRAPH_COLUMNS)])
            ->get();

        $memberUuids = $members->pluck('uuid');

        $edges = RelationshipEdge::query()
            ->whereIn('from_member_uuid', $memberUuids)
            ->whereIn('to_member_uuid', $memberUuids)
            ->with('edgeType:id,code')
            ->get();

        return [
            'members' => $members,
            'edges' => $edges,
        ];
    }

    public function buildSubtree(
        string $rootMemberUuid,
        TreeViewMode $mode,
        int $maxDepth,
        Collection $members,
        Collection $edges,
    ): array {
        if ($mode === TreeViewMode::Inlaws) {
            return $this->buildInlawsSubtree(
                $rootMemberUuid,
                $maxDepth,
                $members,
                $edges,
            );
        }

        $adjacency = $this->kinshipResolver->buildAdjacency($edges, $mode);
        $membersByUuid = $members->keyBy('uuid');

        $queue = [[$rootMemberUuid, 0]];
        $visited = [$rootMemberUuid => 0];
        $memberResults = [];

        while ($queue !== []) {
            [$currentUuid, $depth] = array_shift($queue);

            if ($depth > $maxDepth) {
                continue;
            }

            /** @var FamilyMember|null $member */
            $member = $membersByUuid->get($currentUuid);

            if ($member) {
                $memberResults[] = $this->formatMemberNode($member, $depth);
            }

            if ($depth === $maxDepth) {
                continue;
            }

            foreach ($adjacency[$currentUuid] ?? [] as $step) {
                if (! isset($visited[$step['to']])) {
                    $visited[$step['to']] = $depth + 1;
                    $queue[] = [$step['to'], $depth + 1];
                }
            }
        }

        if ($mode !== TreeViewMode::Blood) {
            $memberResults = $this->includeDirectSpouses($memberResults, $membersByUuid, $edges);
        }

        $memberResults = $this->includeParentsOfVisibleMembers(
            $memberResults,
            $membersByUuid,
            $edges,
        );

        if ($mode !== TreeViewMode::Blood) {
            $memberResults = $this->includeDirectSpouses($memberResults, $membersByUuid, $edges);
        }

        return $this->finalizeSubtree($memberResults, $edges);
    }

    /**
     * Spouse-side in-law view: viewer, spouse(s), couple children, and the
     * spouse's blood relatives — distinct from blood and all.
     *
     * @return array{members: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function buildInlawsSubtree(
        string $rootMemberUuid,
        int $maxDepth,
        Collection $members,
        Collection $edges,
    ): array {
        $membersByUuid = $members->keyBy('uuid');
        $bloodAdjacency = $this->kinshipResolver->buildAdjacency($edges, TreeViewMode::Blood);

        $spouseUuids = [];
        foreach ($edges as $edge) {
            if ($edge->edgeType->code !== 'spouse_of') {
                continue;
            }
            if ($edge->from_member_uuid === $rootMemberUuid) {
                $spouseUuids[] = $edge->to_member_uuid;
            } elseif ($edge->to_member_uuid === $rootMemberUuid) {
                $spouseUuids[] = $edge->from_member_uuid;
            }
        }
        $spouseUuids = array_values(array_unique($spouseUuids));

        $visited = [$rootMemberUuid => 0];
        foreach ($spouseUuids as $spouseUuid) {
            $visited[$spouseUuid] = 0;
        }

        // Couple's children (parent edges from root or spouse).
        $couple = array_flip([$rootMemberUuid, ...$spouseUuids]);
        foreach ($edges as $edge) {
            if (! in_array($edge->edgeType->code, ['parent_of', 'adoptive_parent_of', 'step_parent_of'], true)) {
                continue;
            }
            if (! isset($couple[$edge->from_member_uuid])) {
                continue;
            }
            $childUuid = $edge->to_member_uuid;
            if (! isset($visited[$childUuid])) {
                $visited[$childUuid] = 1;
            }
        }

        // Blood walk from each spouse (in-law family).
        $queue = [];
        foreach ($spouseUuids as $spouseUuid) {
            $queue[] = [$spouseUuid, 0];
        }

        while ($queue !== []) {
            [$currentUuid, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($bloodAdjacency[$currentUuid] ?? [] as $step) {
                $next = $step['to'];
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = $depth + 1;
                $queue[] = [$next, $depth + 1];
            }
        }

        $memberResults = [];
        foreach ($visited as $uuid => $depth) {
            /** @var FamilyMember|null $member */
            $member = $membersByUuid->get($uuid);
            if ($member) {
                $memberResults[] = $this->formatMemberNode($member, $depth);
            }
        }

        $memberResults = $this->includeParentsOfVisibleMembers(
            $memberResults,
            $membersByUuid,
            $edges,
        );
        $memberResults = $this->includeDirectSpouses($memberResults, $membersByUuid, $edges);

        return $this->finalizeSubtree($memberResults, $edges);
    }

    /**
     * @param  list<array<string, mixed>>  $memberResults
     * @return array{members: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function finalizeSubtree(array $memberResults, Collection $edges): array
    {
        $visibleUuids = collect($memberResults)->pluck('uuid')->all();

        $edgeResults = $edges
            ->filter(fn (RelationshipEdge $edge) => in_array($edge->from_member_uuid, $visibleUuids, true)
                && in_array($edge->to_member_uuid, $visibleUuids, true))
            ->map(fn (RelationshipEdge $edge) => [
                'uuid' => $edge->uuid,
                'from_member_uuid' => $edge->from_member_uuid,
                'to_member_uuid' => $edge->to_member_uuid,
                'edge_type' => $edge->edgeType->code,
            ])
            ->values()
            ->all();

        return [
            'members' => $memberResults,
            'edges' => $edgeResults,
        ];
    }

    public function resolveKinship(
        string $viewerMemberUuid,
        string $targetMemberUuid,
        TreeViewMode $mode,
        Collection $edges,
    ): array {
        $adjacency = $this->kinshipResolver->buildAdjacency($edges, $mode);
        $path = $this->kinshipResolver->findPath($viewerMemberUuid, $targetMemberUuid, $adjacency);

        $targetMember = FamilyMember::query()->find($targetMemberUuid);
        $gender = $targetMember?->gender ?? 'unknown';

        return [
            'kinship_label' => $this->kinshipResolver->labelFromPath($path, $gender),
            'path_found' => $path !== null,
            'path_length' => $path === null ? null : count($path),
        ];
    }

    /** @return array<string, mixed> */
    private function formatMemberNode(FamilyMember $member, int $depth): array
    {
        return [
            'uuid' => $member->uuid,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'gender' => $member->gender,
            'is_living' => $member->is_living,
            'date_of_death' => $member->date_of_death?->format('Y-m-d'),
            'is_registered' => $member->user_id !== null,
            'user_uuid' => $member->user?->uuid,
            'avatar' => app(\App\Modules\Avatars\Services\AvatarService::class)
                ->memberAvatarPayload($member),
            'depth' => $depth,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $memberResults
     * @return list<array<string, mixed>>
     */
    private function includeDirectSpouses(
        array $memberResults,
        Collection $membersByUuid,
        Collection $edges,
    ): array {
        $indexed = collect($memberResults)->keyBy('uuid');
        $depthByUuid = $indexed->map(fn (array $node) => $node['depth']);

        foreach ($edges as $edge) {
            if ($edge->edgeType->code !== 'spouse_of') {
                continue;
            }

            foreach ([
                [$edge->from_member_uuid, $edge->to_member_uuid],
                [$edge->to_member_uuid, $edge->from_member_uuid],
            ] as [$visibleUuid, $spouseUuid]) {
                if (! $indexed->has($visibleUuid) || $indexed->has($spouseUuid)) {
                    continue;
                }

                /** @var FamilyMember|null $spouse */
                $spouse = $membersByUuid->get($spouseUuid);
                if (! $spouse) {
                    continue;
                }

                $memberResults[] = $this->formatMemberNode(
                    $spouse,
                    (int) $depthByUuid->get($visibleUuid, 0),
                );
                $indexed->put($spouseUuid, true);
            }
        }

        return $memberResults;
    }

    /**
     * @param  list<array<string, mixed>>  $memberResults
     * @return list<array<string, mixed>>
     */
    private function includeParentsOfVisibleMembers(
        array $memberResults,
        Collection $membersByUuid,
        Collection $edges,
    ): array {
        $indexed = collect($memberResults)->keyBy('uuid');

        foreach ($edges as $edge) {
            if (! in_array($edge->edgeType->code, ['parent_of', 'adoptive_parent_of', 'step_parent_of'], true)) {
                continue;
            }

            $parentUuid = $edge->from_member_uuid;
            $childUuid = $edge->to_member_uuid;

            if (! $indexed->has($childUuid) || $indexed->has($parentUuid)) {
                continue;
            }

            /** @var FamilyMember|null $parent */
            $parent = $membersByUuid->get($parentUuid);
            if (! $parent) {
                continue;
            }

            $childDepth = (int) ($indexed->get($childUuid)['depth'] ?? 0);
            $memberResults[] = $this->formatMemberNode($parent, max(0, $childDepth - 1));
            $indexed->put($parentUuid, $memberResults[array_key_last($memberResults)]);
        }

        return $memberResults;
    }
}
