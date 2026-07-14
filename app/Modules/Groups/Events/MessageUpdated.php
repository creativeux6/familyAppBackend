<?php

namespace App\Modules\Groups\Events;

use App\Models\GroupMember;
use App\Models\Message;
use App\Modules\Groups\Services\GroupMessageService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.'.$this->message->group_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('sender:id,uuid,display_name');

        return app(GroupMessageService::class)->formatMessage($this->message);
    }
}
