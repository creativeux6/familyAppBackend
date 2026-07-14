<?php

namespace App\Contracts\FamilyGraph;

use App\Modules\FamilyTree\Enums\TreeViewMode;
use Illuminate\Support\Collection;

interface FamilyGraphRepositoryInterface
{
    /**
     * @return array{members: Collection, edges: Collection}
     */
    public function loadFamilyGraph(string $familyUuid): array;

    /**
     * @return array{members: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function buildSubtree(
        string $rootMemberUuid,
        TreeViewMode $mode,
        int $maxDepth,
        Collection $members,
        Collection $edges,
    ): array;

    public function resolveKinship(
        string $viewerMemberUuid,
        string $targetMemberUuid,
        TreeViewMode $mode,
        Collection $edges,
    ): array;
}
