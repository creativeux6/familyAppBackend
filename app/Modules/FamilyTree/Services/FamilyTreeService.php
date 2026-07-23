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

        $connectedUserIds = $this->connectedUserIds($user);
        $membersByUuid = $graph['members']->keyBy('uuid');

        $members = collect($subtree['members'])
            ->filter(function (array $node) use ($user, $connectedUserIds, $membersByUuid) {
                /** @var FamilyMember|null $member */
                $member = $membersByUuid->get($node['uuid']);

                return $member && $this->isIncludedInTree($user, $member, $connectedUserIds);
            })
            ->map(function (array $node) use ($user, $viewerMember, $viewMode, $graph, $membersByUuid, $connectedUserIds) {
                /** @var FamilyMember $member */
                $member = $membersByUuid->get($node['uuid']);
                $kinship = $this->graphRepository->resolveKinship(
                    $viewerMember->uuid,
                    $member->uuid,
                    $viewMode,
                    $graph['edges'],
                );

                $node['kinship_label'] = $kinship['kinship_label'];

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
            $member = $this->memberGraph->addMember(
                $selfMember,
                $relationType,
                $data,
                $user->id,
                self::ADDABLE_RELATIONS,
            );

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

            Family::query()
                ->where('uuid', $selfMember->family_uuid)
                ->update(['member_count' => FamilyMember::query()
                    ->where('family_uuid', $selfMember->family_uuid)
                    ->count(),
                ]);

            return [
                'member' => $this->memberGraph->formatRelative($member),
                'family_info' => $this->memberGraph->familyInfoForMember($selfMember->fresh(), $user),
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
            ->with('user:id,uuid,is_anonymous')
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
