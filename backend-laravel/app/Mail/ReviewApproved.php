<?php

namespace App\Mail;

use App\Models\MonthApproval;
use App\Models\ReportingPeriod;

/**
 * Sent to the requesting admin when a manager approves a review.
 */
class ReviewApproved extends BaseNotification
{
    public function __construct(public readonly ReportingPeriod $period, public readonly MonthApproval $approval)
    {
        parent::__construct(
            subjectLine: "Report Approved — {$period->name}",
            title: 'Review Approved',
            introLines: [
                "The {$approval->manager_role} review of the {$period->name} report has been APPROVED".
                ($approval->approvedBy ? ' by '.$approval->approvedBy->full_name.'.' : '.'),
            ],
            ctaUrl: rtrim(config('app.frontend_url', config('app.url')), '/')
                    ."/reporting-periods/{$period->id}",
            ctaText: 'Open reporting period',
        );
    }
}
