<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BaseNotification — shared foundation for all queued notification emails.
 *
 * Queued (never blocks the HTTP request), retried with backoff, and failures
 * are logged. Subclasses provide subject/title/lines/call-to-action.
 */
abstract class BaseNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Retry up to 5 times with increasing backoff before giving up. */
    public int $tries = 5;

    /** Seconds between retries: 30s, 2m, 10m, 30m. */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $title,
        public readonly array $introLines,
        public readonly ?string $ctaUrl = null,
        public readonly ?string $ctaText = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification', with: [
            'title'      => $this->title,
            'introLines' => $this->introLines,
            'ctaUrl'     => $this->ctaUrl,
            'ctaText'    => $this->ctaText,
        ]);
    }

    /**
     * Final failure (after all retries) is logged — never silently dropped.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Notification email failed permanently', [
            'mailable' => static::class,
            'subject'  => $this->subjectLine,
            'error'    => $e?->getMessage(),
        ]);
    }
}
