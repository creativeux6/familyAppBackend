<?php

namespace App\Listeners;

use App\Modules\Connections\Events\ConnectionUpdated;
use App\Modules\Devices\Services\PushNotificationService;

/** Runs inline so connect pushes work even when a queue worker is not running. */
class SendConnectionPushNotification
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    public function handle(ConnectionUpdated $event): void
    {
        $this->pushNotifications->notifyConnectionUpdated(
            actor: $event->actor,
            recipient: $event->notifyUser,
            connectionUuid: $event->connection->uuid,
            action: $event->action,
            status: $event->connection->status,
        );
    }
}
