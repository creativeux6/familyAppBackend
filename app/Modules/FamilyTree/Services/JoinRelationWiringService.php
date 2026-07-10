<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use Illuminate\Support\Str;

class JoinRelationWiringService
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
    ) {}

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    public function wire(
        FamilyMember $selfMember,
        FamilyMember $target,
        string $relation,
        int $userId,
        array $context = [],
    ): void {
        match ($relation) {
            'father', 'mother', 'step_father', 'step_mother' => $this->graph->ensureParentEdge(
                $target,
                $selfMember,
                $userId,
            ),
            'spouse_father', 'spouse_mother' => $this->wireSpouseInLaw(
                $selfMember,
                $target,
                $userId,
                $context,
            ),
            'child' => $this->graph->ensureParentEdge($selfMember, $target, $userId),
            'spouse' => $this->graph->ensureSpouseEdge($selfMember, $target, $userId),
            'sibling', 'half_sibling', 'half_sibling_mother', 'half_sibling_father' => $this->wireSiblingChoice(
                $selfMember,
                $target,
                $userId,
                $relation,
                $context,
            ),
            'cousin_mother_brother_child' => $this->wireCousinThroughParentSibling(
                $selfMember,
                $target,
                $userId,
                parentSide: 'father',
                userLine: 'mother',
                context: $context,
            ),
            'cousin_mother_sister_child' => $this->wireCousinThroughParentSibling(
                $selfMember,
                $target,
                $userId,
                parentSide: 'mother',
                userLine: 'mother',
                context: $context,
            ),
            'cousin_father_brother_child' => $this->wireCousinThroughParentSibling(
                $selfMember,
                $target,
                $userId,
                parentSide: 'father',
                userLine: 'father',
                context: $context,
            ),
            'cousin_father_sister_child' => $this->wireCousinThroughParentSibling(
                $selfMember,
                $target,
                $userId,
                parentSide: 'mother',
                userLine: 'father',
                context: $context,
            ),
            'uncle_mother_brother' => $this->wireParentSibling($selfMember, $target, $userId, 'mother', $context),
            'uncle_mother_sister_husband' => $this->wireSpouseOfParentSibling($selfMember, $target, $userId, 'mother', 'female', $context),
            'uncle_father_brother' => $this->wireParentSibling($selfMember, $target, $userId, 'father', $context),
            'uncle_father_sister_husband' => $this->wireSpouseOfParentSibling($selfMember, $target, $userId, 'father', 'female', $context),
            'aunt_mother_sister' => $this->wireParentSibling($selfMember, $target, $userId, 'mother', $context),
            'aunt_mother_brother_wife' => $this->wireSpouseOfParentSibling($selfMember, $target, $userId, 'mother', 'male', $context),
            'aunt_father_sister' => $this->wireParentSibling($selfMember, $target, $userId, 'father', $context),
            'aunt_father_brother_wife' => $this->wireSpouseOfParentSibling($selfMember, $target, $userId, 'father', 'male', $context),
            'grandfather_paternal' => $this->wireGrandparent($selfMember, $target, $userId, 'father', $context),
            'grandmother_paternal' => $this->wireGrandparent($selfMember, $target, $userId, 'father', $context),
            'grandfather_maternal' => $this->wireGrandparent($selfMember, $target, $userId, 'mother', $context),
            'grandmother_maternal' => $this->wireGrandparent($selfMember, $target, $userId, 'mother', $context),
            default => null,
        };
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, spouse?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireSpouseInLaw(
        FamilyMember $selfMember,
        FamilyMember $inLaw,
        int $userId,
        array $context,
    ): void {
        $spouse = $context['spouse'] ?? null;
        if (! $spouse instanceof FamilyMember) {
            $spouse = $this->graph->findSpouseMember($selfMember);
        }

        if (! $spouse) {
            return;
        }

        $this->graph->ensureParentEdge($inLaw, $spouse, $userId);
    }

    private function wireSibling(FamilyMember $selfMember, FamilyMember $sibling, int $userId): void
    {
        foreach ($this->graph->parentsOf($sibling->uuid) as $parent) {
            $this->graph->ensureParentEdge($parent, $selfMember, $userId);
        }
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, spouse?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireSiblingChoice(
        FamilyMember $selfMember,
        FamilyMember $sibling,
        int $userId,
        string $relation,
        array $context,
    ): void {
        match ($relation) {
            'half_sibling_mother' => $this->wireHalfSibling($selfMember, $sibling, $userId, 'mother', $context),
            'half_sibling_father' => $this->wireHalfSibling($selfMember, $sibling, $userId, 'father', $context),
            default => $this->wireSibling($selfMember, $sibling, $userId),
        };
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, spouse?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireHalfSibling(
        FamilyMember $selfMember,
        FamilyMember $sibling,
        int $userId,
        string $side,
        array $context,
    ): void {
        $parent = $this->resolveUserParent($selfMember, $side, $userId, $context);
        $this->graph->ensureParentEdge($parent, $selfMember, $userId);
        $this->graph->ensureParentEdge($parent, $sibling, $userId);
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireParentSibling(
        FamilyMember $selfMember,
        FamilyMember $target,
        int $userId,
        string $userParentLine,
        array $context,
    ): void {
        $userParent = $this->resolveUserParent($selfMember, $userParentLine, $userId, $context);
        $this->wireSibling($userParent, $target, $userId);
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireSpouseOfParentSibling(
        FamilyMember $selfMember,
        FamilyMember $target,
        int $userId,
        string $userParentLine,
        string $siblingGender,
        array $context,
    ): void {
        $userParent = $this->resolveUserParent($selfMember, $userParentLine, $userId, $context);
        $sibling = $this->ensureSiblingStub($userParent, $siblingGender, $userId);
        $this->graph->ensureSpouseEdge($target, $sibling, $userId);
        $this->wireSibling($userParent, $sibling, $userId);
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireGrandparent(
        FamilyMember $selfMember,
        FamilyMember $target,
        int $userId,
        string $userParentLine,
        array $context,
    ): void {
        $userParent = $this->resolveUserParent($selfMember, $userParentLine, $userId, $context);
        $this->graph->ensureParentEdge($target, $userParent, $userId);
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function wireCousinThroughParentSibling(
        FamilyMember $selfMember,
        FamilyMember $target,
        int $userId,
        string $parentSide,
        string $userLine,
        array $context,
    ): void {
        $anchorParent = $this->pickParentBySide($target, $parentSide);
        $userParent = $this->resolveUserParent($selfMember, $userLine, $userId, $context);

        if ($anchorParent) {
            $this->wireSibling($anchorParent, $userParent, $userId);
        }

        $this->graph->ensureParentEdge($userParent, $selfMember, $userId);
    }

    private function pickParentBySide(FamilyMember $child, string $side): ?FamilyMember
    {
        $parents = $this->graph->parentsOf($child->uuid);

        if ($side === 'father') {
            return $parents['father']
                ?? collect($parents)->first(fn (FamilyMember $p) => $p->gender === 'male');
        }

        return $parents['mother']
            ?? collect($parents)->first(fn (FamilyMember $p) => $p->gender === 'female');
    }

    /**
     * @param  array{mother?: ?FamilyMember, father?: ?FamilyMember, parent_names?: array<string, array<string, string>>}  $context
     */
    private function resolveUserParent(
        FamilyMember $child,
        string $line,
        int $userId,
        array $context,
    ): FamilyMember {
        $fromContext = $context[$line] ?? null;
        if ($fromContext instanceof FamilyMember) {
            return $fromContext;
        }

        $parents = $this->graph->parentsOf($child->uuid);
        $existing = $line === 'father'
            ? ($parents['father'] ?? null)
            : ($parents['mother'] ?? null);

        if ($existing) {
            return $existing;
        }

        $names = $context['parent_names'][$line] ?? [];
        $first = trim((string) ($names['first_name'] ?? ''));
        $last = trim((string) ($names['last_name'] ?? ''));
        $gender = $line === 'father' ? 'male' : 'female';

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $child->family_uuid,
            'first_name' => $first !== '' ? $first : ($line === 'father' ? 'Father' : 'Mother'),
            'last_name' => $last !== '' ? $last : $child->last_name,
            'gender' => $gender,
            'is_living' => true,
        ]);
    }

    private function ensureSiblingStub(
        FamilyMember $anchorParent,
        string $gender,
        int $userId,
    ): FamilyMember {
        $parents = $this->graph->parentsOf($anchorParent->uuid);
        $stub = FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $anchorParent->family_uuid,
            'first_name' => $gender === 'male' ? 'Uncle' : 'Aunt',
            'last_name' => $anchorParent->last_name,
            'gender' => $gender,
            'is_living' => true,
        ]);

        foreach ($parents as $parent) {
            $this->graph->ensureParentEdge($parent, $stub, $userId);
        }

        return $stub;
    }
}
