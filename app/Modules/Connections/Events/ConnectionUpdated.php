<?php

namespace App\Modules\Connections\Events;

use App\Models\Connection;
use App\Models\User;
use App\Modules\Connections\Services\ConnectionService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConnectionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly User $actor,
        public readonly User $notifyUser,
        public readonly Connection $connection,
        public readonly string $action,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->notifyUser->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'connection.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => 'connection.updated',
            'action' => $this->action,
            'connection_uuid' => $this->connection->uuid,
            'status' => $this->connection->status,
            'actor_uuid' => $this->actor->uuid,
            'actor_display_name' => $this->actor->display_name,
            'pending_received_count' => app(ConnectionService::class)
                ->pendingReceivedCount($this->notifyUser),
        ];
    }
}
