<?php

namespace App\Modules\Devices\Services;

use App\Models\FamilyMember;
use App\Models\Message;
use App\Models\User;
use App\Modules\Connections\Services\ConnectionService;
use App\Modules\Groups\Services\GroupService;
use App\Modules\Media\Services\MediaShareInboxService;

class PushNotificationService
{
    public function __construct(
        private readonly DevicePushTokenService $tokenService,
        private readonly FcmClient $fcmClient,
        private readonly GroupService $groupService,
        private readonly MediaShareInboxService $shareInboxService,
    ) {}

    /**
     * @param  list<string>  $mentionedUserUuids
     */
    public function notifyNewMessage(Message $message, array $mentionedUserUuids = []): void
    {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        $message->loadMissing(['sender:id,uuid,display_name', 'group:uuid,type,name']);

        $recipients = $this->groupService->groupMemberUsersExcept(
            $message->group_uuid,
            $message->sender_user_id,
        );

        $mentioned = array_fill_keys($mentionedUserUuids, true);

        foreach ($recipients as $recipient) {
            $isMentioned = isset($mentioned[(string) $recipient->uuid]);
            $this->notifyUserAboutMessage($recipient, $message, $isMentioned);
        }
    }

    public function notifyFamilyMemberJoined(User $joinedUser, \App\Models\Family $family, FamilyMember $member): void
    {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        $memberName = trim($member->first_name.' '.$member->last_name);
        if ($memberName === '') {
            $memberName = $joinedUser->display_name ?: 'Someone';
        }

        $recipients = FamilyMember::query()
            ->where('family_uuid', $family->uuid)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $joinedUser->id)
            ->with('user:id,uuid,display_name')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($recipients as $recipient) {
            $tokens = $this->tokenService->tokensForUser($recipient);
            if ($tokens === []) {
                continue;
            }

            foreach ($tokens as $token) {
                $this->fcmClient->send(
                    $token,
                    'New family member',
                    "{$memberName} joined your family tree",
                    [
                        'type' => 'family.member_joined',
                        'family_uuid' => $family->uuid,
                        'member_uuid' => $member->uuid,
                        'route' => '/family-tree',
                    ],
                    0,
                );
            }
        }
    }

    public function notifyMediaShared(
        User $sharer,
        User $recipient,
        string $mediaUuid,
        string $access,
        string $displayName,
    ): void {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        if ((int) $sharer->id === (int) $recipient->id) {
            return;
        }

        $tokens = $this->tokenService->tokensForUser($recipient);
        if ($tokens === []) {
            return;
        }

        $sharerName = $sharer->display_name ?: 'Someone';
        $fileLabel = trim($displayName) !== '' ? trim($displayName) : 'a file';
        $body = $access === 'owner'
            ? "{$sharerName} shared {$fileLabel} to your storage"
            : "{$sharerName} shared {$fileLabel} with you";

        $unreadCount = $this->shareInboxService->unreadCountForUser($recipient);

        foreach ($tokens as $token) {
            $this->fcmClient->send(
                $token,
                'Media shared',
                $body,
                [
                    'type' => 'media.shared',
                    'media_uuid' => $mediaUuid,
                    'access' => $access,
                    'sharer_uuid' => (string) $sharer->uuid,
                    'unread_count' => (string) $unreadCount,
                    'route' => '/gallery',
                ],
                0,
            );
        }
    }

    public function notifyConnectionUpdated(
        User $actor,
        User $recipient,
        string $connectionUuid,
        string $action,
        string $status,
    ): void {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        if ((int) $actor->id === (int) $recipient->id) {
            return;
        }

        // OS alerts are most useful for incoming requests; other actions refresh in-app via Reverb.
        if ($action !== 'request_sent') {
            return;
        }

        $tokens = $this->tokenService->tokensForUser($recipient);
        if ($tokens === []) {
            return;
        }

        $actorName = $actor->display_name ?: 'Someone';
        $pendingCount = app(ConnectionService::class)->pendingReceivedCount($recipient);

        foreach ($tokens as $token) {
            $this->fcmClient->send(
                $token,
                'Connection request',
                "{$actorName} wants to connect with you",
                [
                    'type' => 'connection.updated',
                    'action' => $action,
                    'connection_uuid' => $connectionUuid,
                    'status' => $status,
                    'actor_uuid' => (string) $actor->uuid,
                    'pending_received_count' => (string) $pendingCount,
                    'route' => '/connections',
                ],
                $pendingCount,
            );
        }
    }

    public function notifyAccessUsageWarning(User $user, string $message): void
    {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        $tokens = $this->tokenService->tokensForUser($user);
        if ($tokens === []) {
            return;
        }

        foreach ($tokens as $token) {
            $this->fcmClient->send(
                $token,
                'Storage',
                $message,
                [
                    'type' => 'storage.access_warning',
                    'route' => '/storage/plans',
                ],
                0,
            );
        }
    }

    private function notifyUserAboutMessage(
        User $recipient,
        Message $message,
        bool $mentioned = false,
    ): void {
        $tokens = $this->tokenService->tokensForUser($recipient);
        if ($tokens === []) {
            return;
        }

        $badge = $this->groupService->totalUnreadCountForUser($recipient);
        $senderName = $message->sender?->display_name ?? 'Someone';
        $preview = match ($message->type) {
            'media_reference' => 'Sent an attachment',
            'system' => 'System message',
            default => $mentioned ? 'Mentioned you' : 'New message',
        };
        $title = $mentioned ? "$senderName mentioned you" : $senderName;

        foreach ($tokens as $token) {
            $this->fcmClient->send(
                $token,
                $title,
                $preview,
                [
                    'type' => 'message.sent',
                    'group_uuid' => $message->group_uuid,
                    'message_uuid' => $message->uuid,
                    'badge' => (string) $badge,
                    'mentioned' => $mentioned ? '1' : '0',
                    'route' => '/groups/'.$message->group_uuid.'/chat',
                ],
                $badge,
                'chat_'.$message->group_uuid,
            );
        }
    }
}
