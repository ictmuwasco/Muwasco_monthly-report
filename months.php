<?php
/**
 * months.php — Reporting period management (logic).
 * UI rendered via frontend/src/pages/reporting-months.php on the shared Tailwind layout.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);   // OFF in production — output breaks redirects
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/storage/logs/php_errors.log');

// ── Secure bootstrap (session, headers, .env, DB via $conn) ─────────────────
require_once __DIR__ . '/backend/bootstrap/app.php';

// ── Auth check ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── Core includes ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/backend/app/Helpers/AuthFunctions.php';
require_once __DIR__ . '/backend/app/Helpers/RoleFunctions.php';
require_once __DIR__ . '/backend/config/mail.php';

// ── PHPMailer — use statements MUST come before any requires that load them.
//    Load autoload safely so a missing vendor dir doesn't kill the page.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

$phpmailer_available = false;
foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/phpmailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
] as $_pm_path) {
    if (!file_exists($_pm_path)) continue;
    require_once $_pm_path;
    if (strpos($_pm_path, 'autoload.php') === false) {
        $d = dirname($_pm_path);
        foreach (['SMTP.php', 'Exception.php'] as $_f)
            if (file_exists("$d/$_f")) require_once "$d/$_f";
    }
    $phpmailer_available = true;
    break;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Absolute base URL (avoids relative-redirect issues in production)
// ─────────────────────────────────────────────────────────────────────────────
function site_base(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'];
    $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return "$proto://$host$dir";
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Send month-creation notification to all active users
// ─────────────────────────────────────────────────────────────────────────────
function sendMonthCreatedEmails($conn, $monthName, $startDate, $endDate, $createdBy): array {
    global $phpmailer_available;

    if (!$phpmailer_available) {
        error_log("sendMonthCreatedEmails: PHPMailer not available.");
        return ['sent' => 0, 'failed' => 0, 'errors' => ['PHPMailer is not installed.']];
    }

    $sql    = "SELECT first_name, last_name, email FROM users
               WHERE is_active = 1 AND email IS NOT NULL AND email != ''";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) {
        return ['sent' => 0, 'failed' => 0, 'errors' => ['No active users with email addresses found.']];
    }

    $sent   = 0;
    $failed = 0;
    $errors = [];

    $formattedStart = date('F j, Y', strtotime($startDate));
    $formattedEnd   = date('F j, Y', strtotime($endDate));

    while ($user = $result->fetch_assoc()) {
        $fullName  = trim($user['first_name'] . ' ' . $user['last_name']);
        $emailBody = buildEmailBody($fullName, $monthName, $formattedStart, $formattedEnd, $createdBy);

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->addAddress($user['email'], $fullName);
            $mail->isHTML(true);
            $mail->Subject = "📅 New Reporting Month Created: {$monthName}";
            $mail->Body    = $emailBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $emailBody));

            $mail->send();
            $sent++;
        } catch (MailException $e) {
            $failed++;
            $errors[] = "Failed to send to {$user['email']}: " . $e->getMessage();
            error_log("Month email error [{$user['email']}]: " . $e->getMessage());
        }
    }

    return compact('sent', 'failed', 'errors');
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Build HTML email body
// ─────────────────────────────────────────────────────────────────────────────
function buildEmailBody($recipientName, $monthName, $startDate, $endDate, $createdBy): string {
    $base = site_base();
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body{margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;}
    .wrapper{max-width:600px;margin:40px auto;background:#fff;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;}
    .header{background:linear-gradient(135deg,#1a6fc4,#2196f3);padding:32px 36px;text-align:center;}
    .header h1{color:#fff;margin:0;font-size:22px;}
    .header p{color:rgba(255,255,255,.85);margin:6px 0 0;font-size:13px;}
    .body{padding:32px 36px;}
    .greeting{font-size:16px;color:#2c3e50;font-weight:600;margin-bottom:6px;}
    .intro{color:#555;font-size:14px;line-height:1.7;margin-bottom:24px;}
    .info-box{background:#f0f7ff;border-left:4px solid #2196f3;border-radius:6px;padding:18px 22px;margin-bottom:24px;}
    .info-box p{margin:5px 0;font-size:14px;color:#333;}
    .info-box strong{display:inline-block;width:110px;color:#1a6fc4;}
    .action-btn{display:block;width:fit-content;margin:0 auto 28px;background:#2196f3;color:#fff;text-decoration:none;padding:13px 32px;border-radius:6px;font-size:15px;font-weight:600;}
    .note{font-size:12px;color:#888;line-height:1.6;border-top:1px solid #eee;padding-top:18px;}
    .footer{background:#f4f6f9;text-align:center;padding:18px;font-size:11px;color:#aaa;}
  </style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>💧 MUWASCO Monthly Report</h1>
    <p>Water System Data Collection Notification</p>
  </div>
  <div class="body">
    <p class="greeting">Hello, {$recipientName}!</p>
    <p class="intro">A new reporting month has been created in <strong>AquaTrack Pro</strong>.
    Please log in and submit your section of the monthly report before the deadline.</p>
    <div class="info-box">
      <p><strong>📋 Month:</strong> {$monthName}</p>
      <p><strong>📅 Start Date:</strong> {$startDate}</p>
      <p><strong>🏁 End Date:</strong> {$endDate}</p>
      <p><strong>👤 Created By:</strong> {$createdBy}</p>
      <p><strong>⚡ Status:</strong> Draft — data entry now open</p>
    </div>
    <a href="{$base}/monthly_reports.php" class="action-btn">➜ &nbsp; Enter My Data Now</a>
    <p class="note">This is an automated notification from the MUWASCO AquaTrack Pro system.
    Please do not reply to this email. If you need assistance, contact your system administrator.</p>
  </div>
  <div class="footer">© 2025 MUWASCO · AquaTrack Pro · All rights reserved</div>
</div>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN FORM HANDLING
// ─────────────────────────────────────────────────────────────────────────────
$success     = '';
$error       = '';
$emailStatus = null;

// Pull email result from session (set before previous redirect)
if (isset($_SESSION['email_status'])) {
    $emailStatus = $_SESSION['email_status'];
    unset($_SESSION['email_status']);
}

// ── CSRF protection for all state-changing requests ─────────────────────────
CsrfMiddleware::verifyOrDie();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    switch ($_POST['action']) {

        // ── CREATE MONTH ──────────────────────────────────────────────────────
        case 'create_month':
            $name       = trim($_POST['name']       ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date   = trim($_POST['end_date']   ?? '');

            if ($name === '' || $start_date === '' || $end_date === '') {
                $error = "Please fill in all required fields.";
                break;
            }

            $start_ts = strtotime($start_date);
            $end_ts   = strtotime($end_date);

            if (!$start_ts || !$end_ts) {
                $error = "Invalid date format.";
                break;
            }
            if ($end_ts < $start_ts) {
                $error = "End date must be after start date.";
                break;
            }
            if (date('Y-m', $start_ts) !== date('Y-m', $end_ts)) {
                $error = "Start and end dates must be within the same calendar month.";
                break;
            }

            $month_year = date('Y-m', $start_ts);

            try {
                // Duplicate month-year check
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM months WHERE month_year = ?");
                $chk->bind_param("s", $month_year); $chk->execute();
                if ($chk->get_result()->fetch_assoc()['c'] > 0) {
                    $error = "A month for " . date('F Y', $start_ts) . " already exists.";
                    $chk->close(); break;
                }
                $chk->close();

                // Overlapping date-range check
                $ovl = $conn->prepare("
                    SELECT COUNT(*) AS c FROM months
                    WHERE (start_date <= ? AND end_date >= ?)
                       OR (start_date <= ? AND end_date >= ?)
                       OR (? <= start_date AND ? >= end_date)
                ");
                $ovl->bind_param("ssssss",
                    $start_date, $start_date,
                    $end_date,   $end_date,
                    $start_date, $end_date
                );
                $ovl->execute();
                if ($ovl->get_result()->fetch_assoc()['c'] > 0) {
                    $error = "This date range overlaps with an existing month.";
                    $ovl->close(); break;
                }
                $ovl->close();

                $user_info  = getUserInfo($conn, $_SESSION['user_id'] ?? null);
                $created_by = $user_info['username'] ?? 'System';

                $ins = $conn->prepare(
                    "INSERT INTO months (name, month_year, start_date, end_date, status, created_by)
                     VALUES (?, ?, ?, ?, 'draft', ?)"
                );
                $ins->bind_param("sssss", $name, $month_year, $start_date, $end_date, $created_by);

                if ($ins->execute()) {
                    $_SESSION['last_created_month'] = $conn->insert_id;

                    // Send notification emails
                    $emailStatus = sendMonthCreatedEmails($conn, $name, $start_date, $end_date, $created_by);
                    $_SESSION['email_status'] = $emailStatus;

                    // Use absolute redirect to prevent path ambiguity in production
                    header('Location: ' . site_base() . '/months.php?success=1');
                    exit;
                } else {
                    $error = ($conn->errno === 1062)
                        ? "Duplicate entry. A month with this name or period already exists."
                        : "Database error: " . $conn->error . " (Error code: " . $conn->errno . ")";
                }
                $ins->close();

            } catch (\Exception $e) {
                $error = "Error creating month: " . $e->getMessage();
                error_log("months.php create_month: " . $e->getMessage());
            }
            break;

        // ── DELETE MONTH ──────────────────────────────────────────────────────
        case 'delete_month':
            $month_id = intval($_POST['month_id'] ?? 0);

            $chk = $conn->prepare("SELECT COUNT(*) AS c FROM months WHERE id = ?");
            $chk->bind_param("i", $month_id); $chk->execute();
            if ($chk->get_result()->fetch_assoc()['c'] === 0) {
                $error = "Month not found."; $chk->close(); break;
            }
            $chk->close();

            $chk2 = $conn->prepare("SELECT COUNT(*) AS c FROM monthly_data WHERE month_id = ?");
            $chk2->bind_param("i", $month_id); $chk2->execute();
            $has_data = $chk2->get_result()->fetch_assoc()['c'] > 0;
            $chk2->close();

            if ($has_data) {
                $error = "Cannot delete a month that already has data. Please remove the data first.";
            } else {
                $del = $conn->prepare("DELETE FROM months WHERE id = ?");
                $del->bind_param("i", $month_id);
                if ($del->execute()) {
                    $_SESSION['last_deleted_month'] = $month_id;
                    header('Location: ' . site_base() . '/months.php?deleted=1');
                    exit;
                } else {
                    $error = "Error deleting month: " . $conn->error;
                }
                $del->close();
            }
            break;
    }
}

// ── Success / redirect messages ───────────────────────────────────────────────
if (isset($_GET['success']) && $success === '') $success = "Month created successfully!";
if (isset($_GET['deleted']) && $success === '') $success = "Month deleted successfully!";

// ── Fetch all months ──────────────────────────────────────────────────────────
$months_result = $conn->query("SELECT * FROM months ORDER BY start_date DESC");
if (!$months_result) die("Database error: " . $conn->error);

// ── Statistics ────────────────────────────────────────────────────────────────
$stats = $conn->query("
    SELECT
        COUNT(*) AS total_months,
        SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END) AS draft_months,
        SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted_months,
        MIN(start_date) AS earliest_month,
        MAX(end_date)   AS latest_month
    FROM months
")->fetch_assoc();

/* ═══ RENDER: Tailwind view inside shared app layout ═══ */
ob_start();
require __DIR__ . '/frontend/src/pages/reporting-months.php';
$content = ob_get_clean();

if (isset($conn)) $conn->close();

$pageTitle   = 'Months · MUWASCO Monthly Report';
$currentPage = 'months.php';
require __DIR__ . '/frontend/src/layouts/app-layout.php';

