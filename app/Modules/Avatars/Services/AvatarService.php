<?php

namespace App\Modules\Avatars\Services;

use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarService
{
    public function __construct(
        private readonly StorageQuotaService $quota,
    ) {}

    /** @return array{thumb_url: ?string, master_url: ?string, updated_at: ?string} */
    public function userAvatarPayload(User $user): array
    {
        return $this->payloadFor(
            subjectType: 'users',
            subjectUuid: $user->uuid,
            thumbKey: $user->avatar_thumb_key,
            masterKey: $user->avatar_master_key,
            updatedAt: $user->avatar_updated_at?->toIso8601String(),
        );
    }

    /**
     * Prefer linked user avatar when present; otherwise member photo.
     *
     * @return array{thumb_url: ?string, master_url: ?string, updated_at: ?string, source: string}
     */
    public function memberAvatarPayload(FamilyMember $member): array
    {
        $member->loadMissing('user');

        if ($member->user && filled($member->user->avatar_thumb_key)) {
            return [
                ...$this->userAvatarPayload($member->user),
                'source' => 'user',
            ];
        }

        return [
            ...$this->payloadFor(
                subjectType: 'members',
                subjectUuid: $member->uuid,
                thumbKey: $member->avatar_thumb_key,
                masterKey: $member->avatar_master_key,
                updatedAt: $member->avatar_updated_at?->toIso8601String(),
            ),
            'source' => filled($member->avatar_thumb_key) ? 'member' : 'none',
        ];
    }

    public function uploadUserAvatar(User $user, UploadedFile $master, UploadedFile $thumb): array
    {
        $this->assertImagePair($master, $thumb);
        $newBytes = $master->getSize() + $thumb->getSize();
        $oldBytes = (int) $user->avatar_master_bytes + (int) $user->avatar_thumb_bytes;
        $delta = $newBytes - $oldBytes;
        if ($delta > 0) {
            $this->quota->assertCanStore($user, $delta);
        }

        $oldMaster = $user->avatar_master_key;
        $oldThumb = $user->avatar_thumb_key;

        $masterKey = $this->objectKey('users', $user->uuid, 'master', $master);
        $thumbKey = $this->objectKey('users', $user->uuid, 'thumb', $thumb);

        $disk = $this->disk();
        $disk->put($masterKey, file_get_contents($master->getRealPath()) ?: '');
        $disk->put($thumbKey, file_get_contents($thumb->getRealPath()) ?: '');

        $user->forceFill([
            'avatar_master_key' => $masterKey,
            'avatar_thumb_key' => $thumbKey,
            'avatar_master_bytes' => $master->getSize(),
            'avatar_thumb_bytes' => $thumb->getSize(),
            'avatar_updated_at' => now(),
        ])->save();

        $this->deleteKeys([$oldMaster, $oldThumb], [$masterKey, $thumbKey]);

        if ($delta > 0) {
            $this->quota->addStoredUsage($user, $delta);
        } elseif ($delta < 0) {
            $this->quota->removeStoredUsage($user, abs($delta));
        }

        return [
            'avatar' => $this->userAvatarPayload($user->fresh()),
        ];
    }

    public function deleteUserAvatar(User $user): array
    {
        $oldBytes = (int) $user->avatar_master_bytes + (int) $user->avatar_thumb_bytes;
        $this->deleteKeys([$user->avatar_master_key, $user->avatar_thumb_key]);

        $user->forceFill([
            'avatar_master_key' => null,
            'avatar_thumb_key' => null,
            'avatar_master_bytes' => 0,
            'avatar_thumb_bytes' => 0,
            'avatar_updated_at' => null,
        ])->save();

        if ($oldBytes > 0) {
            $this->quota->removeStoredUsage($user, $oldBytes);
        }

        return [
            'avatar' => $this->userAvatarPayload($user->fresh()),
        ];
    }

    public function uploadMemberAvatar(User $actor, FamilyMember $member, UploadedFile $master, UploadedFile $thumb): array
    {
        $this->assertCanEditMemberAvatar($actor, $member);
        $this->assertImagePair($master, $thumb);

        $newBytes = $master->getSize() + $thumb->getSize();
        $oldBytes = (int) $member->avatar_master_bytes + (int) $member->avatar_thumb_bytes;
        $delta = $newBytes - $oldBytes;
        if ($delta > 0) {
            $this->quota->assertCanStore($actor, $delta);
        }

        $oldMaster = $member->avatar_master_key;
        $oldThumb = $member->avatar_thumb_key;

        $masterKey = $this->objectKey('members', $member->uuid, 'master', $master);
        $thumbKey = $this->objectKey('members', $member->uuid, 'thumb', $thumb);

        $disk = $this->disk();
        $disk->put($masterKey, file_get_contents($master->getRealPath()) ?: '');
        $disk->put($thumbKey, file_get_contents($thumb->getRealPath()) ?: '');

        $member->forceFill([
            'avatar_master_key' => $masterKey,
            'avatar_thumb_key' => $thumbKey,
            'avatar_master_bytes' => $master->getSize(),
            'avatar_thumb_bytes' => $thumb->getSize(),
            'avatar_updated_at' => now(),
            'avatar_updated_by_user_id' => $actor->id,
        ])->save();

        $this->deleteKeys([$oldMaster, $oldThumb], [$masterKey, $thumbKey]);

        if ($delta > 0) {
            $this->quota->addStoredUsage($actor, $delta);
        } elseif ($delta < 0) {
            $this->quota->removeStoredUsage($actor, abs($delta));
        }

        return [
            'avatar' => $this->memberAvatarPayload($member->fresh()),
        ];
    }

    public function deleteMemberAvatar(User $actor, FamilyMember $member): array
    {
        $this->assertCanEditMemberAvatar($actor, $member);

        $oldBytes = (int) $member->avatar_master_bytes + (int) $member->avatar_thumb_bytes;
        $previousUploaderId = $member->avatar_updated_by_user_id;

        $this->deleteKeys([$member->avatar_master_key, $member->avatar_thumb_key]);

        $member->forceFill([
            'avatar_master_key' => null,
            'avatar_thumb_key' => null,
            'avatar_master_bytes' => 0,
            'avatar_thumb_bytes' => 0,
            'avatar_updated_at' => null,
            'avatar_updated_by_user_id' => null,
        ])->save();

        if ($oldBytes > 0) {
            $chargeUser = $previousUploaderId
                ? User::query()->find($previousUploaderId)
                : $actor;
            if ($chargeUser) {
                $this->quota->removeStoredUsage($chargeUser, $oldBytes);
            }
        }

        return [
            'avatar' => $this->memberAvatarPayload($member->fresh()),
        ];
    }

    /**
     * When a member claims a user account, drop the shared member photo
     * (user owns their profile avatar going forward).
     */
    public function clearMemberAvatarOnClaim(FamilyMember $member): void
    {
        if (! filled($member->avatar_thumb_key) && ! filled($member->avatar_master_key)) {
            return;
        }

        $oldBytes = (int) $member->avatar_master_bytes + (int) $member->avatar_thumb_bytes;
        $uploaderId = $member->avatar_updated_by_user_id;

        $this->deleteKeys([$member->avatar_master_key, $member->avatar_thumb_key]);

        $member->forceFill([
            'avatar_master_key' => null,
            'avatar_thumb_key' => null,
            'avatar_master_bytes' => 0,
            'avatar_thumb_bytes' => 0,
            'avatar_updated_at' => null,
            'avatar_updated_by_user_id' => null,
        ])->save();

        if ($oldBytes > 0 && $uploaderId) {
            $uploader = User::query()->find($uploaderId);
            if ($uploader) {
                $this->quota->removeStoredUsage($uploader, $oldBytes);
            }
        }
    }

    public function stream(string $subjectType, string $subjectUuid, string $variant): StreamedResponse
    {
        [$key, $bytes] = $this->resolveObject($subjectType, $subjectUuid, $variant);
        if ($key === null || ! $this->disk()->exists($key)) {
            abort(404, 'Avatar not found.');
        }

        $mime = $this->guessMime($key);

        return response()->stream(function () use ($key): void {
            $stream = $this->disk()->readStream($key);
            if ($stream === false) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) ($bytes ?: $this->disk()->size($key)),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function assertCanEditMemberAvatar(User $actor, FamilyMember $member): void
    {
        $actorMember = FamilyMember::query()->where('user_id', $actor->id)->first();
        if (! $actorMember || $actorMember->family_uuid !== $member->family_uuid) {
            throw ValidationException::withMessages([
                'avatar' => ['You can only update avatars in your own family.'],
            ]);
        }

        // Registered members upload via /profile/avatar only.
        if ($member->user_id !== null) {
            throw ValidationException::withMessages([
                'avatar' => ['This member has an account. They must update their own profile photo.'],
            ]);
        }
    }

    /** @return array{thumb_url: ?string, master_url: ?string, updated_at: ?string} */
    private function payloadFor(
        string $subjectType,
        string $subjectUuid,
        ?string $thumbKey,
        ?string $masterKey,
        ?string $updatedAt,
    ): array {
        if (! filled($thumbKey)) {
            return [
                'thumb_url' => null,
                'master_url' => null,
                'updated_at' => null,
            ];
        }

        return [
            'thumb_url' => $this->publicApiUrl($subjectType, $subjectUuid, 'thumb'),
            'master_url' => filled($masterKey)
                ? $this->publicApiUrl($subjectType, $subjectUuid, 'master')
                : null,
            'updated_at' => $updatedAt,
        ];
    }

    private function publicApiUrl(string $subjectType, string $subjectUuid, string $variant): string
    {
        return url('/api/v1/avatars/'.$subjectType.'/'.$subjectUuid.'/'.$variant);
    }

    private function objectKey(string $scope, string $uuid, string $variant, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $prefix = rtrim((string) config('avatars.key_prefix'), '/');

        return $prefix.'/'.$scope.'/'.$uuid.'/'.$variant.'-'.Str::lower(Str::random(8)).'.'.$ext;
    }

    private function assertImagePair(UploadedFile $master, UploadedFile $thumb): void
    {
        foreach (['master' => $master, 'thumb' => $thumb] as $label => $file) {
            if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
                throw ValidationException::withMessages([
                    $label => ['Avatar must be an image file.'],
                ]);
            }
        }

        $maxMaster = (int) config('avatars.max_master_bytes');
        $maxThumb = (int) config('avatars.max_thumb_bytes');

        if ($master->getSize() > $maxMaster) {
            throw ValidationException::withMessages([
                'master' => ['Avatar master image is too large.'],
            ]);
        }

        if ($thumb->getSize() > $maxThumb) {
            throw ValidationException::withMessages([
                'thumb' => ['Avatar thumbnail is too large.'],
            ]);
        }
    }

    /** @param  list<?string>  $keys */
    private function deleteKeys(array $keys, array $keep = []): void
    {
        $disk = $this->disk();
        foreach ($keys as $key) {
            if (! filled($key) || in_array($key, $keep, true)) {
                continue;
            }
            if ($disk->exists($key)) {
                $disk->delete($key);
            }
        }
    }

    /** @return array{0: ?string, 1: int} */
    private function resolveObject(string $subjectType, string $subjectUuid, string $variant): array
    {
        $variant = $variant === 'master' ? 'master' : 'thumb';

        if ($subjectType === 'users') {
            $user = User::query()->where('uuid', $subjectUuid)->firstOrFail();

            return $variant === 'master'
                ? [$user->avatar_master_key, (int) $user->avatar_master_bytes]
                : [$user->avatar_thumb_key, (int) $user->avatar_thumb_bytes];
        }

        $member = FamilyMember::query()->where('uuid', $subjectUuid)->firstOrFail();
        // Prefer user avatar when linked.
        if ($member->user_id) {
            $member->loadMissing('user');
            if ($member->user && filled($member->user->avatar_thumb_key)) {
                return $variant === 'master'
                    ? [$member->user->avatar_master_key, (int) $member->user->avatar_master_bytes]
                    : [$member->user->avatar_thumb_key, (int) $member->user->avatar_thumb_bytes];
            }
        }

        return $variant === 'master'
            ? [$member->avatar_master_key, (int) $member->avatar_master_bytes]
            : [$member->avatar_thumb_key, (int) $member->avatar_thumb_bytes];
    }

    private function guessMime(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('avatars.disk'));
    }
}
