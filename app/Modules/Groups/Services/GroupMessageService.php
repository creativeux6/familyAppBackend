<?php

namespace App\Modules\Groups\Services;

use App\Models\GroupMember;
use App\Models\GroupEncryptionGeneration;
use App\Models\Message;
use App\Models\User;
use App\Modules\Groups\Events\GroupReadUpdated;
use App\Modules\Groups\Events\MessageDeleted;
use App\Modules\Groups\Events\MessageReactionsUpdated;
use App\Modules\Groups\Events\MessageSent;
use App\Modules\Groups\Events\MessageUpdated;
use App\Modules\Groups\Http\Requests\ToggleMessageReactionRequest;
use App\Models\MessageReaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        $with = ['sender:id,uuid,display_name'];
        if ($this->messageSupportsReactions()) {
            $with[] = 'reactions.user:id,uuid';
        }

        $query = Message::query()
            ->withTrashed()
            ->where('group_uuid', $groupUuid)
            ->with($with)
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
        ?string $clientMessageId = null,
    ): array {
        $group = $this->groupService->requireGroupMember($user, $groupUuid);

        if ($clientMessageId) {
            $existing = Message::query()
                ->where('group_uuid', $group->uuid)
                ->where('sender_user_id', $user->id)
                ->where('client_message_id', $clientMessageId)
                ->with('sender:id,uuid,display_name')
                ->first();

            if ($existing) {
                return $this->formatMessage($existing);
            }
        }

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

        try {
            $message = $this->createMessageWithRetry([
                'uuid' => (string) Str::uuid(),
                'client_message_id' => $clientMessageId,
                'group_uuid' => $group->uuid,
                'sender_user_id' => $user->id,
                'encryption_generation' => $encryptionGeneration,
                'ciphertext' => $ciphertext,
                'nonce' => $nonce,
                'encryption_version' => $encryptionVersion,
                'type' => $type,
                'media_file_uuid' => $mediaFileUuid,
            ]);
        } catch (QueryException $e) {
            if ($clientMessageId && $this->isUniqueConstraintViolation($e)) {
                $existing = Message::query()
                    ->where('group_uuid', $group->uuid)
                    ->where('sender_user_id', $user->id)
                    ->where('client_message_id', $clientMessageId)
                    ->with('sender:id,uuid,display_name')
                    ->first();

                if ($existing) {
                    return $this->formatMessage($existing);
                }
            }

            throw $e;
        }

        $message->load('sender:id,uuid,display_name');

        try {
            broadcast(new MessageSent($message));
        } catch (\Throwable $e) {
            Log::warning('Message broadcast failed after durable write', [
                'message_uuid' => $message->uuid,
                'group_uuid' => $message->group_uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->formatMessage($message);
    }

    /** @param array<string, mixed> $attributes */
    private function createMessageWithRetry(array $attributes, int $attempts = 3): Message
    {
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return Message::create($attributes);
            } catch (QueryException $e) {
                $lastException = $e;
                if (! $this->isRetryableLockException($e) || $i === $attempts - 1) {
                    throw $e;
                }
                usleep(50_000 * ($i + 1));
            }
        }

        throw $lastException ?? new \RuntimeException('Failed to create message.');
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return $code === '1062'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    private function isRetryableLockException(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return in_array($code, ['1205', '1213'], true)
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
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

        MessageReaction::query()->where('message_uuid', $messageUuid)->delete();

        broadcast(new MessageDeleted($groupUuid, $messageUuid));

        return ['message' => 'Message deleted.'];
    }

    public function toggleReaction(User $user, string $groupUuid, string $messageUuid, string $emoji): array
    {
        $this->groupService->requireGroupMember($user, $groupUuid);

        if (! $this->messageSupportsReactions()) {
            throw ValidationException::withMessages([
                'emoji' => ['Message reactions are not available yet.'],
            ]);
        }

        if (! in_array($emoji, ToggleMessageReactionRequest::ALLOWED_EMOJIS, true)) {
            throw ValidationException::withMessages([
                'emoji' => ['That reaction is not supported.'],
            ]);
        }

        $message = Message::query()
            ->where('group_uuid', $groupUuid)
            ->where('uuid', $messageUuid)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages([
                'message_uuid' => ['Message not found.'],
            ]);
        }

        if ($message->trashed()) {
            throw ValidationException::withMessages([
                'message_uuid' => ['You cannot react to a deleted message.'],
            ]);
        }

        $existing = MessageReaction::query()
            ->where('message_uuid', $messageUuid)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->emoji === $emoji) {
            $existing->delete();
        } elseif ($existing) {
            $existing->update(['emoji' => $emoji]);
        } else {
            MessageReaction::query()->create([
                'message_uuid' => $messageUuid,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
        }

        $message->load(['reactions.user:id,uuid']);
        $reactions = $this->formatReactions($message, $user);

        try {
            broadcast(new MessageReactionsUpdated(
                $groupUuid,
                $messageUuid,
                $this->formatReactions($message, null),
            ));
        } catch (\Throwable $e) {
            Log::warning('Message reaction broadcast failed', [
                'message_uuid' => $messageUuid,
                'group_uuid' => $groupUuid,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'message_uuid' => $messageUuid,
            'group_uuid' => $groupUuid,
            'reactions' => $reactions,
        ];
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
            'reactions' => $isDeleted ? [] : $this->formatReactions($message, $viewer),
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

    /**
     * @return array<int, array{emoji: string, count: int, reacted_by_me: bool, reactor_user_uuids: list<string>}>
     */
    public function formatReactions(Message $message, ?User $viewer): array
    {
        if (! $this->messageSupportsReactions()) {
            return [];
        }

        try {
            if (! $message->relationLoaded('reactions')) {
                $message->load(['reactions.user:id,uuid']);
            }

            $grouped = $message->reactions
                ->groupBy('emoji')
                ->sortKeys();

            $viewerUuid = $viewer?->uuid;
            $formatted = [];

            foreach ($grouped as $emoji => $rows) {
                $reactorUuids = $rows
                    ->map(fn (MessageReaction $reaction) => $reaction->user?->uuid)
                    ->filter()
                    ->values()
                    ->all();

                $formatted[] = [
                    'emoji' => (string) $emoji,
                    'count' => $rows->count(),
                    'reacted_by_me' => $viewerUuid !== null && in_array($viewerUuid, $reactorUuids, true),
                    'reactor_user_uuids' => $reactorUuids,
                ];
            }

            usort($formatted, function (array $a, array $b) {
                if ($a['count'] === $b['count']) {
                    return strcmp($a['emoji'], $b['emoji']);
                }

                return $b['count'] <=> $a['count'];
            });

            return $formatted;
        } catch (\Throwable $e) {
            Log::warning('Failed to format message reactions', [
                'message_uuid' => $message->uuid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function messageSupportsReactions(): bool
    {
        if (! method_exists(Message::class, 'reactions')) {
            return false;
        }

        try {
            return Schema::hasTable('message_reactions');
        } catch (\Throwable) {
            return false;
        }
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
