<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Roles catalog + per-user data-access assignment tests (M7).
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class RolesAndAssignmentsTest extends TestCase
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
            'username' => 'admin1', 'email' => 'admin1@example.com',
            'full_name' => 'Admin One', 'password' => Hash::make('secret123'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function plainUser(string $suffix = ''): User
    {
        return User::create([
            'username' => 'user1'.$suffix, 'email' => 'user1'.$suffix.'@example.com',
            'full_name' => 'Plain User', 'password' => Hash::make('secret123'),
            'role' => 'user', 'is_active' => true,
        ]);
    }

    private function seedCatalog(): array
    {
        Role::create(['name' => 'revenue_officer', 'description' => 'Billing']);
        Role::create(['name' => 'technical_manager', 'description' => 'Production']);

        $catA = ParameterCategory::create(['name' => 'Water Quality']);
        $catB = ParameterCategory::create(['name' => 'Revenue']);

        $p1 = Parameter::create(['category_id' => $catA->id, 'code' => '1a', 'label' => 'pH Level']);
        $p2 = Parameter::create(['category_id' => $catA->id, 'code' => '1b', 'label' => 'Turbidity']);
        $p3 = Parameter::create(['category_id' => $catB->id, 'code' => '2a', 'label' => 'Billed Volume']);

        return [$catA, $catB, $p1, $p2, $p3];
    }

    /* ── Roles catalog ───────────────────────────────────────────────── */

    public function test_any_authenticated_user_can_list_roles(): void
    {
        $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_roles_support_search(): void
    {
        $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson('/api/v1/roles?search=technical')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'technical_manager');
    }

    public function test_unauthenticated_cannot_list_roles(): void
    {
        $this->getJson('/api/v1/roles')->assertStatus(401);
    }

    /* ── Assignments: authorization ──────────────────────────────────── */

    public function test_non_admin_cannot_sync_assignments(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $user = $this->plainUser();
        $other = $this->plainUser('x');

        $this->actingAs($user)
            ->putJson("/api/v1/users/{$other->id}/parameters", ['parameter_ids' => [$p1->id]])
            ->assertStatus(403);

        $this->actingAs($user)
            ->putJson("/api/v1/users/{$other->id}/categories", ['category_ids' => [$catA->id]])
            ->assertStatus(403);
    }

    public function test_user_can_view_own_assignments(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $user = $this->plainUser();
        $user->accessibleParameters()->sync([$p1->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/users/{$user->id}/assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data.parameters');
    }

    /* ── Assignments: sync behaviour ─────────────────────────────────── */

    public function test_admin_can_sync_parameter_assignments(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/parameters", ['parameter_ids' => [$p1->id, $p3->id]])
            ->assertOk()
            ->assertJsonPath('parameter_ids.0', $p1->id);

        $this->assertSame([$p1->id, $p3->id],
            $target->accessibleParameters()->pluck('parameters.id')->sort()->values()->all());

        // Re-sync with a smaller set replaces (not appends).
        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/parameters", ['parameter_ids' => [$p2->id]])
            ->assertOk();

        $this->assertSame([$p2->id],
            $target->accessibleParameters()->pluck('parameters.id')->all());
    }

    public function test_sync_rejects_nonexistent_parameter_ids(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/parameters", ['parameter_ids' => [99999]])
            ->assertStatus(422);

        $this->assertSame(0, $target->accessibleParameters()->count());
    }

    public function test_sync_with_duplicate_ids_is_deduplicated(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/parameters", ['parameter_ids' => [$p1->id, $p1->id]])
            ->assertOk();

        $this->assertSame(1, $target->accessibleParameters()->count());
    }

    public function test_admin_can_sync_category_assignments(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/categories", ['category_ids' => [$catB->id]])
            ->assertOk();

        $this->assertSame([$catB->id],
            $target->accessibleCategories()->pluck('parameter_categories.id')->all());
    }

    public function test_empty_sync_clears_assignments(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');
        $target->accessibleCategories()->sync([$catA->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/categories", ['category_ids' => []])
            ->assertOk();

        $this->assertSame(0, $target->accessibleCategories()->count());
    }

    public function test_sync_writes_audit_log(): void
    {
        [$catA, $catB, $p1, $p2, $p3] = $this->seedCatalog();
        $admin = $this->admin();
        $target = $this->plainUser('t');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$target->id}/parameters", ['parameter_ids' => [$p1->id]])
            ->assertOk();

        $log = AuditLog::where('action', 'user.parameter_assignments.synced')->first();
        $this->assertNotNull($log);
        $this->assertSame($target->id, $log->entity_id);
        $this->assertSame($admin->id, $log->actor_user_id);
    }
}