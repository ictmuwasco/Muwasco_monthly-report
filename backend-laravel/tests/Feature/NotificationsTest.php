<?php

namespace Tests\Feature;

use App\Mail\ChangesRequested;
use App\Mail\DeadlineReminder;
use App\Mail\ReportSubmitted;
use App\Mail\ReviewApproved;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Notification tests (M12). In-memory SQLite only.
 */
class NotificationsTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();

        Mail::fake();
    }

    private ?User $adminUser = null;

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
        return User::create([
            'username' => 'officer'.uniqid(), 'email' => 'officer'.uniqid().'@example.com',
            'full_name' => 'Data Officer', 'password' => Hash::make('secret123'),
            'role' => 'user', 'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /* ── Submission → admins ─────────────────────────────── */

    public function test_submit_notifies_active_admins(): void
    {
        $this->admin();
        $officer = $this->officer();

        $period = ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01',
            'start_date' => '2025-09-01', 'end_date' => '2025-09-30', 'status' => 'open',
        ]);

        $param = Parameter::create(['category_id' => ParameterCategory::create(['name' => 'C'])->id,
            'code' => 'A1', 'label' => 'X', 'data_type' => 'number', 'required' => false]);
        \DB::table('user_parameter_assignments')->insert([
            'user_id' => $officer->id, 'parameter_id' => $param->id, 'assigned_at' => now(),
        ]);
        \DB::table('monthly_data')->insert([
            'month_id' => $period->id, 'parameter_id' => $param->id, 'value' => '10',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($officer))
            ->postJson("/api/v1/reporting-periods/{$period->id}/submit")
            ->assertOk();

        Mail::assertQueued(ReportSubmitted::class, 1);
    }

    /* ── Approval decision → requesting admin ────────────── */

    private function requestReview(ReportingPeriod $period): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals",
                ['manager_role' => 'technical_manager', 'notify_email' => 'manager@example.com'])
            ->assertCreated();
    }

    public function test_approval_decision_notifies_requesting_admin(): void
    {
        $period = ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01', 'status' => 'submitted',
        ]);
        $this->requestReview($period);

        Mail::fake(); // clear the ApprovalRequested from the review request

        $approval = \App\Models\MonthApproval::where('month_id', $period->id)->first();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'approve'])
            ->assertOk();

        Mail::assertQueued(ReviewApproved::class, 1);
        Mail::assertNotQueued(ChangesRequested::class);
    }

    public function test_rejection_decision_sends_changes_requested_with_reason(): void
    {
        $period = ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01', 'status' => 'submitted',
        ]);
        $this->requestReview($period);

        Mail::fake();

        $approval = \App\Models\MonthApproval::where('month_id', $period->id)->first();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->postJson("/api/v1/reporting-periods/{$period->id}/approvals/{$approval->id}/decide",
                ['action' => 'reject', 'comment' => 'Numbers need checking'])
            ->assertOk();

        Mail::assertQueued(ChangesRequested::class, 1);
        Mail::assertNotQueued(ReviewApproved::class);
    }

    /* ── Deadline reminders ──────────────────────────────── */

    public function test_deadline_reminders_sent_for_approaching_periods(): void
    {
        $this->admin();
        $officer = $this->officer();

        $param = Parameter::create(['category_id' => ParameterCategory::create(['name' => 'C'])->id,
            'code' => 'B1', 'label' => 'Y', 'data_type' => 'number']);
        \DB::table('user_parameter_assignments')->insert([
            'user_id' => $officer->id, 'parameter_id' => $param->id, 'assigned_at' => now(),
        ]);

        $approaching = ReportingPeriod::create([
            'name' => 'September 2025', 'month_year' => '2025-09-01',
            'status' => 'open', 'submission_deadline' => now()->addDays(2)->toDateString(),
        ]);
        // No deadline → no reminder.
        ReportingPeriod::create(['name' => 'October 2025', 'month_year' => '2025-10-01', 'status' => 'open']);
        // Far deadline → no reminder.
        ReportingPeriod::create(['name' => 'November 2025', 'month_year' => '2025-11-01',
            'status' => 'open', 'submission_deadline' => now()->addDays(30)->toDateString()]);
        // Closed → no reminder.
        ReportingPeriod::create(['name' => 'August 2025', 'month_year' => '2025-08-01',
            'status' => 'closed', 'submission_deadline' => now()->addDay()->toDateString()]);

        Artisan::call('reports:send-deadline-reminders');

        Mail::assertQueued(DeadlineReminder::class, 1);
    }

    public function test_no_reminders_when_nothing_approaching(): void
    {
        $this->admin();

        ReportingPeriod::create(['name' => 'September 2025', 'month_year' => '2025-09-01', 'status' => 'open']);

        Artisan::call('reports:send-deadline-reminders');

        Mail::assertNothingQueued();
    }
}
