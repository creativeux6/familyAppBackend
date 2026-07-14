<?php

namespace App\Modules\Groups\Services;

use App\Models\Connection;
use App\Models\User;

class ConnectedMemberGuard
{
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

    /** @param  iterable<User>  $targets */
    public function assertAllConnected(User $actor, iterable $targets): void
    {
        foreach ($targets as $target) {
            if (! $this->areConnected($actor, $target)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'member_user_uuids' => ["You must be connected with {$target->display_name} before adding them to a group."],
                ]);
            }
        }
    }
}
