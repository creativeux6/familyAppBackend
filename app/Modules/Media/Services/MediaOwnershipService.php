<?php

namespace App\Modules\Media\Services;

use App\Models\MediaFile;
use App\Models\MediaOwnershipTransfer;
use App\Models\User;
use App\Modules\Groups\Services\ConnectedMemberGuard;
use App\Modules\StoragePlans\Services\StorageQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaOwnershipService
{
    public function __construct(
        private readonly MediaAccessService $accessService,
        private readonly ConnectedMemberGuard $connectedMemberGuard,
        private readonly StorageQuotaService $quotaService,
    ) {}

    public function initiate(User $owner, string $mediaUuid, string $toUserUuid): array
    {
        $media = $this->accessService->requireMedia($mediaUuid);
        $this->accessService->assertOwner($owner, $media);

        if ($media->status !== 'active') {
            throw ValidationException::withMessages([
                'media' => ['Only active files can be transferred.'],
            ]);
        }

        $recipient = User::query()->where('uuid', $toUserUuid)->firstOrFail();

        if (! $this->connectedMemberGuard->areConnected($owner, $recipient)) {
            throw ValidationException::withMessages([
                'to_user_uuid' => ['You can only transfer to connected family members.'],
            ]);
        }

        $pending = MediaOwnershipTransfer::query()
            ->where('media_file_uuid', $media->uuid)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'media' => ['A transfer is already pending for this file.'],
            ]);
        }

        $transfer = MediaOwnershipTransfer::create([
            'uuid' => (string) Str::uuid(),
            'media_file_uuid' => $media->uuid,
            'from_user_id' => $owner->id,
            'to_user_id' => $recipient->id,
            'status' => 'pending',
            'size_bytes' => $media->size_bytes,
        ]);

        return $this->formatTransfer($transfer);
    }

    public function accept(User $recipient, string $transferUuid): array
    {
        $transfer = $this->findTransferForRecipient($recipient, $transferUuid);

        $this->quotaService->assertCanStore($recipient, (int) $transfer->size_bytes);

        return DB::transaction(function () use ($recipient, $transfer) {
            /** @var MediaFile $media */
            $media = MediaFile::query()->lockForUpdate()->findOrFail($transfer->media_file_uuid);

            $owner = User::query()->lockForUpdate()->findOrFail($transfer->from_user_id);

            $this->quotaService->removeUsage($owner, (int) $media->size_bytes);
            $this->quotaService->addUsage($recipient, (int) $media->size_bytes);

            $media->update(['owner_user_id' => $recipient->id]);

            $transfer->update([
                'status' => 'accepted',
                'responded_at' => now(),
            ]);

            return [
                'message' => 'Ownership transferred.',
                'media_uuid' => $media->uuid,
                'new_owner_uuid' => $recipient->uuid,
            ];
        });
    }

    public function decline(User $recipient, string $transferUuid): array
    {
        $transfer = $this->findTransferForRecipient($recipient, $transferUuid);
        $transfer->update(['status' => 'declined', 'responded_at' => now()]);

        return ['message' => 'Transfer declined.'];
    }

    public function cancel(User $owner, string $transferUuid): array
    {
        $transfer = MediaOwnershipTransfer::query()
            ->where('uuid', $transferUuid)
            ->where('from_user_id', $owner->id)
            ->where('status', 'pending')
            ->first();

        if (! $transfer) {
            throw ValidationException::withMessages([
                'transfer' => ['Pending transfer not found.'],
            ]);
        }

        $transfer->update(['status' => 'cancelled', 'responded_at' => now()]);

        return ['message' => 'Transfer cancelled.'];
    }

    private function findTransferForRecipient(User $recipient, string $transferUuid): MediaOwnershipTransfer
    {
        $transfer = MediaOwnershipTransfer::query()
            ->where('uuid', $transferUuid)
            ->where('to_user_id', $recipient->id)
            ->where('status', 'pending')
            ->first();

        if (! $transfer) {
            throw ValidationException::withMessages([
                'transfer' => ['Pending transfer not found.'],
            ]);
        }

        return $transfer;
    }

    /** @return array<string, mixed> */
    private function formatTransfer(MediaOwnershipTransfer $transfer): array
    {
        return [
            'uuid' => $transfer->uuid,
            'media_file_uuid' => $transfer->media_file_uuid,
            'status' => $transfer->status,
            'size_bytes' => $transfer->size_bytes,
        ];
    }
}
