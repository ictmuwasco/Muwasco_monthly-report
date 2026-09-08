<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * User management API tests (M6).
 *
 * Runs against in-memory SQLite — the live MySQL database is NEVER touched.
 * Covers: authorization (admin-only mutations), CRUD, validation, duplicate
 * prevention, self-deactivation prevention, audit logging, password reset.
 */
class UsersTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();
    }

    private function admin(): User
    {
        return User::create([
            'username'  => 'admin1',
            'email'     => 'admin1@example.com',
            'full_name' => 'Admin One',
            'password'  => Hash::make('secret123'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function plainUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'username'  => 'user1',
            'email'     => 'user1@example.com',
            'full_name' => 'Plain User',
            'password'  => Hash::make('secret123'),
            'role'      => 'user',
            'is_active' => true,
        ], $attrs));
    }

    /* ── Authorization ───────────────────────────────────────────────── */

    public function test_non_admin_cannot_create_users(): void
    {
        $user = $this->plainUser();

        $this->actingAs($user)->postJson('/api/v1/users', [
            'username'  => 'newbie',
            'full_name' => 'New Person',
            'password'  => 'longpassword1',
            'role'      => 'user',
        ])->assertStatus(403);
    }

    public function test_non_admin_cannot_update_users(): void
    {
        $user = $this->plainUser();
        $other = $this->plainUser(['username' => 'user2', 'email' => 'user2@example.com']);

        $this->actingAs($user)
            ->patchJson("/api/v1/users/{$other->id}", ['full_name' => 'Hacked'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $other->id, 'full_name' => 'Plain User']);
    }

    public function test_unauthenticated_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    /* ── CRUD ────────────────────────────────────────────────────────── */

    public function test_admin_can_create_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'username'  => 'newbie',
            'email'     => 'newbie@example.com',
            'full_name' => 'New Person',
            'password'  => 'longpassword1',
            'role'      => 'user',
        ])->assertCreated()
          ->assertJsonPath('user.username', 'newbie');

        $created = User::where('username', 'newbie')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('longpassword1', $created->password));

        // Audit entry written with no password material.
        $log = AuditLog::where('action', 'user.created')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($created->id, $log->entity_id);
        $this->assertStringNotContainsString('longpassword1', json_encode($log->new_values));
    }

    public function test_create_rejects_duplicate_username(): void
    {
        $admin = $this->admin();
        $this->plainUser();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'username'  => 'user1',
            'full_name' => 'Duplicate',
            'password'  => 'longpassword1',
            'role'      => 'user',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('username');
    }

    public function test_create_rejects_weak_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'username'  => 'weakpw',
            'full_name' => 'Weak',
            'password'  => '123',
            'role'      => 'user',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('password');
    }

    public function test_admin_can_update_user(): void
    {
        $admin = $this->admin();
        $target = $this->plainUser(['email' => 'old@example.com']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/users/{$target->id}", ['full_name' => 'Renamed Person', 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('user.full_name', 'Renamed Person');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'admin']);

        $log = AuditLog::where('action', 'user.updated')->latest('id')->first();
        $this->assertSame('Plain User', $log->old_values['full_name']);
        $this->assertSame('Renamed Person', $log->new_values['full_name']);
    }

    public function test_username_and_password_are_immutable_on_update(): void
    {
        $admin = $this->admin();
        $target = $this->plainUser();

        $this->actingAs($admin)
            ->patchJson("/api/v1/users/{$target->id}", [
                'username' => 'changed',
                'password' => 'newpassword99',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    /* ── Activation lifecycle ────────────────────────────────────────── */

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$admin->id}/deactivate")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => 1]);
    }

    public function test_admin_can_deactivate_and_reactivate_user(): void
    {
        $admin = $this->admin();
        $target = $this->plainUser();
        $target->createToken('t');

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$target->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => 0]);
        $this->assertSame(0, $target->tokens()->count()); // tokens revoked

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$target->id}/activate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => 1]);
    }

    public function test_deactivating_twice_conflicts(): void
    {
        $admin = $this->admin();
        $target = $this->plainUser(['is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$target->id}/deactivate")
            ->assertStatus(409);
    }

    /* ── Password reset ─────────────────────────────────────────────── */

    public function test_admin_can_reset_password_and_tokens_are_revoked(): void
    {
        $admin = $this->admin();
        $target = $this->plainUser();
        $target->createToken('t');

        $this->actingAs($admin)
            ->postJson("/api/v1/users/{$target->id}/password", [
                'password' => 'newpassword99',
                'password_confirmation' => 'newpassword99',
            ])
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('newpassword99', $fresh->password));
        $this->assertSame(0, $fresh->tokens()->count());

        $this->assertNotNull(AuditLog::where('action', 'user.password_reset')->first());
    }

    public function test_user_can_reset_own_password(): void
    {
        $user = $this->plainUser();

        $this->actingAs($user)
            ->postJson("/api/v1/users/{$user->id}/password", [
                'password' => 'mynewpassword',
                'password_confirmation' => 'mynewpassword',
            ])
            ->assertOk();
    }

    public function test_user_cannot_reset_someone_elses_password(): void
    {
        $user = $this->plainUser();
        $other = $this->plainUser(['username' => 'user3', 'email' => 'user3@example.com']);

        $this->actingAs($user)
            ->postJson("/api/v1/users/{$other->id}/password", [
                'password' => 'evilpassword1',
                'password_confirmation' => 'evilpassword1',
            ])
            ->assertStatus(403);
    }

    /* ── Listing ─────────────────────────────────────────────────────── */

    public function test_index_supports_search_and_role_filter(): void
    {
        $admin = $this->admin();
        $this->plainUser();

        $this->actingAs($admin)
            ->getJson('/api/v1/users?search=Admin')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/users?role=user')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_can_view_own_record_but_not_others(): void
    {
        $user = $this->plainUser();
        $other = $this->plainUser(['username' => 'user4', 'email' => 'user4@example.com']);

        $this->actingAs($user)
            ->getJson("/api/v1/users/{$user->id}")
            ->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/users/{$other->id}")
            ->assertStatus(403);
    }
}