<?php

namespace App\Modules\Media\Events;

use App\Models\User;
use App\Modules\Media\Services\MediaShareInboxService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaSharedWithUser implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly User $sharer,
        public readonly User $recipient,
        public readonly string $mediaUuid,
        public readonly string $access,
        public readonly string $displayName,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->recipient->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'media.shared';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => 'media.shared',
            'media_uuid' => $this->mediaUuid,
            'access' => $this->access,
            'display_name' => $this->displayName,
            'sharer_uuid' => $this->sharer->uuid,
            'sharer_display_name' => $this->sharer->display_name,
            'unread_count' => app(MediaShareInboxService::class)
                ->unreadCountForUser($this->recipient),
        ];
    }
}
