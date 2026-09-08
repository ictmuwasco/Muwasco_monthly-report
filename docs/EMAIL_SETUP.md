# Email Notifications Setup

Approval emails (`ApprovalRequested`, `ChangesRequested`, `ReviewApproved`, `ReportSubmitted`, `DeadlineReminder`) are already wired into the approval workflow. This guide covers switching mail delivery on.

## 1. Why emails may not arrive now

The current `.env` uses:

```
MAIL_MAILER=log
```

which writes emails to `backend-laravel/storage/logs/laravel.log` instead of sending. That is safe for development but no one receives anything.

## 2. Switch to SMTP (Gmail example)

1. In the Google account used for sending, enable **2-Step Verification**, then create an **App Password** (Google Account → Security → App passwords).
2. Edit `backend-laravel/.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-sender@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_FROM_ADDRESS=your-sender@gmail.com
MAIL_FROM_NAME="MUWASCO Reporting"
```

3. Clear config cache: `php artisan config:clear`
4. Test:

```bash
php artisan tinker --execute='
    Mail::raw("SMTP test from MUWASCO reporting", function ($m) {
        $m->to("recipient@example.com")->subject("SMTP test");
    });
    echo "sent";'
```

If your provider is not Gmail, set `MAIL_HOST`/`MAIL_PORT`/`MAIL_ENCRYPTION` accordingly (common: `mail.muwasco.or.ke`, port `587`, `tls`).

## 3. Who receives approval emails

`ApprovalService::sendRequestEmail` resolves recipients from the manager user accounts (technical / commercial manager). An optional catch-all override exists via:

```env
APPROVAL_NOTIFY_EMAILS="tm@muwasco.or.ke,cm@muwasco.or.ke"
```

## 4. Production notes

- Keep credentials out of git — `.env` is ignored; `.env.example` documents the shape.
- For higher deliverability use a transactional service (Postmark, SES, Mailgun) — only `MAIL_MAILER`, host, port and credentials change.
- Failures queue in `failed_jobs`; run `php artisan queue:work` if you switch `QUEUE_CONNECTION=database` and want retries in the background.
