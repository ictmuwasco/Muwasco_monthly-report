<?php

namespace App\Mail;

use App\Models\MonthApproval;
use App\Models\ReportingPeriod;

/**
 * Sent to the requesting admin when a manager requests changes (rejection).
 */
class ChangesRequested extends BaseNotification
{
    public function __construct(public readonly ReportingPeriod $period, public readonly MonthApproval $approval)
    {
        parent::__construct(
            subjectLine: "Changes Requested — {$period->name}",
            title: 'Changes Requested',
            introLines: [
                "The {$approval->manager_role} review of the {$period->name} report requested changes.",
                'Reason: '.($approval->rejection_reason ?? '—'),
                'The period can be corrected and resubmitted.',
            ],
            ctaUrl: rtrim(config('app.frontend_url', config('app.url')), '/')
                    ."/reporting-periods/{$period->id}",
            ctaText: 'Open reporting period',
        );
    }
}
