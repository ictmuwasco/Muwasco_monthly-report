<?php

namespace Tests\Feature;

use App\Models\MonthlyData;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Monthly data-entry tests (M10).
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class MonthlyDataTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();
    }

    private ?User $adminUser = null;
    private ?User $officerUser = null;

    private function admin(): User
    {
        return $this->adminUser ??= User::create([
            'username' => 'admin1', 'email' => 'admin1@example.com',
            'full_name' => 'Admin One', 'password' => Hash::make('secret123'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function officer(): User
    {
        return $this->officerUser ??= User::create([
            'username' => 'officer1', 'email' => 'officer1@example.com',
            'full_name' => 'Data Officer', 'password' => Hash::make('secret123'),
            'role' => 'user', 'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Catalog: category A (p1 required number, p2 optional percentage)
     * and category B (p3 optional text).
     */
    private function seedCatalog(): array
    {
        $catA = ParameterCategory::create(['name' => 'Production']);
        $catB = ParameterCategory::create(['name' => 'Revenue', 'display_order' => 2]);

        $p1 = Parameter::create(['category_id' => $catA->id, 'code' => 'A1',
            'label' => 'Water Produced', 'data_type' => 'number', 'required' => true]);
        $p2 = Parameter::create(['category_id' => $catA->id, 'code' => 'A2',
            'label' => 'NRW', 'data_type' => 'percentage', 'required' => false]);
        $p3 = Parameter::create(['category_id' => $catB->id, 'code' => 'B1',
            'label' => 'Remarks', 'data_type' => 'text', 'required' => false]);

        return [$catA, $catB, $p1, $p2, $p3];
    }

    private function period(string $status = 'open'): ReportingPeriod
    {
        return ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01',
            'start_date' => '2025-09-01', 'end_date' => '2025-09-30', 'status' => $status,
        ]);
    }

    /* ── Access scope ────────────────────────────────────── */

    public function test_admin_sees_all_parameters_in_entry_form(): void
    {
        $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->getJson("/api/v1/reporting-periods/{$period->id}/data-entry")
            ->assertOk()
            ->assertJsonPath('can_save', true)
            ->assertJsonPath('can_submit', true)
            ->assertJsonCount(2, 'categories');
    }

    public function test_user_sees_only_assigned_parameters(): void
    {
        [, $catB, $p1, , $p3] = $this->seedCatalog();
        $officer = $this->officer();
        $period  = $this->period();

        \DB::table('user_parameter_assignments')->insert([
            'user_id' => $officer->id, 'parameter_id' => $p1->id, 'assigned_at' => now(),
        ]);
        \DB::table('user_section_assignments')->insert([
            'user_id' => $officer->id, 'category_id' => $catB->id, 'assigned_at' => now(),
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($officer))
            ->getJson("/api/v1/reporting-periods/{$period->id}/data-entry");

        $res->assertOk();

        $codes = collect($res->json('categories'))
            ->flatMap(fn ($c) => array_column($c['parameters'], 'code'))
            ->sort()->values()->all();

        $this->assertSame(['A1', 'B1'], $codes); // A2 (NRW) is NOT accessible
    }

    public function test_user_with_no_assignments_sees_nothing(): void
    {
        $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->officer()))
            ->getJson("/api/v1/reporting-periods/{$period->id}/data-entry")
            ->assertOk()
            ->assertJsonCount(0, 'categories');
    }

    public function test_unauthenticated_cannot_access_entry_form(): void
    {
        $period = $this->period();

        $this->getJson("/api/v1/reporting-periods/{$period->id}/data-entry")
            ->assertUnauthorized();
    }

    /* ── Save (draft upsert) ─────────────────────────────── */

    public function test_admin_can_save_values(): void
    {
        [, , $p1, $p2, ] = $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data", ['rows' => [
                ['parameter_id' => $p1->id, 'value' => '1234.5'],
                ['parameter_id' => $p2->id, 'value' => '18.75'],
            ]])
            ->assertOk()
            ->assertJsonPath('saved', 2);

        $this->assertDatabaseHas('monthly_data',
            ['month_id' => $period->id, 'parameter_id' => $p1->id, 'value' => '1234.5']);
    }

    public function test_save_upserts_without_duplicates(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period();

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($this->admin())];
        $url     = "/api/v1/reporting-periods/{$period->id}/monthly-data";

        $this->withHeaders($headers)->putJson($url,
            ['rows' => [['parameter_id' => $p1->id, 'value' => '100']]])->assertOk();
        $this->withHeaders($headers)->putJson($url,
            ['rows' => [['parameter_id' => $p1->id, 'value' => '200']]])->assertOk();

        $this->assertDatabaseHas('monthly_data',
            ['month_id' => $period->id, 'parameter_id' => $p1->id, 'value' => '200']);
        $this->assertSame(1, MonthlyData::where('month_id', $period->id)->count());
    }

    public function test_save_rejects_unassigned_parameter(): void
    {
        [, , , , $p3] = $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->officer()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p3->id, 'value' => 'sneaky']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.parameter_id');

        $this->assertDatabaseMissing('monthly_data', ['parameter_id' => $p3->id]);
    }

    public function test_save_rejects_non_numeric_for_number_type(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p1->id, 'value' => 'not-a-number']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.value');
    }

    public function test_save_rejects_percentage_out_of_range(): void
    {
        [, , , $p2, ] = $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p2->id, 'value' => '150']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.value');
    }

    public function test_save_blocked_on_locked_period(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period('closed');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p1->id, 'value' => '1']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_save_blocked_after_deadline(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = ReportingPeriod::create([
            'name' => 'August 2025', 'month_year' => '2025-08-01',
            'start_date' => '2025-08-01', 'end_date' => '2025-08-31',
            'submission_deadline' => '2025-08-15', 'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p1->id, 'value' => '1']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    /* ── Submit ──────────────────────────────────────────── */

    public function test_submit_blocked_until_required_values_present(): void
    {
        [, , $p1, , ] = $this->seedCatalog(); // p1 is required
        $period = $this->period('open');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('required');

        // Fill the required parameter, then submit succeeds.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p1->id, 'value' => '42']]])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertOk()
            ->assertJsonPath('period.status', 'submitted');
    }

    public function test_submit_from_draft_passes_through_open(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period('draft');

        MonthlyData::create(['month_id' => $period->id, 'parameter_id' => $p1->id, 'value' => '7']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertOk()
            ->assertJsonPath('previous', 'draft')
            ->assertJsonPath('period.status', 'submitted');
    }

    public function test_submit_blocked_on_submitted_period(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period('submitted');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_submit_blocked_after_deadline(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = ReportingPeriod::create([
            'name' => 'August 2025', 'month_year' => '2025-08-01',
            'start_date' => '2025-08-01', 'end_date' => '2025-08-31',
            'submission_deadline' => '2025-08-15', 'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
    }

    public function test_submit_only_checks_accessible_required_parameters(): void
    {
        // p1 (required) belongs to Production; officer is assigned only to
        // Revenue section, so p1 must NOT block their submission.
        [, $catB, , , ] = $this->seedCatalog();
        $officer = $this->officer();
        $period  = $this->period('open');

        \DB::table('user_section_assignments')->insert([
            'user_id' => $officer->id, 'category_id' => $catB->id, 'assigned_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($officer))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertOk()
            ->assertJsonPath('period.status', 'submitted');
    }

    public function test_save_writes_audit_log(): void
    {
        [, , $p1, , ] = $this->seedCatalog();
        $period = $this->period();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->putJson("/api/v1/reporting-periods/{$period->id}/monthly-data",
                ['rows' => [['parameter_id' => $p1->id, 'value' => '5']]])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'monthly_data.saved']);
    }
}
