<?php

namespace App\Modules\Groups\Events;

use App\Models\GroupMember;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupDeliveredUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GroupMember $membership,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('group.'.$this->membership->group_uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'group.delivered';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->membership->loadMissing('user:id,uuid,display_name');

        return [
            'group_uuid' => $this->membership->group_uuid,
            'user_uuid' => $this->membership->user->uuid,
            'display_name' => $this->membership->user->display_name,
            'last_delivered_at' => $this->membership->last_delivered_at?->toIso8601String(),
        ];
    }
}
