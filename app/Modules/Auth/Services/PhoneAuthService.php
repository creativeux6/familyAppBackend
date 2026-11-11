<?php

namespace App\Modules\Auth\Services;

use App\Mail\LoginAlertMail;
use App\Mail\PasswordResetOtpMail;
use App\Mail\SupportRecoveryRequestMail;
use App\Models\AppSetting;
use App\Models\User;
use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Support\AdminDiagnostic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
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
            $this->throwClientSafeValidation(
                ['phone' => ['This phone number is already registered.']],
                'AUTH_PHONE_ALREADY_REGISTERED',
                'Registration blocked: phone number is already registered.',
                ['phone' => $phone],
            );
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

    public function login(
        string $phone,
        string $password,
        string $tokenName = 'mobile',
        ?string $deviceName = null,
    ): array {
        $phone = $this->normalizePhone($phone);
        $failureKey = 'login-fail:'.hash('sha256', $phone);

        // Extra lockout after repeated wrong passwords for the same phone.
        if (RateLimiter::tooManyAttempts($failureKey, 8)) {
            $seconds = RateLimiter::availableIn($failureKey);

            throw ValidationException::withMessages([
                'phone' => [
                    'Too many failed login attempts. Try again in '.$seconds.' seconds.',
                ],
            ]);
        }

        $user = User::query()->where('phone', $phone)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($failureKey, 60 * 15);

            $this->throwClientSafeValidation(
                ['phone' => ['These credentials do not match our records.']],
                $user ? 'AUTH_INVALID_PASSWORD' : 'AUTH_USER_NOT_FOUND',
                $user
                    ? 'Login failed: incorrect password for existing phone.'
                    : 'Login failed: no user found for phone.',
                ['phone' => $phone],
            );
        }

        RateLimiter::clear($failureKey);

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

        $this->sendLoginAlert($user, $deviceName);

        return $this->authResponse($user->fresh(), $token, 'logged_in');
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        // Cookie/session auth (no PAT): revoke API tokens for this user.
        $user->tokens()->delete();
    }

    public function refresh(User $user, string $tokenName = 'mobile'): array
    {
        // Issue the new token first, then revoke only the current one.
        // Deleting first left clients stranded if the response never arrived.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken
            ? $currentToken->id
            : null;

        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

        if ($currentTokenId !== null) {
            $user->tokens()->whereKey($currentTokenId)->delete();
        }

        return $this->authResponse($user->fresh(), $plainTextToken, 'refreshed');
    }

    /** @return array<string, mixed> */
    public function me(User $user): array
    {
        return $this->userPayload($user->fresh());
    }

    /**
     * Issue an email OTP for password reset when the account has an email.
     *
     * @return array<string, mixed>
     */
    public function forgotPassword(string $phone): array
    {
        $phone = $this->normalizePhone($phone);
        $user = User::query()->where('phone', $phone)->first();

        if (! $user) {
            // Avoid confirming whether the phone exists.
            return [
                'status' => 'otp_sent',
                'message' => 'If that account has an email on file, a reset code was sent.',
                'resend_after_seconds' => 180,
            ];
        }

        if (! is_string($user->email) || trim($user->email) === '') {
            return [
                'status' => 'no_email',
                'message' => 'No email attached to your account so we cannot initiate the recovery process.',
                'can_contact_support' => true,
            ];
        }

        $resendKey = 'reset-otp-resend:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($resendKey, 1)) {
            $seconds = RateLimiter::availableIn($resendKey);

            return [
                'status' => 'otp_sent',
                'message' => 'A reset code was already sent. You can request another after the timer.',
                'resend_after_seconds' => max(1, $seconds),
            ];
        }

        $otp = (string) random_int(100000, 999999);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $phone],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        RateLimiter::hit($resendKey, 180);

        Mail::to($user->email)->send(new PasswordResetOtpMail($otp, $phone));

        if (app()->environment(['local', 'testing'])) {
            Log::info('Password reset OTP issued', [
                'phone' => $phone,
                'otp' => $otp,
            ]);
        } else {
            Log::info('Password reset OTP issued', [
                'phone_hash' => hash('sha256', $phone),
            ]);
        }

        $payload = [
            'status' => 'otp_sent',
            'message' => 'A reset code was sent to the email on your account.',
            'resend_after_seconds' => 180,
        ];

        if (app()->environment(['local', 'testing'])) {
            $payload['otp'] = $otp;
        }

        return $payload;
    }

    /**
     * Verify OTP and return a short-lived reset capability token.
     *
     * @return array{status: string, reset_token: string, message: string}
     */
    public function verifyResetOtp(string $phone, string $otp): array
    {
        $phone = $this->normalizePhone($phone);
        $attemptKey = 'reset-otp-attempt:'.hash('sha256', $phone);

        if (RateLimiter::tooManyAttempts($attemptKey, 8)) {
            $seconds = RateLimiter::availableIn($attemptKey);

            throw ValidationException::withMessages([
                'otp' => ["Too many attempts. Try again in {$seconds} seconds."],
            ]);
        }

        $row = DB::table('password_reset_tokens')->where('email', $phone)->first();
        $invalidMessage = 'This reset code is invalid or has expired.';

        if (
            ! $row
            || ! Hash::check($otp, $row->token)
            || ($row->created_at && \Illuminate\Support\Carbon::parse($row->created_at)->lt(now()->subMinutes(10)))
        ) {
            RateLimiter::hit($attemptKey, 60 * 15);

            throw ValidationException::withMessages([
                'otp' => [$invalidMessage],
            ]);
        }

        RateLimiter::clear($attemptKey);

        $resetToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $phone],
            [
                'token' => Hash::make($resetToken),
                'created_at' => now(),
            ]
        );

        return [
            'status' => 'otp_verified',
            'reset_token' => $resetToken,
            'message' => 'Code verified. Set a new password.',
        ];
    }

    public function resetPassword(string $phone, string $token, string $password): array
    {
        $phone = $this->normalizePhone($phone);
        $user = User::query()->where('phone', $phone)->first();
        $invalidMessage = 'This password reset request is invalid or has expired.';

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => [$invalidMessage],
            ]);
        }

        $row = DB::table('password_reset_tokens')->where('email', $phone)->first();

        if (! $row || ! Hash::check($token, $row->token)) {
            throw ValidationException::withMessages([
                'token' => [$invalidMessage],
            ]);
        }

        if ($row->created_at && \Illuminate\Support\Carbon::parse($row->created_at)->lt(now()->subMinutes(30))) {
            throw ValidationException::withMessages([
                'token' => [$invalidMessage],
            ]);
        }

        $user->forceFill([
            'password' => $password,
        ])->save();

        DB::table('password_reset_tokens')->where('email', $phone)->delete();
        $user->tokens()->delete();

        return ['message' => 'Password changed successfully. You can log in with your new password.'];
    }

    /** @return array{message: string} */
    public function contactSupportForRecovery(string $phone, ?string $note = null): array
    {
        $phone = $this->normalizePhone($phone);
        $support = AppSetting::getValue(
            'support_inbox_email',
            (string) config('mail.from.address'),
        );

        if (! is_string($support) || trim($support) === '') {
            throw ValidationException::withMessages([
                'support' => ['Support inbox is not configured. Please try again later.'],
            ]);
        }

        Mail::to($support)->send(new SupportRecoveryRequestMail($phone, $note));

        return [
            'message' => 'Support has been notified. Once an email is added to your account, retry forgot password.',
        ];
    }

    private function sendLoginAlert(User $user, ?string $deviceName): void
    {
        if (! is_string($user->email) || trim($user->email) === '') {
            return;
        }

        $label = is_string($deviceName) && trim($deviceName) !== ''
            ? trim($deviceName)
            : 'Unknown device';

        try {
            Mail::to($user->email)->send(new LoginAlertMail((string) $user->phone, $label));
        } catch (\Throwable $e) {
            Log::warning('Login alert email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $newPassword,
        ])->save();

        // Keep the current session; revoke other devices.
        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when(
                $currentToken instanceof PersonalAccessToken,
                fn ($query) => $query->where('id', '!=', $currentToken->id),
            )
            ->delete();

        return ['message' => 'Password updated.'];
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone) ?? $phone;
    }

    /**
     * Client gets a safe validation payload; admin logs get the real cause/code.
     *
     * @param  array<string, list<string>>  $messages
     * @param  array<string, mixed>  $context
     */
    private function throwClientSafeValidation(
        array $messages,
        string $errorCode,
        string $internalMessage,
        array $context = [],
    ): never {
        $exception = ValidationException::withMessages($messages);
        AdminDiagnostic::attach($exception, $errorCode, $internalMessage, $context);

        throw $exception;
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
            'email' => $user->email,
            'display_name' => $user->display_name,
            'is_anonymous' => $user->is_anonymous,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'avatar' => app(\App\Modules\Avatars\Services\AvatarService::class)
                ->userAvatarPayload($user),
        ];
    }
}
