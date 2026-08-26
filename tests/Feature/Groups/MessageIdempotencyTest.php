<?php

namespace Tests\Feature\Groups;

use App\Models\Message;
use App\Modules\Groups\Events\MessageSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class MessageIdempotencyTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_duplicate_client_message_id_returns_same_message(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily(['display_name' => 'Alice']));
        $bob = $this->createUserWithFamily(['display_name' => 'Bob']);
        $this->connectUsers($alice, $bob);

        $groupUuid = $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $bob->uuid,
        ])->assertOk()->json('uuid');

        $clientMessageId = (string) Str::uuid();
        $payload = [
            'ciphertext' => base64_encode('cipher-bytes'),
            'nonce' => base64_encode('nonce-bytes-12'),
            'encryption_generation' => 1,
            'client_message_id' => $clientMessageId,
        ];

        Event::fake([MessageSent::class]);

        $first = $this->postJson("/api/v1/groups/{$groupUuid}/messages", $payload);
        $first->assertCreated();

        $second = $this->postJson("/api/v1/groups/{$groupUuid}/messages", $payload);
        $second->assertCreated();

        $this->assertSame($first->json('uuid'), $second->json('uuid'));
        $this->assertSame(1, Message::query()->count());
        $this->assertSame(
            $clientMessageId,
            Message::query()->first()->client_message_id,
        );
    }

    public function test_send_still_succeeds_when_broadcast_event_is_faked(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily());
        $bob = $this->createUserWithFamily();
        $this->connectUsers($alice, $bob);

        $groupUuid = $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $bob->uuid,
        ])->assertOk()->json('uuid');

        Event::fake([MessageSent::class]);

        $this->postJson("/api/v1/groups/{$groupUuid}/messages", [
            'ciphertext' => base64_encode('hello'),
            'nonce' => base64_encode('nonce-bytes-12'),
            'encryption_generation' => 1,
            'client_message_id' => (string) Str::uuid(),
        ])->assertCreated();

        $this->assertSame(1, Message::query()->count());
        Event::assertDispatched(MessageSent::class);
    }
}
