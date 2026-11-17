<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_session_authentication_uses_the_versioned_api(): void
    {
        $user = User::factory()->create();
        $headers = ['Origin' => 'http://localhost:5173'];

        $this->withHeaders($headers)->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);

        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);
        Auth::forgetGuards();
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_mobile_token_authentication_and_logout_revoke_the_token(): void
    {
        $user = User::factory()->create();
        $tokenResponse = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ])->assertCreated();
        $token = $tokenResponse->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_employee_browser_sessions_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $headers = ['Origin' => 'http://localhost:5173'];

        $this->withHeaders($headers)->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withHeaders($headers)->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_browser_login_can_opt_out_of_persistent_sign_in(): void
    {
        $user = User::factory()->create();
        $headers = ['Origin' => 'http://localhost:5173'];

        $this->withHeaders($headers)->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => false,
        ])->assertOk()
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_employee_mobile_tokens_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'employee-phone',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_invalid_payloads_use_the_shared_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/tokens', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    }

    public function test_a_mobile_client_can_revoke_another_device_token(): void
    {
        $user = User::factory()->create();
        $firstToken = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'first-device',
        ])->assertCreated()->json('data.token');
        Auth::forgetGuards();
        $secondToken = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'second-device',
        ])->assertCreated()->json('data.token');
        $secondTokenId = $user->tokens()->where('name', 'second-device')->value('id');

        Auth::forgetGuards();
        $this->withToken($firstToken)->deleteJson("/api/v1/auth/tokens/{$secondTokenId}")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
        Auth::forgetGuards();
        $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
