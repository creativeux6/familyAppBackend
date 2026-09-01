<?php

namespace App\Modules\Account\Services;

use App\Models\FamilyMember;
use App\Models\Group;
use App\Models\GroupEncryptionGeneration;
use App\Models\GroupMember;
use App\Models\MediaFile;
use App\Models\MediaOwnershipTransfer;
use App\Models\MediaPermission;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Modules\Avatars\Services\AvatarService;
use App\Modules\Media\Services\MediaChunkedUploadService;
use App\Modules\Media\Services\MediaStreamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountDeletionService
{
    public function __construct(
        private readonly AvatarService $avatars,
    ) {}

    public function delete(User $user, string $confirmation): void
    {
        $expected = strtolower(trim($confirmation));
        if (! in_array($expected, ['delete', 'delete my account'], true)) {
            throw ValidationException::withMessages([
                'confirmation' => ['Type DELETE to permanently remove your account.'],
            ]);
        }

        DB::transaction(function () use ($user) {
            $userId = (int) $user->id;

            $this->purgeOwnedMedia($user);
            $this->avatars->deleteUserAvatar($user);

            FamilyMember::query()
                ->where('user_id', $userId)
                ->update(['user_id' => null]);

            FamilyMember::query()
                ->where('avatar_updated_by_user_id', $userId)
                ->update(['avatar_updated_by_user_id' => null]);

            $this->reassignOrDeleteGroups($userId);

            GroupEncryptionGeneration::query()
                ->where('created_by_user_id', $userId)
                ->get()
                ->each(function (GroupEncryptionGeneration $generation) use ($userId): void {
                    $other = GroupMember::query()
                        ->where('group_uuid', $generation->group_uuid)
                        ->where('user_id', '!=', $userId)
                        ->first();
                    if ($other) {
                        $generation->update(['created_by_user_id' => $other->user_id]);
                    } else {
                        $generation->delete();
                    }
                });

            MediaFile::query()
                ->where('uploaded_by_user_id', $userId)
                ->update(['uploaded_by_user_id' => DB::raw('owner_user_id')]);

            MediaPermission::query()->where('granted_by_user_id', $userId)->delete();
            MediaOwnershipTransfer::query()
                ->where(function ($q) use ($userId) {
                    $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId);
                })
                ->delete();

            PaymentTransaction::query()->where('user_id', $userId)->delete();

            if (method_exists($user, 'roles')) {
                $user->roles()->detach();
                $user->permissions()->detach();
            }

            $user->tokens()->delete();
            $user->forceDelete();
        });
    }

    private function purgeOwnedMedia(User $user): void
    {
        $disk = Storage::disk((string) config('media.disk'));
        $files = MediaFile::query()
            ->withTrashed()
            ->where('owner_user_id', $user->id)
            ->get();

        foreach ($files as $media) {
            try {
                if (filled($media->s3_key)) {
                    $disk->delete($media->s3_key);
                }
                if ($media->hasThumbnail()) {
                    $disk->delete($media->thumbnail_s3_key);
                }
                app(MediaStreamService::class)->deleteStreamPackage($media);
                if ($media->status === 'pending_upload') {
                    app(MediaChunkedUploadService::class)->cleanupPartialUpload($media);
                }
            } catch (\Throwable) {
                // Continue deleting account even if a blob is already gone.
            }

            $media->forceDelete();
        }
    }

    private function reassignOrDeleteGroups(int $userId): void
    {
        Group::query()
            ->withTrashed()
            ->where('created_by_user_id', $userId)
            ->get()
            ->each(function (Group $group) use ($userId): void {
                $other = GroupMember::query()
                    ->where('group_uuid', $group->uuid)
                    ->where('user_id', '!=', $userId)
                    ->first();
                if ($other) {
                    $group->update(['created_by_user_id' => $other->user_id]);
                } else {
                    $group->forceDelete();
                }
            });
    }
}
