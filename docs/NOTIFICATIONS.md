# Notifications (M12)

> **Status:** ✅ done · 111 tests total (308 assertions) · live DB intact

## What it does
Replaces ad-hoc email handling with a proper **queued notification system**:
all transactional emails are queued (never block HTTP requests), retried with
backoff, and permanent failures are logged. Adds workflow notifications and a
deadline-reminder command.

## Mailables (`App\Mail`, all queued)
| Class | Trigger | Recipients |
|---|---|---|
| `ApprovalRequested` (M11, now queued) | Admin requests a manager review | `notify_email` or active users with the role |
| `ReportSubmitted` | M10 submit action | Active admins |
| `ReviewApproved` | Manager/admin approves a review | The admin who requested the review (fallback: active admins) |
| `ChangesRequested` | Review rejected (incl. reason) | The admin who requested the review (fallback: active admins) |
| `DeadlineReminder` | `reports:send-deadline-reminders` command | Users with data-entry assignments (fallback: active admins) |

## Reliability features
- **`BaseNotification`** base class: `ShouldQueue`, `tries = 5`,
  `backoff = [30s, 2m, 10m, 30m]`, `failed()` → `Log::error` (never silently dropped).
- `ApprovalRequested` has the same retry/logging guarantees.
- Queue connection: `database` (Laravel `jobs` table). Mail driver: `log` in this
  environment — switch `MAIL_MAILER` in production.
- Generic blade template `resources/views/emails/notification.blade.php`
  (title, intro lines, optional CTA) shared by the new notifications.

## Console command + schedule
```
php artisan reports:send-deadline-reminders {--days=3}
```
Finds periods with status `draft`/`open`/`changes_requested` and a
`submission_deadline` within the next N days (default 3), then queues reminders to
assigned users (falls back to active admins when nobody is assigned).
Scheduled daily at 08:00 (`routes/console.php`). Run `php artisan schedule:work`
(or a cron entry) in production.

## Bugs caught by tests
1. **Mailables lost their payload** — constructor params were not promoted
   (`public readonly`), so `$period`/`$approval` were never stored and emails would
   have rendered without data. Fixed in all four new mailables.
2. **Fake-mail assertions** — queued mailables must be asserted with
   `assertQueued`/`assertNotQueued`, not `assertSent` (M11 tests updated too).

## Verification
- **5 new tests**; full suite **111 passed (308 assertions)**.
- Coverage: submit → admins notified; approve → requester notified; reject →
  `ChangesRequested` with reason; reminder command (approaching / no-deadline /
  far-deadline / closed cases); nothing-approaching no-op.
- Command verified against the live DB (all 14 periods are `submitted` → correct
  "no approaching deadlines" no-op). Live data intact: users=10, months=14,
  monthly_data=2206, month_approvals=0.
