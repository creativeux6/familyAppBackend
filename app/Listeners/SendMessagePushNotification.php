<?php

namespace App\Listeners;

use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\Groups\Events\MessageSent;

/** Runs inline so chat pushes work even when a queue worker is not running. */
class SendMessagePushNotification
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    public function handle(MessageSent $event): void
    {
        $this->pushNotifications->notifyNewMessage($event->message);
    }
}
