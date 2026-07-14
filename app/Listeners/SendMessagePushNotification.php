<?php

namespace App\Listeners;

use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\Groups\Events\MessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendMessagePushNotification implements ShouldQueue
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    public function handle(MessageSent $event): void
    {
        $this->pushNotifications->notifyNewMessage($event->message);
    }
}
