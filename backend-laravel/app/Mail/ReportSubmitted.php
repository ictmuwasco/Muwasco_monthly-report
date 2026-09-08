<?php

namespace App\Mail;

use App\Models\ReportingPeriod;
use App\Models\User;

/**
 * Sent to active admins when a user submits a reporting period's data.
 */
class ReportSubmitted extends BaseNotification
{
    public function __construct(public readonly ReportingPeriod $period, public readonly User $submitter)
    {
        parent::__construct(
            subjectLine: "Monthly Report Submitted — {$period->name}",
            title: 'Monthly Report Submitted',
            introLines: [
                "{$submitter->full_name} ({$submitter->username}) has submitted the monthly report for {$period->name}.",
                'The period is now awaiting manager review.',
            ],
            ctaUrl: rtrim(config('app.frontend_url', config('app.url')), '/')
                    ."/reporting-periods/{$period->id}",
            ctaText: 'Open reporting period',
        );
    }
}
