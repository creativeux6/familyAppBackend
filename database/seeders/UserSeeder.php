<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Dev users for local testing (phone + password auth).
     *
     * Login via POST /api/v1/auth/login with phone + password.
     */
    public function run(): void
    {
        $devUsers = [
            [
                'display_name' => 'Test User',
                'phone' => '+923001234567',
                'password' => 'password',
                'email' => 'test@example.com',
            ],
            [
                'display_name' => 'Ali Khan',
                'phone' => '+923009876543',
                'password' => 'password',
            ],
            [
                'display_name' => 'Super Admin',
                'phone' => '+923009999999',
                'password' => 'password',
                'email' => 'superadmin@example.com',
            ],
        ];

        foreach ($devUsers as $data) {
            $this->seedUser(
                displayName: $data['display_name'],
                phone: $data['phone'],
                password: $data['password'],
                email: $data['email'] ?? null,
            );
        }

        $this->command?->info('Seeded dev users (phone + password):');
        foreach ($devUsers as $data) {
            $this->command?->line("  {$data['phone']} / {$data['password']} ({$data['display_name']})");
        }

        $reviewerPhone = env('PLAY_REVIEWER_PHONE');
        $reviewerPassword = env('PLAY_REVIEWER_PASSWORD');
        if (is_string($reviewerPhone) && $reviewerPhone !== '' && is_string($reviewerPassword) && $reviewerPassword !== '') {
            $reviewerEmail = env('PLAY_REVIEWER_EMAIL');
            $this->seedUser(
                displayName: (string) env('PLAY_REVIEWER_NAME', 'Play Reviewer'),
                phone: $reviewerPhone,
                password: $reviewerPassword,
                email: is_string($reviewerEmail) && $reviewerEmail !== '' ? $reviewerEmail : null,
            );
            $this->command?->info("Seeded Play reviewer: {$reviewerPhone}");
        }
    }

    private function seedUser(string $displayName, string $phone, string $password, ?string $email = null): User
    {
        if (User::query()->where('phone', $phone)->exists()) {
            return User::query()->where('phone', $phone)->firstOrFail();
        }

        return DB::transaction(function () use ($displayName, $phone, $password, $email) {
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'phone' => $phone,
                'display_name' => $displayName,
                'name' => $displayName,
                'email' => $email,
                'password' => $password,
                'is_anonymous' => false,
            ]);

            $user->phones()->create([
                'phone' => $phone,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            return $user;
        });
    }
}
