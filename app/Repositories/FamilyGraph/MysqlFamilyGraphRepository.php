<?php

namespace App\Repositories\FamilyGraph;

use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
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
            ->with('user:id,uuid,is_anonymous')
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
