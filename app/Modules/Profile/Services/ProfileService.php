<?php

namespace App\Modules\Profile\Services;

use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    /** @return array<string, mixed> */
    public function show(User $user): array
    {
        $member = $this->requireFamilyMember($user);

        return [
            'user' => $this->formatUser($user),
            'member' => $this->formatMember($member),
            'notice' => 'Manage parents, spouse, and children from the Family tree tab.',
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function updateUser(User $user, array $data): array
    {
        $updates = array_intersect_key($data, array_flip(['display_name', 'marital_status']));

        if ($updates !== []) {
            if (isset($updates['display_name'])) {
                $updates['name'] = $updates['display_name'];
            }

            $user->update($updates);
        }

        return $this->show($user->fresh());
    }

    /** @param  array<string, mixed>  $data */
    public function updateMember(User $user, array $data): array
    {
        $member = $this->requireFamilyMember($user);

        $updates = array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'date_of_birth', 'birthplace', 'gender',
        ]));

        if ($updates !== []) {
            $member->update($updates);
        }

        return $this->show($user->fresh());
    }

    private function requireFamilyMember(User $user): FamilyMember
    {
        $member = FamilyMember::query()->where('user_id', $user->id)->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'profile' => ['Complete onboarding before editing your profile.'],
            ]);
        }

        return $member;
    }

    /** @return array<string, mixed> */
    private function formatUser(User $user): array
    {
        return [
            'uuid' => $user->uuid,
            'display_name' => $user->display_name,
            'phone' => $user->phone,
            'is_anonymous' => (bool) $user->is_anonymous,
            'marital_status' => $user->marital_status,
        ];
    }

    /** @return array<string, mixed> */
    private function formatMember(FamilyMember $member): array
    {
        return [
            'uuid' => $member->uuid,
            'member_code' => $member->member_code,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'birthplace' => $member->birthplace,
            'gender' => $member->gender,
            'is_living' => $member->is_living,
        ];
    }
}
