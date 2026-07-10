<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;

class FamilyDisplayNameService
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
    ) {}

    public function labelForMember(FamilyMember $member): string
    {
        if ($this->isFamilyHead($member)) {
            return $this->formatName($member->last_name);
        }

        $parents = $this->graph->parentsOf($member->uuid);

        if (isset($parents['father']) && $this->isFamilyHead($parents['father'])) {
            return $this->formatName($parents['father']->last_name);
        }

        if (isset($parents['father'])) {
            return $this->formatName($parents['father']->last_name);
        }

        if (isset($parents['mother'])) {
            return $this->formatName($parents['mother']->last_name);
        }

        return $this->formatName($member->last_name);
    }

    private function isFamilyHead(FamilyMember $member): bool
    {
        if ($member->gender !== 'male') {
            return false;
        }

        if ($this->graph->findSpouseMember($member) !== null) {
            return true;
        }

        return $this->graph->childrenOf($member->uuid)->isNotEmpty();
    }

    private function formatName(?string $lastName): string
    {
        $last = trim((string) $lastName);

        if ($last === '') {
            return 'Family';
        }

        return "{$last} Family";
    }
}
