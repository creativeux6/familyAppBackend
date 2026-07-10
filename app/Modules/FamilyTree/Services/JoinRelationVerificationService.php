<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;

class JoinRelationVerificationService
{
    public function __construct(
        private readonly FamilyRelationPathService $paths,
        private readonly JoinRelationOptionsResolver $relationOptions,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $parentContext
     * @return array{
     *   ok: bool,
     *   message: string,
     *   resolved: array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}
     * }
     */
    public function verify(FamilyMember $target, string $relation, array $parentContext): array
    {
        return $this->paths->verifyJoinPath($target, $relation, $parentContext);
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, array<string, mixed>>  $parentContext
     */
    public function assertParentContextForSlots(array $required, array $parentContext): void
    {
        $this->paths->assertRequiredAnchors($required, $parentContext);
    }

    /** @deprecated Use FamilyRelationPathService::requiredAnchorsForJoinCode */
    public function requiredParentsForRelation(string $code): array
    {
        return $this->relationOptions->requiredParentsForRelation($code);
    }
}
