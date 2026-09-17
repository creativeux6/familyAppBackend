<?php

namespace Tests\Feature\Admin;

use App\Models\SystemErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemErrorLogDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_register_logs_internal_error_code_for_admins(): void
    {
        User::factory()->create([
            'phone' => '+923005555555',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'phone' => '+923005555555',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'first_name' => 'Dup',
            'last_name' => 'Licate',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath(
                'errors.phone.0',
                'This phone number is already registered.',
            );

        $log = SystemErrorLog::query()
            ->where('path', 'like', '%/auth/register')
            ->where('status_code', 422)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('ValidationException', $log->exception_class);
        $this->assertStringContainsString('[AUTH_PHONE_ALREADY_REGISTERED]', (string) $log->message);
        $this->assertStringContainsString('already registered', (string) $log->message);
        $this->assertStringContainsString('AUTH_PHONE_ALREADY_REGISTERED', (string) $log->trace);
        $this->assertStringContainsString('+923005555555', (string) $log->trace);
    }

    public function test_register_validation_errors_include_field_details_in_log_message(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'phone' => 'not-a-phone',
            'password' => 'short',
            'password_confirmation' => 'short',
            'first_name' => '',
            'last_name' => '',
        ])->assertStatus(422);

        $log = SystemErrorLog::query()
            ->where('path', 'like', '%/auth/register')
            ->where('status_code', 422)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('ValidationException', $log->exception_class);
        $this->assertStringContainsString('Validation failed', (string) $log->message);
        $this->assertStringContainsString('validation_errors', (string) $log->trace);
    }
}
