<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;

class FamilyJoinDisambiguationService
{
    public function __construct(
        private readonly FamilyRelationPathService $paths,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $parentContext
     * @return array{fits: bool, score: float, connection_path: ?string, suggested_join_code: ?string}
     */
    public function scoreMatch(
        FamilyMember $match,
        string $searchSlot,
        array $parentContext,
    ): array {
        return $this->paths->scoreSearchMatch($match, $searchSlot, $parentContext);
    }
}
