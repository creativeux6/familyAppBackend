<?php

namespace App\Listeners;

use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\FamilyTree\Events\FamilyMemberJoined;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendFamilyJoinPushNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

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
