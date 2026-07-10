<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserDeclaredRelative;
use Illuminate\Support\Str;

/**
 * Materializes join-time parent context into graph members and edges.
 */
class JoinParentGraphMaterializer
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
        private readonly DeclaredRelativeService $declaredRelatives,
        private readonly FamilyRelationPathService $paths,
    ) {}

    /**
     * @param  array{
     *   mother?: ?FamilyMember,
     *   father?: ?FamilyMember,
     *   spouse?: ?FamilyMember,
     *   parent_names?: array<string, array<string, string>>
     * }  $context
     * @param  array<string, array<string, mixed>>  $parentNames
     */
    public function materialize(
        User $user,
        FamilyMember $selfMember,
        array $context,
        array $parentNames = [],
    ): void {
        $context['parent_names'] = array_merge(
            $context['parent_names'] ?? [],
            $parentNames,
        );

        $mother = $this->resolveParent($selfMember, 'mother', $context, $user->id);
        $father = $this->resolveParent($selfMember, 'father', $context, $user->id);

        if ($mother) {
            $this->graph->ensureParentEdge($mother, $selfMember, $user->id);
            $this->declaredRelatives->linkMemberToDeclared($user, $mother, 'mother');
        }

        if ($father) {
            $this->graph->ensureParentEdge($father, $selfMember, $user->id);
            $this->declaredRelatives->linkMemberToDeclared($user, $father, 'father');
        }

        if ($mother && $father) {
            $this->graph->ensureSpouseEdge($mother, $father, $user->id);
        }
    }

    /**
     * @param  array{
     *   mother?: ?FamilyMember,
     *   father?: ?FamilyMember,
     *   parent_names?: array<string, array<string, string>>
     * }  $context
     */
    private function resolveParent(
        FamilyMember $selfMember,
        string $slot,
        array $context,
        int $userId,
    ): ?FamilyMember {
        $fromContext = $context[$slot] ?? null;
        if ($fromContext instanceof FamilyMember) {
            return $fromContext;
        }

        $names = $context['parent_names'][$slot] ?? null;
        if ($names === null) {
            return null;
        }

        $resolved = $this->paths->resolveAnchorsInFamily($selfMember, [$slot => $names]);
        $member = $resolved[$slot] ?? null;

        if ($member) {
            return $member;
        }

        $first = trim((string) ($names['first_name'] ?? ''));
        $last = trim((string) ($names['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            return null;
        }

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $selfMember->family_uuid,
            'first_name' => $first,
            'last_name' => $last !== '' ? $last : $selfMember->last_name,
            'gender' => $slot === 'father' ? 'male' : 'female',
            'is_living' => true,
        ]);
    }

    public function repairDeclaredParentsForUser(User $user, FamilyMember $selfMember): void
    {
        $parentNames = [];
        foreach (['mother', 'father'] as $slot) {
            $declared = UserDeclaredRelative::query()
                ->where('user_id', $user->id)
                ->where('relation_type', $slot)
                ->where('relation_index', 0)
                ->first();

            if (! $declared) {
                continue;
            }

            $parentNames[$slot] = [
                'first_name' => $declared->first_name,
                'last_name' => $declared->last_name,
            ];
        }

        if ($parentNames === []) {
            return;
        }

        $this->materialize($user, $selfMember, [], $parentNames);
    }
}
