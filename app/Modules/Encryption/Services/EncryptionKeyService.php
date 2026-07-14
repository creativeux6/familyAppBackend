<?php

namespace App\Modules\Encryption\Services;

use App\Models\User;
use App\Models\UserEncryptionKey;
use App\Models\UserKeyBackup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EncryptionKeyService
{
    public function storeIdentityKey(User $user, string $publicKeyBase64, int $version = 1): UserEncryptionKey
    {
        $binary = base64_decode($publicKeyBase64, true);

        if ($binary === false || strlen($binary) < 16) {
            throw ValidationException::withMessages([
                'public_identity_key' => ['Invalid public key format. Expected base64-encoded key.'],
            ]);
        }

        return UserEncryptionKey::updateOrCreate(
            ['user_id' => $user->id],
            [
                'public_identity_key' => $binary,
                'encryption_version' => $version,
                'rotated_at' => now(),
            ]
        );
    }

    public function getPublicKey(string $userUuid): array
    {
        $user = User::query()->where('uuid', $userUuid)->firstOrFail();

        $record = UserEncryptionKey::query()->where('user_id', $user->id)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'user_uuid' => ['No encryption key found for this user.'],
            ]);
        }

        return [
            'user_uuid' => $user->uuid,
            'public_identity_key' => base64_encode($record->public_identity_key),
            'encryption_version' => $record->encryption_version,
        ];
    }

    public function storeKeyBackup(User $user, string $blobBase64, string $saltBase64, int $version = 1): UserKeyBackup
    {
        $blob = base64_decode($blobBase64, true);
        $salt = base64_decode($saltBase64, true);

        if ($blob === false || $salt === false) {
            throw ValidationException::withMessages([
                'encrypted_private_key_blob' => ['Invalid base64 encoding.'],
            ]);
        }

        UserKeyBackup::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        return UserKeyBackup::create([
            'user_id' => $user->id,
            'encrypted_private_key_blob' => $blob,
            'salt' => $salt,
            'encryption_version' => $version,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getKeyBackup(User $user): ?array
    {
        $backup = UserKeyBackup::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $backup) {
            return null;
        }

        return [
            'encrypted_private_key_blob' => base64_encode($backup->encrypted_private_key_blob),
            'salt' => base64_encode($backup->salt),
            'encryption_version' => $backup->encryption_version,
            'created_at' => $backup->created_at?->toIso8601String(),
        ];
    }
}
