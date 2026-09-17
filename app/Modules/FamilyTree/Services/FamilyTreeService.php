<?php

namespace App\Modules\FamilyTree\Services;

use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Models\Connection;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\FamilyTree\Enums\TreeViewMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FamilyTreeService
{
    private const ADDABLE_RELATIONS = [
        'father', 'mother', 'spouse', 'child', 'sibling', 'spouse_father', 'spouse_mother',
    ];

    public function __construct(
        private readonly FamilyGraphRepositoryInterface $graphRepository,
        private readonly FamilyMemberGraphService $memberGraph,
        private readonly DeclaredRelativeService $declaredRelatives,
        private readonly FamilyMemberCandidateService $memberCandidates,
        private readonly FamilyDisplayNameService $familyNames,
    ) {}

    /** @return array<string, mixed> */
    public function getTree(
        User $user,
        ?string $rootMemberUuid,
        TreeViewMode $viewMode,
        int $maxDepth,
        string $scope = 'bootstrap',
    ): array {
        $viewerMember = $this->requireViewerMember($user);
        $maxDepth = max(1, min(8, $maxDepth));

        $rootUuid = $rootMemberUuid ?? $viewerMember->uuid;
        $this->assertSameFamily($viewerMember, $rootUuid);

        $graph = $this->graphRepository->loadFamilyGraph($viewerMember->family_uuid);
        $subtree = $this->graphRepository->buildSubtree(
            $rootUuid,
            $viewMode,
            $maxDepth,
            $graph['members'],
            $graph['edges'],
        );

        if ($scope !== 'full') {
            $seedUuids = $this->defaultVisibleSeedUuids(
                $viewerMember->uuid,
                $graph['edges'],
            );
            $subtree['members'] = array_values(array_filter(
                $subtree['members'],
                fn (array $node) => in_array($node['uuid'], $seedUuids, true),
            ));
            $visibleSeed = array_column($subtree['members'], 'uuid');
            $subtree['edges'] = array_values(array_filter(
                $subtree['edges'],
                fn (array $edge) => in_array($edge['from_member_uuid'], $visibleSeed, true)
                    && in_array($edge['to_member_uuid'], $visibleSeed, true),
            ));
        }

        return $this->formatTreePayload(
            $user,
            $viewerMember,
            $rootUuid,
            $viewMode,
            $graph,
            $subtree,
        );
    }

    /** @return array<string, mixed> */
    public function expandMemberNeighborhood(
        User $user,
        string $memberUuid,
        TreeViewMode $viewMode,
    ): array {
        $viewerMember = $this->requireViewerMember($user);
        $this->assertSameFamily($viewerMember, $memberUuid);

        $graph = $this->graphRepository->loadFamilyGraph($viewerMember->family_uuid);
        $neighborhoodUuids = $this->neighborhoodUuids($memberUuid, $graph['edges']);
        $neighborhoodUuids[] = $memberUuid;
        $neighborhoodUuids = array_values(array_unique($neighborhoodUuids));

        $membersByUuid = $graph['members']->keyBy('uuid');
        $subtreeMembers = [];
        foreach ($neighborhoodUuids as $uuid) {
            /** @var FamilyMember|null $member */
            $member = $membersByUuid->get($uuid);
            if (! $member) {
                continue;
            }
            $subtreeMembers[] = [
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
                'depth' => 0,
            ];
        }

        $subtree = [
            'members' => $subtreeMembers,
            'edges' => $graph['edges']
                ->filter(fn ($edge) => in_array($edge->from_member_uuid, $neighborhoodUuids, true)
                    && in_array($edge->to_member_uuid, $neighborhoodUuids, true))
                ->map(fn ($edge) => [
                    'uuid' => $edge->uuid,
                    'from_member_uuid' => $edge->from_member_uuid,
                    'to_member_uuid' => $edge->to_member_uuid,
                    'edge_type' => $edge->edgeType->code,
                ])
                ->values()
                ->all(),
        ];

        $payload = $this->formatTreePayload(
            $user,
            $viewerMember,
            $viewerMember->uuid,
            $viewMode,
            $graph,
            $subtree,
        );
        $payload['expanded_member_uuid'] = $memberUuid;

        return $payload;
    }

    /**
     * @param  array{members: Collection, edges: Collection}  $graph
     * @param  array{members: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $subtree
     * @return array<string, mixed>
     */
    private function formatTreePayload(
        User $user,
        FamilyMember $viewerMember,
        string $rootUuid,
        TreeViewMode $viewMode,
        array $graph,
        array $subtree,
    ): array {
        $connectedUserIds = $this->connectedUserIds($user);
        $membersByUuid = $graph['members']->keyBy('uuid');
        $kinshipCache = [];

        $members = collect($subtree['members'])
            ->filter(function (array $node) use ($user, $connectedUserIds, $membersByUuid) {
                /** @var FamilyMember|null $member */
                $member = $membersByUuid->get($node['uuid']);

                return $member && $this->isIncludedInTree($user, $member, $connectedUserIds);
            })
            ->map(function (array $node) use ($user, $viewerMember, $viewMode, $graph, $subtree, $membersByUuid, $connectedUserIds, &$kinshipCache) {
                /** @var FamilyMember $member */
                $member = $membersByUuid->get($node['uuid']);
                $cacheKey = $member->uuid;
                if (! isset($kinshipCache[$cacheKey])) {
                    $kinshipCache[$cacheKey] = $this->graphRepository->resolveKinship(
                        $viewerMember->uuid,
                        $member->uuid,
                        $viewMode,
                        $graph['edges'],
                    );
                }
                $node['kinship_label'] = $kinshipCache[$cacheKey]['kinship_label'];
                $node['expand_count'] = $this->expandCountFor(
                    $member->uuid,
                    $graph['edges'],
                    array_column($subtree['members'], 'uuid'),
                );

                return $this->applyPrivacyToNode(
                    $node,
                    $this->hasFullProfileAccess($user, $member, $connectedUserIds),
                );
            })
            ->values()
            ->all();

        $visibleUuids = collect($members)->pluck('uuid')->all();
        $edges = collect($subtree['edges'])
            ->filter(fn (array $edge) => in_array($edge['from_member_uuid'], $visibleUuids, true)
                && in_array($edge['to_member_uuid'], $visibleUuids, true))
            ->values()
            ->all();

        return [
            'family_uuid' => $viewerMember->family_uuid,
            'family_label' => $this->familyNames->labelForMember($viewerMember),
            'view_mode' => $viewMode->value,
            'root_member_uuid' => $rootUuid,
            'viewer_member_uuid' => $viewerMember->uuid,
            'members' => $members,
            'edges' => $edges,
            'legend' => [
                'registered' => 'Has an app account',
                'unregistered' => 'No app account yet',
                'ghost' => 'Deprecated — unlinked members appear as unregistered',
            ],
        ];
    }

    /**
     * Default canvas seed: viewer, ancestors, viewer siblings, parents' siblings.
     *
     * @return list<string>
     */
    private function defaultVisibleSeedUuids(string $viewerUuid, Collection $edges): array
    {
        $parentsOf = [];
        $childrenOf = [];
        foreach ($edges as $edge) {
            $code = $edge->edgeType->code;
            if (in_array($code, ['parent_of', 'adoptive_parent_of', 'step_parent_of'], true)) {
                $parentsOf[$edge->to_member_uuid][] = $edge->from_member_uuid;
                $childrenOf[$edge->from_member_uuid][] = $edge->to_member_uuid;
            }
        }

        $visible = [$viewerUuid => true];
        $queue = [$viewerUuid];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($parentsOf[$current] ?? [] as $parent) {
                if (! isset($visible[$parent])) {
                    $visible[$parent] = true;
                    $queue[] = $parent;
                }
            }
        }

        foreach ($parentsOf[$viewerUuid] ?? [] as $parent) {
            foreach ($childrenOf[$parent] ?? [] as $sibling) {
                $visible[$sibling] = true;
            }
            foreach ($parentsOf[$parent] ?? [] as $grandparent) {
                foreach ($childrenOf[$grandparent] ?? [] as $uncle) {
                    $visible[$uncle] = true;
                }
            }
        }

        return array_keys($visible);
    }

    /**
     * One-hop expand set: spouse, children, parents, siblings.
     *
     * @return list<string>
     */
    private function neighborhoodUuids(string $memberUuid, Collection $edges): array
    {
        $parentsOf = [];
        $childrenOf = [];
        $spouses = [];
        foreach ($edges as $edge) {
            $code = $edge->edgeType->code;
            if (in_array($code, ['parent_of', 'adoptive_parent_of', 'step_parent_of'], true)) {
                $parentsOf[$edge->to_member_uuid][] = $edge->from_member_uuid;
                $childrenOf[$edge->from_member_uuid][] = $edge->to_member_uuid;
            } elseif ($code === 'spouse_of') {
                if ($edge->from_member_uuid === $memberUuid) {
                    $spouses[] = $edge->to_member_uuid;
                } elseif ($edge->to_member_uuid === $memberUuid) {
                    $spouses[] = $edge->from_member_uuid;
                }
            }
        }

        $out = $spouses;
        // Co-spouses: other partners of each spouse (sister-wives / co-husbands).
        foreach ($spouses as $spouseUuid) {
            foreach ($edges as $edge) {
                if ($edge->edgeType->code !== 'spouse_of') {
                    continue;
                }
                $other = null;
                if ($edge->from_member_uuid === $spouseUuid) {
                    $other = $edge->to_member_uuid;
                } elseif ($edge->to_member_uuid === $spouseUuid) {
                    $other = $edge->from_member_uuid;
                }
                if ($other !== null && $other !== $memberUuid) {
                    $out[] = $other;
                }
            }
        }
        foreach ($childrenOf[$memberUuid] ?? [] as $child) {
            $out[] = $child;
        }
        foreach ($parentsOf[$memberUuid] ?? [] as $parent) {
            $out[] = $parent;
            foreach ($childrenOf[$parent] ?? [] as $sibling) {
                if ($sibling !== $memberUuid) {
                    $out[] = $sibling;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $alreadyVisible
     */
    private function expandCountFor(string $memberUuid, Collection $edges, array $alreadyVisible): int
    {
        $visible = array_flip($alreadyVisible);
        $pending = 0;
        foreach ($this->neighborhoodUuids($memberUuid, $edges) as $uuid) {
            if (! isset($visible[$uuid])) {
                $pending++;
            }
        }

        return $pending;
    }

    /** @return array<string, mixed> */
    public function getFamilyInfo(User $user): array
    {
        $selfMember = $this->requireViewerMember($user);
        $this->declaredRelatives->ensureDeclaredFromGraph($user, $selfMember);

        return [
            'family_info' => $this->memberGraph->familyInfoForMember($selfMember, $user),
            'family_label' => $this->familyNames->labelForMember($selfMember),
            'notice' => 'Manage relatives here. Registered members have an app account; others are stubs until they join.',
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function updateFamilyInfo(User $user, array $data): array
    {
        $selfMember = $this->requireViewerMember($user);

        return DB::transaction(function () use ($user, $selfMember, $data) {
            $this->memberGraph->syncMatchingInfo($selfMember, $data, $user);
            $freshSelf = $this->requireViewerMember($user);
            $familyInfo = $this->memberGraph->familyInfoForMember($freshSelf, $user);
            $this->declaredRelatives->syncMatchingInfo($user, $familyInfo);

            Family::query()
                ->where('uuid', $selfMember->family_uuid)
                ->update(['member_count' => FamilyMember::query()
                    ->where('family_uuid', $selfMember->family_uuid)
                    ->count(),
                ]);

            return [
                'family_info' => $familyInfo,
                'family_label' => $this->familyNames->labelForMember($freshSelf),
                'notice' => 'Manage relatives here. Registered members have an app account; others are stubs until they join.',
            ];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function findMemberCandidates(User $user, array $data): array
    {
        $viewer = $this->requireViewerMember($user);

        return [
            'candidates' => $this->memberCandidates->findCandidates(
                $viewer,
                $data,
                $data['exclude_uuid'] ?? null,
                $data['relation_type'] ?? null,
            ),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function addMember(User $user, array $data): array
    {
        $selfMember = $this->requireViewerMember($user);
        $relationType = $data['relation_type'];

        if (! in_array($relationType, self::ADDABLE_RELATIONS, true)) {
            throw ValidationException::withMessages([
                'relation_type' => ['Unsupported relation type.'],
            ]);
        }

        return DB::transaction(function () use ($user, $selfMember, $data, $relationType) {
            try {
                $member = $this->memberGraph->addMember(
                    $selfMember,
                    $relationType,
                    $data,
                    $user->id,
                    self::ADDABLE_RELATIONS,
                );
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages([
                    'uuid' => [
                        'That person is already linked to an account in the family tree. '
                        .'Choose “same person” again, or pick a different relative.',
                    ],
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'family_members_user_id')) {
                    throw ValidationException::withMessages([
                        'uuid' => [
                            'That person is already linked to an account in the family tree. '
                            .'Choose “same person” again, or pick a different relative.',
                        ],
                    ]);
                }

                throw $e;
            }

            $relationIndex = match ($relationType) {
                'child', 'sibling' => $this->declaredRelatives->nextRelationIndex(
                    $user,
                    $relationType,
                    $member->uuid,
                ),
                default => 0,
            };

            $this->declaredRelatives->upsertDeclared(
                $user,
                $relationType,
                $relationIndex,
                $data,
                $member->uuid,
            );

            // Cross-family "same person" may move the viewer into another family.
            $viewer = FamilyMember::query()
                ->where('user_id', $user->id)
                ->first() ?? $selfMember->fresh() ?? $selfMember;

            Family::query()
                ->where('uuid', $viewer->family_uuid)
                ->update(['member_count' => FamilyMember::query()
                    ->where('family_uuid', $viewer->family_uuid)
                    ->count(),
                ]);

            return [
                'member' => $this->memberGraph->formatRelative($member),
                'family_info' => $this->memberGraph->familyInfoForMember($viewer, $user),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function getMember(User $user, string $memberUuid, TreeViewMode $viewMode): array
    {
        $viewerMember = $this->requireViewerMember($user);
        $member = $this->findFamilyMember($viewerMember, $memberUuid);
        $connectedUserIds = $this->connectedUserIds($user);
        $this->assertIncludedInTree($user, $member, $connectedUserIds);

        $graph = $this->graphRepository->loadFamilyGraph($viewerMember->family_uuid);
        $kinship = $this->graphRepository->resolveKinship(
            $viewerMember->uuid,
            $member->uuid,
            $viewMode,
            $graph['edges'],
        );

        $fullAccess = $this->hasFullProfileAccess($user, $member, $connectedUserIds);

        return [
            'member' => $this->formatMemberDetail($member, $fullAccess, $kinship['kinship_label']),
            'kinship_label' => $kinship['kinship_label'],
            'view_mode' => $viewMode->value,
            'is_ghost' => false,
            'connection' => $this->formatMemberConnection($user, $member),
        ];
    }

    /** @return array<string, mixed> */
    public function getKinship(User $user, string $targetMemberUuid, TreeViewMode $viewMode): array
    {
        $viewerMember = $this->requireViewerMember($user);
        $targetMember = $this->findFamilyMember($viewerMember, $targetMemberUuid);
        $this->assertIncludedInTree($user, $targetMember, $this->connectedUserIds($user));

        $graph = $this->graphRepository->loadFamilyGraph($viewerMember->family_uuid);
        $kinship = $this->graphRepository->resolveKinship(
            $viewerMember->uuid,
            $targetMember->uuid,
            $viewMode,
            $graph['edges'],
        );

        return [
            'viewer_member_uuid' => $viewerMember->uuid,
            'target_member_uuid' => $targetMember->uuid,
            'kinship_label' => $kinship['kinship_label'],
            'view_mode' => $viewMode->value,
            'path_found' => $kinship['path_found'],
        ];
    }

    private function requireViewerMember(User $user): FamilyMember
    {
        $member = FamilyMember::query()->where('user_id', $user->id)->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'family' => ['Complete onboarding and confirm your family before viewing the tree.'],
            ]);
        }

        return $member;
    }

    private function assertSameFamily(FamilyMember $viewerMember, string $rootMemberUuid): void
    {
        $exists = FamilyMember::query()
            ->where('uuid', $rootMemberUuid)
            ->where('family_uuid', $viewerMember->family_uuid)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'root_member_uuid' => ['Root member must belong to your family.'],
            ]);
        }
    }

    private function findFamilyMember(FamilyMember $viewerMember, string $memberUuid): FamilyMember
    {
        $member = FamilyMember::query()
            ->where('uuid', $memberUuid)
            ->where('family_uuid', $viewerMember->family_uuid)
            ->with(['user' => fn ($query) => $query->select(User::FAMILY_GRAPH_COLUMNS)])
            ->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'member_uuid' => ['Family member not found.'],
            ]);
        }

        return $member;
    }

    /** @param  Collection<int, int>  $connectedUserIds */
    private function assertIncludedInTree(
        User $viewer,
        FamilyMember $member,
        Collection $connectedUserIds,
    ): void {
        if (! $this->isIncludedInTree($viewer, $member, $connectedUserIds)) {
            throw ValidationException::withMessages([
                'member_uuid' => ['You do not have permission to view this member.'],
            ]);
        }
    }

    /** @param  Collection<int, int>  $connectedUserIds */
    private function isIncludedInTree(
        User $viewer,
        FamilyMember $member,
        Collection $connectedUserIds,
    ): bool {
        if ($member->user_id === $viewer->id) {
            return true;
        }

        if ($member->user_id === null) {
            return true;
        }

        if ($member->is_anonymous || ($member->user && $member->user->is_anonymous)) {
            return $connectedUserIds->contains($member->user_id);
        }

        return true;
    }

    /** @param  Collection<int, int>  $connectedUserIds */
    private function hasFullProfileAccess(
        User $viewer,
        FamilyMember $member,
        Collection $connectedUserIds,
    ): bool {
        if ($member->user_id === $viewer->id) {
            return true;
        }

        if ($member->user_id === null) {
            return true;
        }

        return $connectedUserIds->contains($member->user_id);
    }

    /** @param  array<string, mixed>  $node */
    private function applyPrivacyToNode(array $node, bool $fullAccess): array
    {
        $hasAppAccount = (bool) ($node['is_registered'] ?? false);

        if ($fullAccess) {
            $node['is_ghost'] = false;

            return $node;
        }

        return [
            ...$node,
            'is_registered' => $hasAppAccount,
            'is_ghost' => false,
            'user_uuid' => null,
            'date_of_birth' => null,
            'date_of_death' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function formatMemberConnection(User $viewer, FamilyMember $member): ?array
    {
        if ($member->user_id === null || $member->user_id === $viewer->id) {
            return null;
        }

        $connection = Connection::query()
            ->where(function ($query) use ($viewer, $member) {
                $query->where('requester_user_id', $viewer->id)
                    ->where('recipient_user_id', $member->user_id);
            })
            ->orWhere(function ($query) use ($viewer, $member) {
                $query->where('requester_user_id', $member->user_id)
                    ->where('recipient_user_id', $viewer->id);
            })
            ->first();

        $status = $connection?->status;

        return [
            'user_uuid' => $member->user?->uuid,
            'connection_uuid' => $connection?->uuid,
            'status' => $status ?? 'none',
            'can_connect' => $connection === null
                || in_array($status, ['rejected', 'disconnected'], true),
            'can_unlink' => $status === 'connected',
        ];
    }

    /** @return Collection<int, int> */
    private function connectedUserIds(User $user): Collection
    {
        return Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($user) {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            })
            ->get()
            ->map(fn (Connection $connection) => $connection->requester_user_id === $user->id
                ? $connection->recipient_user_id
                : $connection->requester_user_id);
    }

    /** @return array<string, mixed> */
    private function formatMemberDetail(
        FamilyMember $member,
        bool $fullAccess,
        string $kinshipLabel = 'Family member',
    ): array {
        if (! $fullAccess) {
            return [
                'uuid' => $member->uuid,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'gender' => $member->gender,
                'date_of_birth' => null,
                'date_of_death' => null,
                'is_living' => $member->is_living,
                'is_registered' => false,
                'is_ghost' => false,
                'user_uuid' => null,
                'avatar' => app(\App\Modules\Avatars\Services\AvatarService::class)
                    ->memberAvatarPayload($member),
            ];
        }

        return [
            'uuid' => $member->uuid,
            'member_code' => $member->member_code,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'gender' => $member->gender,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'date_of_death' => $member->date_of_death?->format('Y-m-d'),
            'is_living' => $member->is_living,
            'is_registered' => $member->user_id !== null,
            'is_ghost' => false,
            'user_uuid' => $member->user?->uuid,
            'avatar' => app(\App\Modules\Avatars\Services\AvatarService::class)
                ->memberAvatarPayload($member),
        ];
    }
}
