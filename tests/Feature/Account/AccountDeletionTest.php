<?php

namespace Tests\Feature\Account;

use App\Models\FamilyMember;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['media.disk' => 'local']);
    }

    public function test_user_can_delete_account_and_personal_media(): void
    {
        $pair = $this->createFamilyPair();
        $user = $pair['users']['viewer'];
        $other = $pair['users']['other'];
        $member = $pair['members']['viewer'];

        Storage::disk('local')->put('media/test.bin', 'ciphertext');
        MediaFile::query()->create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            's3_bucket' => 'local',
            's3_key' => 'media/test.bin',
            'display_name' => 'photo.jpg',
            'size_bytes' => 10,
            'mime_type' => 'image/jpeg',
            'checksum_sha256' => hash('sha256', 'ciphertext'),
            'encryption_version' => 1,
            'status' => 'active',
        ]);

        $this->actingAsUser($user);
        $this->deleteJson('/api/v1/account', [
            'confirmation' => 'DELETE',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('family_members', [
            'uuid' => $member->uuid,
            'user_id' => null,
        ]);
        $this->assertSame(0, MediaFile::withTrashed()->where('owner_user_id', $user->id)->count());
        Storage::disk('local')->assertMissing('media/test.bin');
    }

    public function test_deletion_requires_confirmation(): void
    {
        $user = $this->createUserWithFamily();
        $this->actingAsUser($user);

        $this->deleteJson('/api/v1/account', [
            'confirmation' => 'please',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_privacy_and_deletion_pages_are_public(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy', false);
        $this->get('/account-deletion')->assertOk()->assertSee('Delete your', false);
    }

    public function test_web_form_deletes_account(): void
    {
        $user = User::factory()->create([
            'phone' => '+923001110000',
            'password' => 'secret-pass',
        ]);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->from('/account-deletion')
            ->post('/account-deletion', [
                'phone' => '+923001110000',
                'password' => 'secret-pass',
                'confirmation' => 'DELETE',
            ])
            ->assertRedirect('/account-deletion')
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
