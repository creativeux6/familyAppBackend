<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhoneAuthService
{
    public function register(string $phone, string $displayName, string $password): array
    {
        $phone = $this->normalizePhone($phone);

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Phone number is already registered.'],
            ]);
        }

        return DB::transaction(function () use ($phone, $displayName, $password) {
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'phone' => $phone,
                'display_name' => $displayName,
                'name' => $displayName,
                'password' => $password,
            ]);

            $user->phones()->create([
                'phone' => $phone,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            $token = $user->createToken('mobile')->plainTextToken;

            return $this->authResponse($user, $token, 'registered');
        });
    }

    public function login(string $phone, string $password): array
    {
        $phone = $this->normalizePhone($phone);

        $user = User::query()->where('phone', $phone)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['These credentials do not match our records.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->authResponse($user, $token, 'logged_in');
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function refresh(User $user): array
    {
        $user->currentAccessToken()?->delete();
        $token = $user->createToken('mobile')->plainTextToken;

        return $this->authResponse($user, $token, 'refreshed');
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone);
    }

    private function authResponse(User $user, string $token, string $action): array
    {
        return [
            'action' => $action,
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'uuid' => $user->uuid,
                'phone' => $user->phone,
                'display_name' => $user->display_name,
                'is_anonymous' => $user->is_anonymous,
            ],
        ];
    }
}
