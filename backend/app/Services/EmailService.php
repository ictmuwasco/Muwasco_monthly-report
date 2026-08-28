<?php

namespace App\Services;
/**
 * EmailService — PHPMailer bootstrap, SMTP transport & notification templates.
 * Extracted from add_data.php (no logic changes, only re-organized).
 */
class EmailService {
    private bool $loaded = false;
    private $model;

    public function __construct($model = null) {
        foreach ([
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../phpmailer/src/PHPMailer.php',
            __DIR__ . '/../../PHPMailer/src/PHPMailer.php',
        ] as $path) {
            if (!file_exists($path)) continue;
            require_once $path;
            if (strpos($path, 'autoload.php') === false) {
                $d = dirname($path);
                foreach (['SMTP.php', 'Exception.php'] as $f)
                    if (file_exists("$d/$f")) require_once "$d/$f";
            }
            $this->loaded = true;
            break;
        }
        $this->model = $model;
    }

    /** Low-level SMTP send. */
    public function send(string $to_email, string $to_name, string $subject, string $html): bool {
        if (!$this->loaded) { error_log("PHPMailer missing — cannot email $to_email"); return false; }
        try {
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = SMTP_HOST;
            $m->SMTPAuth   = true;
            $m->Username   = SMTP_USERNAME;
            $m->Password   = SMTP_PASSWORD;
            $m->SMTPSecure = SMTP_ENCRYPTION === 'tls'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMIME;
            $m->Port       = SMTP_PORT;
            // Uncomment on localhost if SSL errors occur:
            // $m->SMTPOptions = ['ssl' => ['verify_peer' => false, 'allow_self_signed' => true]];
            $m->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $m->addAddress($to_email, $to_name);
            $m->addReplyTo(EMAIL_FROM, EMAIL_FROM_NAME);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html));
            $m->send();
            return true;
        } catch (PHPMailer\PHPMailer\Exception $e) {
            error_log("PHPMailer [$to_email]: " . $e->getMessage());
            return false;
        }
    }

    /** Shared HTML email shell. */
    private function wrap(string $accent, string $icon_emoji, string $title, string $body_html): string {
        $year = date('Y');
        $org  = EMAIL_FROM_NAME;
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:#f4f6f9;font-family:'Segoe UI',Arial,sans-serif;color:#333;}
  .shell{max-width:620px;margin:32px auto;}
  .header{background:$accent;border-radius:10px 10px 0 0;padding:36px 40px;text-align:center;}
  .header h1{color:#fff;font-size:22px;font-weight:700;letter-spacing:.3px;}
  .header p{color:rgba(255,255,255,.82);font-size:14px;margin-top:6px;}
  .body{background:#fff;padding:36px 40px;border:1px solid #e0e5ec;border-top:none;}
  .body p{font-size:15px;line-height:1.75;color:#444;margin-bottom:14px;}
  .info-box{background:#f8faff;border-left:4px solid $accent;border-radius:0 8px 8px 0;padding:16px 20px;margin:20px 0;}
  .info-box table{border-collapse:collapse;width:100%;}
  .info-box td{padding:5px 0;font-size:14px;color:#2c3e50;vertical-align:top;}
  .info-box td:first-child{font-weight:600;white-space:nowrap;padding-right:16px;width:140px;}
  .cta{text-align:center;margin:28px 0 8px;}
  .cta a{display:inline-block;background:$accent;color:#fff!important;text-decoration:none;padding:14px 38px;border-radius:8px;font-size:15px;font-weight:600;letter-spacing:.2px;}
  .fallback{text-align:center;font-size:12px;color:#999;margin-top:10px;}
  .fallback a{color:#1976d2;word-break:break-all;}
  .notice{background:#fffbf0;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 18px;margin-top:20px;font-size:13px;color:#7c5b00;}
  .footer{background:#1e2d3d;border-radius:0 0 10px 10px;padding:18px 30px;text-align:center;}
  .footer p{color:#8fa3b0;font-size:12px;line-height:1.6;}
</style>
</head>
<body>
<div class="shell">
  <div class="header">
    <h1>$icon_emoji &nbsp;$org</h1>
    <p>$title</p>
  </div>
  <div class="body">
    $body_html
  </div>
  <div class="footer">
    <p>This is an automated message from <strong>$org</strong>.<br>
    Please do not reply directly to this email.<br>
    &copy; $year MUWASCO Water &amp; Sewerage Company</p>
  </div>
</div>
</body>
</html>
HTML;
    }


    /** Email 1 — sent to manager asking them to review the report. */
    public function reviewRequest(int $month_id, string $mgr_role, array $mgr, string $token): bool {
        $month = $this->model ? $this->model->getMonth($month_id) : null;
        if (add_data.phpmonth) return false;

        $base   = base_url();
        $link   = "$base/add_data.php?month_id=$month_id&approval_token=$token&manager_role=$mgr_role";
        $title  = $mgr_role === 'technical_manager' ? 'Technical Manager' : 'Commercial Manager';
        $sects  = $mgr_role === 'technical_manager'
                ? 'Production, Infrastructure, Water Quality, NRW &amp; Operations'
                : 'Revenue, Customer Care, Accounts, GIS, HR &amp; Related Sections';
        $name   = trim("{$mgr['first_name']} {$mgr['last_name']}") ?: $mgr['username'];
        $period = !empty($month['start_date']) && !empty($month['end_date'])
                ? date('d M Y', strtotime($month['start_date'])) . ' – ' . date('d M Y', strtotime($month['end_date']))
                : 'N/A';

        $body = "
        <p>Dear <strong>" . he($name) . "</strong>,</p>
        <p>I hope this message finds you well. The monthly operations report for <strong>" . he($month['name']) . "</strong> has been compiled and is ready for your review and formal approval.</p>
        <p>Kindly review the figures within your area of responsibility and confirm their accuracy before the report is finalised.</p>
        <div class='info-box'>
          <table>
            <tr><td>Report Period</td><td>" . he($month['name']) . "</td></tr>
            <tr><td>Dates</td><td>$period</td></tr>
            <tr><td>Your Role</td><td>$title</td></tr>
            <tr><td>Sections</td><td>$sects</td></tr>
            <tr><td>Link Valid</td><td>7 days from date of this email</td></tr>
          </table>
        </div>
        <p>Please click the button below to open the data review page, verify the entries, and submit your approval or request corrections where necessary.</p>
        <div class='cta'><a href='" . he($link) . "'>Open Review &amp; Approval Page</a></div>
        <p class='fallback'>Button not working? Copy and paste this link into your browser:<br>
          <a href='" . he($link) . "'>" . he($link) . "</a></p>
        <div class='notice'>
          <strong>⏰ Please note:</strong> This approval link will expire in <strong>7 days</strong>.
          If you are unable to act within this period, please contact the system administrator to have a new link issued.
        </div>";

        $html = $this->wrap(
            'linear-gradient(135deg,#1565c0,#1976d2)',
            '📋',
            'Monthly Report — Review &amp; Approval Required',
            $body
        );

        return $this->send($mgr['email'], $name,
            "Action Required: Please Review and Approve the {$month['name']} Monthly Report",
            $html);
    }

    /** Email 2 — sent to all admins after a manager approves or rejects. Returns count sent. */
    public function adminDecision(int $month_id, string $mgr_role, array $mgr, string $action, string $reason = ''): int {
        $month  = $this->model ? $this->model->getMonth($month_id) : null;
        if (add_data.phpmonth) return 0;
        $admins = $this->model ? $this->model->getAdminUsers() : [];

        $title    = $mgr_role === 'technical_manager' ? 'Technical Manager' : 'Commercial Manager';
        $mgr_name = trim("{$mgr['first_name']} {$mgr['last_name']}") ?: $mgr['username'];
        $ts       = date('d M Y \\a\\t H:i');
        $link     = base_url() . "/add_data.php?month_id=$month_id";

        if ($action === 'approve') {
            $subj   = "Monthly Report Approved — {$month['name']} ({$title})";
            $accent = 'linear-gradient(135deg,#1b5e20,#2e7d32)';
            $emoji  = '✅';
            $header = 'Report Approved Successfully';
            $body   = "
            <p>Dear Administrator,</p>
            <p>This is to confirm that the <strong>$title</strong>, <strong>" . he($mgr_name) . "</strong>, has reviewed and formally <strong>approved</strong> the data entries for the <strong>" . he($month['name']) . "</strong> monthly report.</p>
            <div class='info-box'>
              <table>
                <tr><td>Report</td><td>" . he($month['name']) . "</td></tr>
                <tr><td>Approved By</td><td>" . he($mgr_name) . " &mdash; $title</td></tr>
                <tr><td>Date &amp; Time</td><td>$ts</td></tr>
              </table>
            </div>
            <p>You may now proceed with the final submission of the report once all other required approvals are in place.</p>
            <div class='cta'><a href='" . he($link) . "'>View Report Status</a></div>";
        } else {
            $subj   = "Corrections Required — {$month['name']} Monthly Report ({$title})";
            $accent = 'linear-gradient(135deg,#7f0000,#c62828)';
            $emoji  = '⚠️';
            $header = 'Corrections Requested by Manager';
            $reason_block = $reason
                ? "<div class='info-box' style='border-color:#c62828;background:#fff8f8;'>
                     <table><tr><td style='font-weight:600;padding-right:16px;white-space:nowrap;'>Reason</td>
                     <td>" . he($reason) . "</td></tr></table></div>"
                : '';
            $body   = "
            <p>Dear Administrator,</p>
            <p>Please be advised that the <strong>$title</strong>, <strong>" . he($mgr_name) . "</strong>, has reviewed the <strong>" . he($month['name']) . "</strong> monthly report and has <strong>requested corrections</strong> before approval can be granted.</p>
            <div class='info-box'>
              <table>
                <tr><td>Report</td><td>" . he($month['name']) . "</td></tr>
                <tr><td>Reviewed By</td><td>" . he($mgr_name) . " &mdash; $title</td></tr>
                <tr><td>Date &amp; Time</td><td>$ts</td></tr>
              </table>
            </div>
            $reason_block
            <p>Kindly log in to the system, make the necessary corrections, and re-submit the review request to the manager once the data has been updated.</p>
            <div class='cta'><a href='" . he($link) . "'>Log In &amp; Make Corrections</a></div>";
        }

        $html = $this->wrap($accent, $emoji, $header, $body);

        $sent = 0;
        foreach ($admins as $admin) {
            $aname = trim("{$admin['first_name']} {$admin['last_name']}") ?: $admin['username'];
            if ($this->send($admin['email'], $aname, $subj, $html)) $sent++;
        }
        return $sent;
    }
}
