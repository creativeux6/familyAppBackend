<?php

namespace App\Modules\Groups\Services;

use App\Models\GroupMember;
use App\Models\GroupEncryptionGeneration;
use App\Models\Message;
use App\Models\User;
use App\Modules\Groups\Events\GroupReadUpdated;
use App\Modules\Groups\Events\MessageDeleted;
use App\Modules\Groups\Events\MessageSent;
use App\Modules\Groups\Events\MessageUpdated;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupMessageService
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {}

    public function list(User $user, string $groupUuid, ?string $cursor, int $limit = 30): array
    {
        $this->groupService->requireGroupMember($user, $groupUuid);
        $limit = max(1, min(50, $limit));

        $members = GroupMember::query()
            ->where('group_uuid', $groupUuid)
            ->with('user:id,uuid,display_name')
            ->get();

        $query = Message::query()
            ->withTrashed()
            ->where('group_uuid', $groupUuid)
            ->with('sender:id,uuid,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('uuid');

        if ($cursor) {
            $query->where('uuid', '<', $cursor);
        }

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $items = $messages->take($limit);

        return [
            'messages' => $items
                ->map(fn (Message $message) => $this->formatMessage($message, $members, $user))
                ->values()
                ->all(),
            'next_cursor' => $hasMore ? $items->last()?->uuid : null,
            'read_state' => $this->formatReadState($members),
        ];
    }

    public function send(
        User $user,
        string $groupUuid,
        string $ciphertextBase64,
        string $nonceBase64,
        int $encryptionGeneration,
        int $encryptionVersion = 1,
        string $type = 'text',
        ?string $mediaFileUuid = null,
    ): array {
        $group = $this->groupService->requireGroupMember($user, $groupUuid);

        $generationExists = GroupEncryptionGeneration::query()
            ->where('group_uuid', $group->uuid)
            ->where('generation', $encryptionGeneration)
            ->exists();

        if (! $generationExists) {
            throw ValidationException::withMessages([
                'encryption_generation' => ['Unknown encryption generation for this group.'],
            ]);
        }

        $ciphertext = base64_decode($ciphertextBase64, true);
        $nonce = base64_decode($nonceBase64, true);

        if ($ciphertext === false || $nonce === false || $ciphertext === '' || $nonce === '') {
            throw ValidationException::withMessages([
                'ciphertext' => ['Invalid base64 ciphertext or nonce.'],
            ]);
        }

        $message = Message::create([
            'uuid' => (string) Str::uuid(),
            'group_uuid' => $group->uuid,
            'sender_user_id' => $user->id,
            'encryption_generation' => $encryptionGeneration,
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'encryption_version' => $encryptionVersion,
            'type' => $type,
            'media_file_uuid' => $mediaFileUuid,
        ]);

        $message->load('sender:id,uuid,display_name');

        broadcast(new MessageSent($message));

        return $this->formatMessage($message);
    }

    public function markRead(User $user, string $groupUuid, ?string $messageUuid = null): array
    {
        $this->groupService->requireGroupMember($user, $groupUuid);

        $membership = GroupMember::query()
            ->where('group_uuid', $groupUuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $latestMessage = null;

        if ($messageUuid) {
            $latestMessage = Message::query()
                ->where('group_uuid', $groupUuid)
                ->where('uuid', $messageUuid)
                ->first();

            if (! $latestMessage) {
                throw ValidationException::withMessages([
                    'message_uuid' => ['Message not found in this group.'],
                ]);
            }
        } else {
            $latestMessage = Message::query()
                ->where('group_uuid', $groupUuid)
                ->orderByDesc('created_at')
                ->orderByDesc('uuid')
                ->first();
        }

        if (! $latestMessage) {
            return [
                'last_read_at' => $membership->last_read_at?->toIso8601String(),
                'last_read_message_uuid' => $membership->last_read_message_uuid,
            ];
        }

        $shouldUpdate = $membership->last_read_at === null
            || $latestMessage->created_at->greaterThan($membership->last_read_at);

        if ($shouldUpdate) {
            $membership->update([
                'last_read_at' => now(),
                'last_read_message_uuid' => $latestMessage->uuid,
            ]);

            broadcast(new GroupReadUpdated($membership->fresh(['user:id,uuid,display_name'])));
        }

        return [
            'last_read_at' => $membership->fresh()->last_read_at?->toIso8601String(),
            'last_read_message_uuid' => $membership->fresh()->last_read_message_uuid,
        ];
    }

    public function update(
        User $user,
        string $groupUuid,
        string $messageUuid,
        string $ciphertextBase64,
        string $nonceBase64,
    ): array {
        $this->groupService->requireGroupMember($user, $groupUuid);

        $message = Message::query()
            ->where('group_uuid', $groupUuid)
            ->where('uuid', $messageUuid)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages([
                'message_uuid' => ['Message not found.'],
            ]);
        }

        if ($message->sender_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'message_uuid' => ['You can only edit your own messages.'],
            ]);
        }

        if ($message->trashed()) {
            throw ValidationException::withMessages([
                'message_uuid' => ['Deleted messages cannot be edited.'],
            ]);
        }

        $ciphertext = base64_decode($ciphertextBase64, true);
        $nonce = base64_decode($nonceBase64, true);

        if ($ciphertext === false || $nonce === false || $ciphertext === '' || $nonce === '') {
            throw ValidationException::withMessages([
                'ciphertext' => ['Invalid base64 ciphertext or nonce.'],
            ]);
        }

        $message->update([
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'edited_at' => now(),
        ]);

        $message->load('sender:id,uuid,display_name');

        broadcast(new MessageUpdated($message->fresh()));

        return $this->formatMessage($message->fresh());
    }

    public function delete(User $user, string $groupUuid, string $messageUuid): array
    {
        $this->groupService->requireGroupMember($user, $groupUuid);

        $message = Message::query()
            ->where('group_uuid', $groupUuid)
            ->where('uuid', $messageUuid)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages([
                'message_uuid' => ['Message not found.'],
            ]);
        }

        if ($message->sender_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'message_uuid' => ['You can only delete your own messages.'],
            ]);
        }

        $message->delete();

        broadcast(new MessageDeleted($groupUuid, $messageUuid));

        return ['message' => 'Message deleted.'];
    }

    public function unreadCountForMember(string $groupUuid, GroupMember $membership): int
    {
        $query = Message::query()
            ->where('group_uuid', $groupUuid)
            ->where('sender_user_id', '!=', $membership->user_id);

        if ($membership->last_read_at) {
            $query->where('created_at', '>', $membership->last_read_at);
        }

        return $query->count();
    }

    /** @return array<string, mixed>|null */
    public function latestMessageSummary(string $groupUuid): ?array
    {
        $message = Message::query()
            ->where('group_uuid', $groupUuid)
            ->with('sender:id,uuid,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('uuid')
            ->first();

        if (! $message) {
            return null;
        }

        return [
            'uuid' => $message->uuid,
            'sender_display_name' => $message->sender->display_name,
            'type' => $message->type,
            'is_deleted' => $message->trashed(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, GroupMember>  $members
     * @return array<string, mixed>
     */
    public function formatMessage(
        Message $message,
        ?Collection $members = null,
        ?User $viewer = null,
    ): array {
        $isDeleted = $message->trashed();
        $readBy = [];
        $readCount = 0;
        $otherMemberCount = 0;

        if ($members !== null) {
            foreach ($members as $member) {
                if ($member->user_id === $message->sender_user_id) {
                    continue;
                }

                $otherMemberCount++;

                if ($member->last_read_at && $message->created_at
                    && $member->last_read_at->greaterThanOrEqualTo($message->created_at)) {
                    $readCount++;
                    $readBy[] = [
                        'user_uuid' => $member->user->uuid,
                        'display_name' => $member->user->display_name,
                        'read_at' => $member->last_read_at->toIso8601String(),
                    ];
                }
            }
        }

        $payload = [
            'uuid' => $message->uuid,
            'group_uuid' => $message->group_uuid,
            'sender_user_uuid' => $message->sender->uuid,
            'sender_display_name' => $message->sender->display_name,
            'encryption_generation' => $message->encryption_generation,
            'encryption_version' => $message->encryption_version,
            'type' => $message->type,
            'media_file_uuid' => $message->media_file_uuid,
            'created_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'is_deleted' => $isDeleted,
            'read_count' => $readCount,
            'other_member_count' => $otherMemberCount,
            'read_by' => $readBy,
        ];

        if ($isDeleted) {
            $payload['ciphertext'] = null;
            $payload['nonce'] = null;
        } else {
            $payload['ciphertext'] = base64_encode($message->ciphertext);
            $payload['nonce'] = base64_encode($message->nonce);
        }

        return $payload;
    }

    /** @param  Collection<int, GroupMember>  $members */
    private function formatReadState(Collection $members): array
    {
        return $members->map(fn (GroupMember $member) => [
            'user_uuid' => $member->user->uuid,
            'display_name' => $member->user->display_name,
            'last_read_at' => $member->last_read_at?->toIso8601String(),
            'last_read_message_uuid' => $member->last_read_message_uuid,
        ])->values()->all();
    }
}
