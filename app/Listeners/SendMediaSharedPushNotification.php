<?php

namespace App\Listeners;

use App\Modules\Devices\Services\PushNotificationService;
use App\Modules\Media\Events\MediaSharedWithUser;

/** Runs inline so media pushes work even when a queue worker is not running. */
class SendMediaSharedPushNotification
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    public function handle(MediaSharedWithUser $event): void
    {
        $this->pushNotifications->notifyMediaShared(
            sharer: $event->sharer,
            recipient: $event->recipient,
            mediaUuid: $event->mediaUuid,
            access: $event->access,
            displayName: $event->displayName,
        );
    }
}
