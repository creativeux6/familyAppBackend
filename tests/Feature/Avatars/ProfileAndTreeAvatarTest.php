<?php

namespace Tests\Feature\Avatars;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class ProfileAndTreeAvatarTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_profile_includes_user_avatar(): void
    {
        $user = $this->createUserWithFamily();
        $user->forceFill([
            'avatar_thumb_key' => 'tagori/avatars/users/'.$user->uuid.'/thumb-test.jpg',
            'avatar_master_key' => 'tagori/avatars/users/'.$user->uuid.'/master-test.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAsUser($user);

        $response = $this->getJson('/api/v1/profile');
        $response->assertOk();

        $thumb = $response->json('user.avatar.thumb_url');
        $this->assertIsString($thumb);
        $this->assertStringContainsString(
            '/api/v1/avatars/users/'.$user->uuid.'/thumb',
            $thumb,
        );
        $this->assertSame('user', $response->json('member.avatar.source'));
    }

    public function test_family_tree_includes_viewer_user_avatar(): void
    {
        $user = $this->createUserWithFamily();
        $user->forceFill([
            'avatar_thumb_key' => 'tagori/avatars/users/'.$user->uuid.'/thumb-test.jpg',
            'avatar_master_key' => 'tagori/avatars/users/'.$user->uuid.'/master-test.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAsUser($user);

        $response = $this->getJson('/api/v1/family-tree');
        $response->assertOk();

        $self = collect($response->json('members'))
            ->firstWhere('user_uuid', $user->uuid);

        $this->assertNotNull($self);
        $this->assertSame('user', $self['avatar']['source'] ?? null);
        $this->assertNotEmpty($self['avatar']['thumb_url'] ?? null);
        $this->assertStringContainsString('/api/v1/avatars/users/'.$user->uuid.'/thumb', $self['avatar']['thumb_url']);
    }
}
