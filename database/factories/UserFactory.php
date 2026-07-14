<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $displayName = fake()->name();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $displayName,
            'display_name' => $displayName,
            'phone' => '+92300'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'is_anonymous' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->phones()->where('phone', $user->phone)->exists()) {
                return;
            }

            $user->phones()->create([
                'phone' => $user->phone,
                'is_primary' => true,
                'verified_at' => now(),
            ]);
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
