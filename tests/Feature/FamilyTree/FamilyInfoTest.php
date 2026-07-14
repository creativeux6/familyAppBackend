<?php

namespace Tests\Feature\FamilyTree;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class FamilyInfoTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_user_can_update_mother_in_family_info(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $response = $this->patchJson('/api/v1/family-tree/family-info', [
            'mother' => [
                'first_name' => 'Fatima',
                'last_name' => 'Khan',
                'gender' => 'female',
                'is_living' => true,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('family_info.mother.first_name', 'Fatima')
            ->assertJsonPath('family_info.mother.last_name', 'Khan');
    }

    public function test_family_info_requires_authentication(): void
    {
        $this->getJson('/api/v1/family-tree/family-info')
            ->assertUnauthorized();
    }
}
