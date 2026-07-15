<?php

namespace App\Modules\Devices\Services;

use App\Models\DevicePushToken;
use App\Models\User;

class DevicePushTokenService
{
    public function register(User $user, string $token, string $platform = 'android'): void
    {
        // One device token belongs to at most one account (reclaim on login switch).
        DevicePushToken::query()->where('token', $token)->delete();

        DevicePushToken::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => $platform,
        ]);
    }

    public function remove(User $user, string $token): void
    {
        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->delete();
    }

    /** @return list<string> */
    public function tokensForUser(User $user): array
    {
        return DevicePushToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->all();
    }
}
