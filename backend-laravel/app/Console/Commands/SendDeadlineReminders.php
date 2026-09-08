<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminder;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendDeadlineReminders extends Command
{
    protected $signature = 'reports:send-deadline-reminders {--days=3 : Days ahead of the deadline to remind}';

    protected $description = 'Email users with reporting assignments about approaching submission deadlines';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $from = now()->startOfDay();
        $to   = now()->copy()->addDays($days)->endOfDay();

        // Periods still accepting entry with a deadline inside the window.
        $periods = ReportingPeriod::query()
            ->whereIn('status', [ReportingPeriod::STATUS_DRAFT, ReportingPeriod::STATUS_OPEN,
                ReportingPeriod::STATUS_CHANGES_REQUESTED])
            ->whereNotNull('submission_deadline')
            ->whereBetween('submission_deadline', [$from, $to])
            ->get();

        if ($periods->isEmpty()) {
            $this->info('No reporting periods with approaching deadlines.');

            return self::SUCCESS;
        }

        foreach ($periods as $period) {
            $recipients = $this->recipientsFor($period);

            if ($recipients->isEmpty()) {
                $this->warn("No recipients found for {$period->name}; skipped.");

                continue;
            }

            foreach ($recipients as $user) {
                Mail::to($user->email)->send(new DeadlineReminder($period));
            }

            $this->info("Reminders queued for {$period->name} → {$recipients->count()} recipient(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Users with data-entry assignments for this period's parameters/sections,
     * falling back to active admins when nobody is assigned.
     */
    private function recipientsFor(ReportingPeriod $period): \Illuminate\Support\Collection
    {
        $assigned = User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereHas('accessibleParameters')
                  ->orWhereHas('accessibleCategories');
            })
            ->get();

        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        return User::query()->where('role', 'admin')->where('is_active', true)->get();
    }
}
