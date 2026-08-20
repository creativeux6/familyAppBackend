<?php

namespace App\Modules\Friends\Services;

use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Models\Connection;
use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserContactHash;
use App\Modules\FamilyTree\Enums\TreeViewMode;
use App\Support\PhoneHash;
use Illuminate\Support\Facades\DB;

class FriendsService
{
    public function __construct(
        private readonly FamilyGraphRepositoryInterface $graphRepository,
    ) {}

    /**
     * @param  list<string>  $phoneHashes
     * @return array{matches: list<array<string, mixed>>}
     */
    public function sync(User $user, array $phoneHashes): array
    {
        $hashes = array_values(array_unique(array_filter(
            $phoneHashes,
            fn ($hash) => is_string($hash) && PhoneHash::isValidHash($hash)
        )));

        DB::transaction(function () use ($user, $hashes): void {
            UserContactHash::query()->where('user_id', $user->id)->delete();

            if ($hashes === []) {
                return;
            }

            $now = now();
            UserContactHash::query()->insert(array_map(
                fn (string $hash) => [
                    'user_id' => $user->id,
                    'phone_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $hashes,
            ));
        });

        return ['matches' => $this->matchesFor($user, $hashes)];
    }

    /** @return array{matches: list<array<string, mixed>>} */
    public function list(User $user): array
    {
        $hashes = UserContactHash::query()
            ->where('user_id', $user->id)
            ->pluck('phone_hash')
            ->all();

        return ['matches' => $this->matchesFor($user, $hashes)];
    }

    public function hasContactMatch(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        $targetHashes = $this->phoneHashesForUser($target);
        if ($targetHashes === []) {
            return false;
        }

        return UserContactHash::query()
            ->where('user_id', $actor->id)
            ->whereIn('phone_hash', $targetHashes)
            ->exists();
    }

    /**
     * @param  list<string>  $hashes
     * @return list<array<string, mixed>>
     */
    private function matchesFor(User $actor, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $hashSet = array_flip($hashes);
        $candidates = User::query()
            ->where('id', '!=', $actor->id)
            ->where('is_anonymous', false)
            ->with('phones')
            ->get(['id', 'uuid', 'display_name', 'phone', 'is_anonymous']);

        $matched = [];
        foreach ($candidates as $candidate) {
            foreach ($this->phoneHashesForUser($candidate) as $hash) {
                if (isset($hashSet[$hash])) {
                    $matched[] = $candidate;
                    break;
                }
            }
        }

        if ($matched === []) {
            return [];
        }

        $matchedIds = array_map(fn (User $u) => $u->id, $matched);
        $blockedIds = $this->relatedUserIds($actor, $matchedIds, 'blocked');
        $connectedIds = $this->relatedUserIds($actor, $matchedIds, 'connected');
        $kinshipByUserId = $this->kinshipLabelsFor($actor, $connectedIds);

        $payload = [];
        foreach ($matched as $candidate) {
            if (isset($blockedIds[$candidate->id])) {
                continue;
            }

            $connected = isset($connectedIds[$candidate->id]);
            foreach ($this->phoneHashesForUser($candidate) as $hash) {
                if (isset($hashSet[$hash])) {
                    $payload[] = [
                        'user_uuid' => $candidate->uuid,
                        'display_name' => $candidate->display_name,
                        'is_registered' => true,
                        'is_connected_family' => $connected,
                        'kinship_label' => $connected
                            ? ($kinshipByUserId[$candidate->id] ?? 'Family')
                            : null,
                        'phone_hash' => $hash,
                    ];
                    break;
                }
            }
        }

        usort($payload, fn (array $a, array $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $payload;
    }

    /** @return list<string> */
    private function phoneHashesForUser(User $user): array
    {
        $phones = [];
        if (is_string($user->phone) && $user->phone !== '') {
            $phones[] = $user->phone;
        }

        if ($user->relationLoaded('phones')) {
            foreach ($user->phones as $row) {
                if ($row->revoked_at !== null) {
                    continue;
                }
                $phones[] = $row->phone;
            }
        }

        $hashes = [];
        foreach (array_unique($phones) as $phone) {
            $hash = PhoneHash::hash($phone);
            if ($hash !== null) {
                $hashes[] = $hash;
            }
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @param  list<int>  $otherIds
     * @return array<int, true>
     */
    private function relatedUserIds(User $actor, array $otherIds, string $status): array
    {
        if ($otherIds === []) {
            return [];
        }

        $rows = Connection::query()
            ->where('status', $status)
            ->where(function ($query) use ($actor, $otherIds) {
                $query->where(function ($inner) use ($actor, $otherIds) {
                    $inner->where('requester_user_id', $actor->id)
                        ->whereIn('recipient_user_id', $otherIds);
                })->orWhere(function ($inner) use ($actor, $otherIds) {
                    $inner->where('recipient_user_id', $actor->id)
                        ->whereIn('requester_user_id', $otherIds);
                });
            })
            ->get(['requester_user_id', 'recipient_user_id']);

        $ids = [];
        foreach ($rows as $row) {
            $other = $row->requester_user_id === $actor->id
                ? $row->recipient_user_id
                : $row->requester_user_id;
            $ids[$other] = true;
        }

        return $ids;
    }

    /**
     * @param  array<int, true>  $connectedIds
     * @return array<int, string>
     */
    private function kinshipLabelsFor(User $actor, array $connectedIds): array
    {
        if ($connectedIds === []) {
            return [];
        }

        $viewerMember = FamilyMember::query()->where('user_id', $actor->id)->first();
        if (! $viewerMember) {
            return [];
        }

        $targetMembers = FamilyMember::query()
            ->where('family_uuid', $viewerMember->family_uuid)
            ->whereIn('user_id', array_keys($connectedIds))
            ->get(['uuid', 'user_id', 'gender']);

        if ($targetMembers->isEmpty()) {
            return [];
        }

        $graph = $this->graphRepository->loadFamilyGraph($viewerMember->family_uuid);
        $labels = [];

        foreach ($targetMembers as $member) {
            $kinship = $this->graphRepository->resolveKinship(
                $viewerMember->uuid,
                $member->uuid,
                TreeViewMode::All,
                $graph['edges'],
            );
            $labels[$member->user_id] = $kinship['kinship_label'] ?? 'Family';
        }

        return $labels;
    }
}
