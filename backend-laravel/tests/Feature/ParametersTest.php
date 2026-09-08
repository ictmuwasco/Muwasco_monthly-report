<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Parameters & parameter-categories API tests (M8).
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class ParametersTest extends TestCase
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

    private function plainUser(): User
    {
        return User::create([
            'username' => 'user1', 'email' => 'user1@example.com',
            'full_name' => 'Plain User', 'password' => Hash::make('secret123'),
            'role' => 'user', 'is_active' => true,
        ]);
    }

    private function seedCatalog(): array
    {
        $cat = ParameterCategory::create(['name' => 'Population', 'description' => 'Pop data', 'display_order' => 1]);
        $p = Parameter::create([
            'category_id' => $cat->id, 'code' => '1a', 'label' => 'Population served',
            'data_type' => 'number', 'unit' => 'people', 'required' => true,
        ]);
        return [$cat, $p];
    }

    /* ── Read access ──────────────────────────────────────────────── */

    public function test_any_authenticated_user_can_list_parameters(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson('/api/v1/parameters')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_cannot_list_parameters(): void
    {
        $this->getJson('/api/v1/parameters')->assertStatus(401);
    }

    public function test_parameters_support_category_filter(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson("/api/v1/parameters?category_id={$cat->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p->id);
    }

    public function test_show_parameter_includes_category(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson("/api/v1/parameters/{$p->id}")
            ->assertOk()
            ->assertJsonPath('category.id', $cat->id);
    }

    public function test_any_authenticated_user_can_list_categories(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->getJson('/api/v1/parameter-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.parameters_count', 1);
    }

    /* ── Admin write access ──────────────────────────────────────── */

    public function test_admin_can_create_parameter(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameters', [
                'code' => '1b', 'category_id' => $cat->id, 'label' => 'New param',
                'data_type' => 'currency', 'unit' => 'KES', 'required' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('code', '1b');
    }

    public function test_plain_user_cannot_create_parameter(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $user = $this->plainUser();

        $this->actingAs($user)
            ->postJson('/api/v1/parameters', ['code' => '1b', 'label' => 'x'])
            ->assertForbidden();
    }

    public function test_create_parameter_validates_required_fields(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameters', ['code' => '1b']) // missing label
            ->assertStatus(422);
    }

    public function test_create_parameter_rejects_duplicate_code(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameters', ['code' => $p->code, 'label' => 'dup'])
            ->assertStatus(422);
    }

    public function test_admin_can_update_parameter(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/parameters/{$p->id}", ['label' => 'Updated', 'required' => true])
            ->assertOk()
            ->assertJsonPath('label', 'Updated');
    }

    public function test_update_parameter_rejects_duplicate_code(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $p2 = Parameter::create(['category_id' => $cat->id, 'code' => '2a', 'label' => 'second']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/parameters/{$p->id}", ['code' => '2a']) // same as p2
            ->assertStatus(422);
    }

    /* ── Create category ─────────────────────────────────────────── */

    public function test_admin_can_create_category(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameter-categories', ['name' => 'New Cat', 'display_order' => 2])
            ->assertCreated()
            ->assertJsonPath('name', 'New Cat');
    }

    public function test_non_admin_cannot_create_category(): void
    {
        $user = $this->plainUser();

        $this->actingAs($user)
            ->postJson('/api/v1/parameter-categories', ['name' => 'x'])
            ->assertForbidden();
    }

    public function test_create_category_requires_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameter-categories', [])
            ->assertStatus(422);
    }

    /* ── Audit ───────────────────────────────────────────────────── */

    public function test_create_parameter_writes_audit_log(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/parameters', ['code' => '1b', 'label' => 'test'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'parameter.created']);
    }

    public function test_update_parameter_writes_audit_log(): void
    {
        [$cat, $p] = $this->seedCatalog();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/api/v1/parameters/{$p->id}", ['label' => 'changed']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'parameter.updated']);
    }
}
