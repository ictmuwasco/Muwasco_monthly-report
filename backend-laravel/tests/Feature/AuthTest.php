<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Auth API tests.
 *
 * Runs against in-memory SQLite (phpunit.xml) — the live MySQL database is
 * NEVER touched. The schema mirrors the legacy live tables exactly.
 */
class AuthTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'username'  => 'testuser',
            'email'     => 'testuser@example.com',
            'full_name' => 'Test User',
            'password'  => Hash::make('secret123'),
            'role'      => 'user',
            'is_active' => true,
        ], $attrs));
    }

    public function test_login_returns_user_and_token(): void
    {
        $this->makeUser();

        $res = $this->postJson('/api/v1/login', [
            'username' => 'testuser',
            'password' => 'secret123',
        ]);

        $res->assertOk()
            ->assertJsonPath('user.username', 'testuser')
            ->assertJsonPath('user.role', 'user')
            ->assertJsonStructure(['token']);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/login', [
            'username' => 'testuser',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(['username' => 'inactive1', 'email' => 'inactive1@example.com', 'is_active' => false]);

        $this->postJson('/api/v1/login', [
            'username' => 'inactive1',
            'password' => 'secret123',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('username');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_me_and_logout_work_with_token(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('api-token')->plainTextToken;

        $this->withToken($token)
             ->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonPath('user.username', 'testuser')
             ->assertJsonMissing(['password']);

        $this->withToken($token)
             ->postJson('/api/v1/logout')
             ->assertOk();




        // Reset cached guard instances (the app container persists across
        // requests within a single test — in production each HTTP request
        // gets a fresh container, so this reset mirrors reality).
        $this->app->forgetInstance('auth');

        // Token must now be revoked.
        $this->withToken($token)
             ->getJson('/api/v1/me')
             ->assertStatus(401);
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/v1/login', [])->assertStatus(422);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->makeUser(['username' => 'ratelimit', 'password' => Hash::make('secret123')]);

        // 5 failed attempts exhaust the limiter (keyed by username+IP).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', [
                'username' => 'ratelimit',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // 6th attempt — even with the CORRECT password — is throttled.
        $res = $this->postJson('/api/v1/login', [
            'username' => 'ratelimit',
            'password' => 'secret123',
        ]);

        $res->assertStatus(429)
            ->assertJsonStructure(['message', 'errors' => ['username']])
            ->assertHeader('Retry-After');

        $this->assertStringContainsString(
            'Too many login attempts',
            $res->json('message')
        );
    }

    public function test_rate_limit_is_per_username_and_ip(): void
    {
        $this->makeUser(['username' => 'victim', 'email' => 'victim@example.com', 'password' => Hash::make('secret123')]);
        $this->makeUser(['username' => 'other', 'email' => 'other@example.com', 'password' => Hash::make('secret123')]);

        // Exhaust the limiter for the "victim" account only.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', [
                'username' => 'victim',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        // A different account from the same IP is NOT throttled.
        $this->postJson('/api/v1/login', [
            'username' => 'other',
            'password' => 'secret123',
        ])->assertOk();
    }
}