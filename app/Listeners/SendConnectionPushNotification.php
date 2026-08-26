<?php

namespace App\Listeners;

use App\Modules\Connections\Events\ConnectionUpdated;
use App\Modules\Devices\Services\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendConnectionPushNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

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
