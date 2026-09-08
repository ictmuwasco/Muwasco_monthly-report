<?php

namespace App\Mail;

use App\Models\MonthApproval;
use App\Models\ReportingPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Email sent to a manager when a reporting period is submitted for their review.
 * Carries the raw token used to build the secure approve/reject links.
 * Queued with retry/backoff; permanent failures are logged.
 */
class ApprovalRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly ReportingPeriod $period,
        public readonly MonthApproval $approval,
        public readonly string $rawToken,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Monthly Report Review Requested — {$this->period->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.approval-requested',
            with: [
                'periodName'  => $this->period->name,
                'managerRole' => $this->approval->manager_role,
                'approveUrl'  => $this->decisionUrl('approve'),
                'rejectUrl'   => $this->decisionUrl('reject'),
                'expiresAt'   => $this->approval->token_expires_at?->format('d M Y, H:i'),
            ],
        );
    }

    private function decisionUrl(string $action): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/')
            ."/approval-requests/{$this->rawToken}/{$action}";
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Approval-request email failed permanently', [
            'approval_id' => $this->approval->id,
            'error'       => $e?->getMessage(),
        ]);
    }
}
