<?php

namespace App\Modules\Devices\Services;

use App\Models\FamilyMember;
use App\Models\Message;
use App\Models\User;
use App\Modules\Groups\Services\GroupService;
use App\Modules\Media\Services\MediaShareInboxService;

class PushNotificationService
{
    public function __construct(
        private readonly DevicePushTokenService $tokenService,
        private readonly FcmClient $fcmClient,
        private readonly GroupService $groupService,
    ) {}

    public function notifyNewMessage(Message $message): void
    {
        if (! $this->fcmClient->isConfigured()) {
            return;
        }

        $message->loadMissing(['sender:id,uuid,display_name', 'group:uuid,type,name']);

        $recipients = $this->groupService->groupMemberUsersExcept(
            $message->group_uuid,
            $message->sender_user_id,
        );

        foreach ($recipients as $recipient) {
            $this->notifyUserAboutMessage($recipient, $message);
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
                ],
                0,
            );
        }
    }

    private function notifyUserAboutMessage(User $recipient, Message $message): void
    {
        $tokens = $this->tokenService->tokensForUser($recipient);
        if ($tokens === []) {
            return;
        }

        $badge = $this->groupService->totalUnreadCountForUser($recipient);
        $senderName = $message->sender?->display_name ?? 'Someone';
        $preview = match ($message->type) {
            'media_reference' => 'Sent an attachment',
            'system' => 'System message',
            default => 'New message',
        };

        foreach ($tokens as $token) {
            $this->fcmClient->send(
                $token,
                $senderName,
                $preview,
                [
                    'type' => 'message.sent',
                    'group_uuid' => $message->group_uuid,
                    'message_uuid' => $message->uuid,
                    'badge' => (string) $badge,
                ],
                $badge,
            );
        }
    }
}
