<?php

namespace Tests\Feature\FamilyTree;

use App\Models\FamilyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class AddFamilyMemberTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_spouse_father_attaches_to_selected_spouse_when_multiple_partners(): void
    {
        $user = $this->createUserWithFamily(['display_name' => 'Ahmed']);
        $self = FamilyMember::query()->where('user_id', $user->id)->firstOrFail();
        $self->update(['first_name' => 'Ahmed', 'last_name' => 'Ali', 'gender' => 'male']);

        $this->actingAsUser($user);

        $setup = $this->patchJson('/api/v1/family-tree/family-info', [
            'has_multiple_partners' => true,
            'spouses' => [
                [
                    'first_name' => 'Sara',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                    'is_living' => true,
                    'father' => [
                        'first_name' => 'Omar',
                        'last_name' => 'Hassan',
                        'gender' => 'male',
                    ],
                ],
                [
                    'first_name' => 'Layla',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                    'is_living' => true,
                ],
            ],
        ]);

        $setup->assertOk();
        $saraUuid = $setup->json('family_info.spouses.0.uuid');
        $laylaUuid = $setup->json('family_info.spouses.1.uuid');

        $response = $this->postJson('/api/v1/family-tree/members', [
            'relation_type' => 'spouse_father',
            'spouse_uuid' => $laylaUuid,
            'first_name' => 'Karim',
            'last_name' => 'Noor',
            'gender' => 'male',
            'is_living' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('member.first_name', 'Karim');

        $info = $this->getJson('/api/v1/family-tree/family-info')->assertOk();
        $info->assertJsonPath('family_info.spouses.0.uuid', $saraUuid)
            ->assertJsonPath('family_info.spouses.0.father.first_name', 'Omar')
            ->assertJsonPath('family_info.spouses.1.uuid', $laylaUuid)
            ->assertJsonPath('family_info.spouses.1.father.first_name', 'Karim');
    }

    public function test_spouse_father_requires_spouse_uuid_when_multiple_partners(): void
    {
        $user = $this->createUserWithFamily(['display_name' => 'Ahmed']);
        $self = FamilyMember::query()->where('user_id', $user->id)->firstOrFail();
        $self->update(['first_name' => 'Ahmed', 'last_name' => 'Ali', 'gender' => 'male']);

        $this->actingAsUser($user);

        $this->patchJson('/api/v1/family-tree/family-info', [
            'has_multiple_partners' => true,
            'spouses' => [
                [
                    'first_name' => 'Sara',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                ],
                [
                    'first_name' => 'Layla',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                ],
            ],
        ])->assertOk();

        $this->postJson('/api/v1/family-tree/members', [
            'relation_type' => 'spouse_father',
            'first_name' => 'Karim',
            'last_name' => 'Noor',
            'gender' => 'male',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['spouse_uuid']);
    }
}
