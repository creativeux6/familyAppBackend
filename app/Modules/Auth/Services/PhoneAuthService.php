<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class PhoneAuthService
{
    public function __construct(
        private readonly PlanAssignmentService $planAssignmentService,
    ) {}

    public function register(string $phone, string $displayName, string $password, string $tokenName = 'mobile'): array
    {
        $phone = $this->normalizePhone($phone);

        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Phone number is already registered.'],
            ]);
        }

        return DB::transaction(function () use ($phone, $displayName, $password, $tokenName) {
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

            $userRole = Role::query()->firstOrCreate([
                'name' => 'user',
                'guard_name' => 'web',
            ]);
            $user->assignRole($userRole);

            $this->planAssignmentService->ensureDefaultFreePlan($user);

            $token = $user->createToken($tokenName)->plainTextToken;

            return $this->authResponse($user->fresh(), $token, 'registered');
        });
    }

    public function login(string $phone, string $password, string $tokenName = 'mobile'): array
    {
        $phone = $this->normalizePhone($phone);

        $user = User::query()->where('phone', $phone)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['These credentials do not match our records.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        if ($user->roles()->count() === 0) {
            $userRole = Role::query()->firstOrCreate([
                'name' => 'user',
                'guard_name' => 'web',
            ]);
            $user->assignRole($userRole);
        }

        $this->planAssignmentService->ensureDefaultFreePlan($user);

        return $this->authResponse($user->fresh(), $token, 'logged_in');
    }

    public function logout(User $user): void
    {
        $user->tokens()->delete();
    }

    public function refresh(User $user, string $tokenName = 'mobile'): array
    {
        $user->tokens()->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        return $this->authResponse($user->fresh(), $token, 'refreshed');
    }

    /** @return array<string, mixed> */
    public function me(User $user): array
    {
        return $this->userPayload($user->fresh());
    }

    /** @return array{message: string, reset_token?: string} */
    public function forgotPassword(string $phone): array
    {
        $phone = $this->normalizePhone($phone);
        $user = User::query()->where('phone', $phone)->first();

        // Always return a generic message to avoid account enumeration.
        $message = 'If that phone number is registered, a reset token has been issued.';

        if (! $user) {
            return ['message' => $message];
        }

        $token = Str::upper(Str::random(8));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $phone],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        Log::info('Password reset token issued', [
            'phone' => $phone,
            'reset_token' => $token,
        ]);

        $payload = ['message' => $message];

        if (app()->environment(['local', 'testing'])) {
            $payload['reset_token'] = $token;
        }

        return $payload;
    }

    public function resetPassword(string $phone, string $token, string $password): array
    {
        $phone = $this->normalizePhone($phone);
        $user = User::query()->where('phone', $phone)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid reset request.'],
            ]);
        }

        $row = DB::table('password_reset_tokens')->where('email', $phone)->first();

        if (! $row || ! Hash::check($token, $row->token)) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token is invalid.'],
            ]);
        }

        if ($row->created_at && \Illuminate\Support\Carbon::parse($row->created_at)->lt(now()->subHour())) {
            throw ValidationException::withMessages([
                'token' => ['This password reset token has expired.'],
            ]);
        }

        $user->forceFill([
            'password' => $password,
        ])->save();

        DB::table('password_reset_tokens')->where('email', $phone)->delete();
        $user->tokens()->delete();

        return ['message' => 'Password has been reset. You can log in with your new password.'];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone) ?? $phone;
    }

    /** @return array<string, mixed> */
    private function authResponse(User $user, string $token, string $action): array
    {
        return [
            'action' => $action,
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => $this->userPayload($user),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing('roles', 'permissions');

        return [
            'uuid' => $user->uuid,
            'phone' => $user->phone,
            'display_name' => $user->display_name,
            'is_anonymous' => $user->is_anonymous,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
