<?php

namespace Tests\Feature;

use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Reporting period lifecycle tests (M9).
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class ReportingPeriodsTest extends TestCase
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

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /* ── Read ────────────────────────────────────────────── */

    public function test_any_authenticated_user_can_list_periods(): void
    {
        ReportingPeriod::create(['name' => 'June 2025', 'month_year' => '2025-06-01',
            'start_date' => '2025-06-01', 'end_date' => '2025-06-30', 'status' => 'open']);
        ReportingPeriod::create(['name' => 'July 2025', 'month_year' => '2025-07-01',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-31', 'status' => 'draft']);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->getJson('/api/v1/reporting-periods');

        $res->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_list_supports_status_filter(): void
    {
        ReportingPeriod::create(['month_year' => '2025-06-01', 'status' => 'open']);
        ReportingPeriod::create(['month_year' => '2025-07-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->getJson('/api/v1/reporting-periods?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_unauthenticated_cannot_list_periods(): void
    {
        $this->getJson('/api/v1/reporting-periods')->assertUnauthorized();
    }

    public function test_show_returns_transition_options(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-06-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->getJson("/api/v1/reporting-periods/{$period->id}")
            ->assertOk()
            ->assertJsonPath('period.status', 'draft')
            ->assertJsonPath('can_transition_to.0', 'open')
            ->assertJsonPath('is_locked', false);
    }

    /* ── Create ──────────────────────────────────────────── */

    public function test_admin_can_create_period_with_derived_dates(): void
    {
        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson('/api/v1/reporting-periods', ['month_year' => '2025-09-01']);

        $res->assertCreated()
            ->assertJsonPath('name', 'September 2025')
            ->assertJsonPath('start_date', '2025-09-01')
            ->assertJsonPath('end_date', '2025-09-30')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('created_by', 'admin1');

        $this->assertDatabaseHas('months', ['month_year' => '2025-09-01', 'status' => 'draft']);
    }

    public function test_create_rejects_duplicate_month(): void
    {
        ReportingPeriod::create(['month_year' => '2025-09-01']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson('/api/v1/reporting-periods', ['month_year' => '2025-09-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month_year');
    }

    public function test_non_admin_cannot_create_period(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->postJson('/api/v1/reporting-periods', ['month_year' => '2025-09-01'])
            ->assertForbidden();
    }

    public function test_create_validates_month_year_format(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson('/api/v1/reporting-periods', ['month_year' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month_year');
    }

    /* ── Update ──────────────────────────────────────────── */

    public function test_admin_can_update_deadline(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->patchJson("/api/v1/reporting-periods/{$period->id}", ['submission_deadline' => '2025-09-10'])
            ->assertOk()
            ->assertJsonPath('submission_deadline', '2025-09-10');
    }

    public function test_month_year_is_immutable(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->patchJson("/api/v1/reporting-periods/{$period->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertDatabaseHas('months', ['id' => $period->id, 'month_year' => '2025-09-01']);
    }

    public function test_status_cannot_be_set_via_generic_update(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->patchJson("/api/v1/reporting-periods/{$period->id}", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_non_admin_cannot_update_period(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->patchJson("/api/v1/reporting-periods/{$period->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    /* ── Status transitions ──────────────────────────────── */

    public function test_admin_can_transition_draft_to_open(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('period.status', 'open')
            ->assertJsonPath('previous', 'draft');
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        // draft → approved is not a valid transition.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status", ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('months', ['id' => $period->id, 'status' => 'draft']);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status", ['status' => 'exploded'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_closed_period_can_be_reopened_by_admin(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'closed']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('period.status', 'open');
    }

    public function test_full_lifecycle_walk(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        foreach (['open', 'submitted', 'under_review', 'approved', 'closed'] as $status) {
            $period->transitionTo($status);
            $this->assertDatabaseHas('months', ['id' => $period->id, 'status' => $status]);
        }
    }

    public function test_non_admin_cannot_transition(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->plainUser()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status", ['status' => 'open'])
            ->assertForbidden();
    }

    public function test_transition_writes_audit_log(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status",
                ['status' => 'open', 'comment' => 'Period opened for entry'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'    => 'reporting_period.status_changed',
            'entity_id' => $period->id,
        ]);
    }

    public function test_transition_validates_comment_length(): void
    {
        $period = ReportingPeriod::create(['month_year' => '2025-09-01', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/status",
                ['status' => 'open', 'comment' => str_repeat('x', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('comment');
    }

    /* ── Model unit checks ───────────────────────────────── */

    public function test_period_state_helpers(): void
    {
        $open = new ReportingPeriod(['status' => 'open']);
        $closed = new ReportingPeriod(['status' => 'closed']);
        $underReview = new ReportingPeriod(['status' => 'under_review']);

        $this->assertTrue($open->isOpenForEntry());
        $this->assertFalse($open->isLockedForEditing());
        $this->assertTrue($closed->isClosed());
        $this->assertTrue($closed->isLockedForEditing());
        $this->assertTrue($underReview->isLockedForEditing());
    }
}
