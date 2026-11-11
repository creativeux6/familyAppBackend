<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Mail\SupportRecoveryRequestMail;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_forgot_password_without_email_returns_no_email_status(): void
    {
        $user = $this->createUserWithFamily();
        $user->forceFill(['email' => null])->save();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => $user->phone,
        ])->assertOk()
            ->assertJsonPath('status', 'no_email')
            ->assertJsonPath('can_contact_support', true);
    }

    public function test_forgot_password_with_email_sends_otp(): void
    {
        Mail::fake();

        $user = $this->createUserWithFamily();
        $user->forceFill(['email' => 'member@example.com'])->save();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => $user->phone,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'otp_sent')
            ->assertJsonStructure(['otp']);

        Mail::assertSent(PasswordResetOtpMail::class);

        $otp = $response->json('otp');

        $verify = $this->postJson('/api/v1/auth/verify-reset-otp', [
            'phone' => $user->phone,
            'otp' => $otp,
        ]);

        $verify->assertOk()
            ->assertJsonStructure(['reset_token']);

        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => $user->phone,
            'token' => $verify->json('reset_token'),
            'password' => 'new-secret-99',
            'password_confirmation' => 'new-secret-99',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'new-secret-99',
        ])->assertOk();
    }

    public function test_contact_support_emails_support_inbox(): void
    {
        Mail::fake();
        AppSetting::putValue('support_inbox_email', 'support@example.com');

        $this->postJson('/api/v1/auth/contact-support', [
            'phone' => '+923001112233',
            'note' => 'Need email added',
        ])->assertOk();

        Mail::assertSent(SupportRecoveryRequestMail::class);
    }
}
