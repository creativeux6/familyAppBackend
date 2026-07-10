<?php

namespace App\Modules\Connections\Services;

use App\Models\FamilyMember;
use App\Models\User;

class PrivacyService
{
    /** @return array<string, mixed> */
    public function settings(User $user): array
    {
        return [
            'is_anonymous' => (bool) $user->is_anonymous,
        ];
    }

    /** @return array<string, mixed> */
    public function updateAnonymity(User $user, bool $isAnonymous): array
    {
        $user->update(['is_anonymous' => $isAnonymous]);

        FamilyMember::query()
            ->where('user_id', $user->id)
            ->update(['is_anonymous' => $isAnonymous]);

        return [
            'is_anonymous' => (bool) $user->is_anonymous,
            'message' => $isAnonymous
                ? 'Anonymity enabled. You are hidden from everyone except yourself.'
                : 'Anonymity disabled. You are visible to family members again.',
        ];
    }
}
