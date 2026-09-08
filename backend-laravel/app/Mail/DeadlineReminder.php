<?php

namespace App\Mail;

use App\Models\ReportingPeriod;

/**
 * Reminder that a reporting period's submission deadline is approaching.
 * Sent by the reports:send-deadline-reminders command.
 */
class DeadlineReminder extends BaseNotification
{
    public function __construct(public readonly ReportingPeriod $period)
    {
        parent::__construct(
            subjectLine: "Deadline Approaching — {$period->name}",
            title: 'Submission Deadline Approaching',
            introLines: [
                "The submission deadline for the {$period->name} report is ".
                $period->submission_deadline?->format('d M Y').'.',
                'Please ensure all assigned monthly data is entered and submitted before the deadline.',
            ],
            ctaUrl: rtrim(config('app.frontend_url', config('app.url')), '/')
                    ."/reporting-periods/{$period->id}",
            ctaText: 'Enter monthly data',
        );
    }
}
