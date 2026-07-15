<?php

namespace App\Listeners;

use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\FamilyTree\Events\FamilyMemberJoined;

/** Runs inline so family-join pushes work even when a queue worker is not running. */
class SendFamilyJoinPushNotification
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    public function handle(FamilyMemberJoined $event): void
    {
        $this->pushNotifications->notifyFamilyMemberJoined(
            $event->joinedUser,
            $event->family,
            $event->member,
        );
    }
}
