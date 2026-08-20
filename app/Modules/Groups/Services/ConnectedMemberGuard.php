<?php

namespace App\Modules\Groups\Services;

use App\Models\Connection;
use App\Models\User;
use App\Modules\Friends\Services\FriendsService;
use Illuminate\Validation\ValidationException;

class ConnectedMemberGuard
{
    public function __construct(
        private readonly FriendsService $friendsService,
    ) {}

    public function areConnected(User $a, User $b): bool
    {
        if ($a->id === $b->id) {
            return false;
        }

        return Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($a, $b) {
                $query->where(function ($inner) use ($a, $b) {
                    $inner->where('requester_user_id', $a->id)
                        ->where('recipient_user_id', $b->id);
                })->orWhere(function ($inner) use ($a, $b) {
                    $inner->where('requester_user_id', $b->id)
                        ->where('recipient_user_id', $a->id);
                });
            })
            ->exists();
    }

    public function areBlocked(User $a, User $b): bool
    {
        if ($a->id === $b->id) {
            return false;
        }

        return Connection::query()
            ->where('status', 'blocked')
            ->where(function ($query) use ($a, $b) {
                $query->where(function ($inner) use ($a, $b) {
                    $inner->where('requester_user_id', $a->id)
                        ->where('recipient_user_id', $b->id);
                })->orWhere(function ($inner) use ($a, $b) {
                    $inner->where('requester_user_id', $b->id)
                        ->where('recipient_user_id', $a->id);
                });
            })
            ->exists();
    }

    public function canChatOrShare(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        if ($this->areBlocked($actor, $target)) {
            return false;
        }

        return $this->areConnected($actor, $target)
            || $this->friendsService->hasContactMatch($actor, $target);
    }

    /** @param  iterable<User>  $targets */
    public function assertAllConnected(User $actor, iterable $targets): void
    {
        foreach ($targets as $target) {
            if (! $this->canChatOrShare($actor, $target)) {
                throw ValidationException::withMessages([
                    'member_user_uuids' => ["You can only chat with {$target->display_name} if they are in your contacts and on the app."],
                ]);
            }
        }
    }
}
