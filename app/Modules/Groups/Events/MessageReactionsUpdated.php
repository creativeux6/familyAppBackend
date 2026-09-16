<?php

namespace App\Modules\Groups\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReactionsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{emoji: string, count: int, reacted_by_me: bool}>  $reactions
     */
    public function __construct(
        public string $groupUuid,
        public string $messageUuid,
        public array $reactions,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.'.$this->groupUuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.reaction';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'group_uuid' => $this->groupUuid,
            'message_uuid' => $this->messageUuid,
            'reactions' => $this->reactions,
        ];
    }
}
