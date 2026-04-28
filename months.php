<?php

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);   // OFF in production — output breaks redirects
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// ── Session BEFORE every require_once ────────────────────────────────────────
// Must match the session_start() style used in login.php and every other page.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth check immediately — before any heavy includes ───────────────────────
if (!isset($_SESSION['user_id'])) {
    // Do NOT store redirect_url here; months.php is a top-level nav page.
    // Storing it causes login.php to loop back to months.php → loop.
    ob_end_clean();
    header('Location: login.php');
    exit;
}

// ── Core includes ─────────────────────────────────────────────────────────────
require_once 'db.php';
require_once 'auth_functions.php';
require_once 'role_functions.php';
require_once 'email_config.php';

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

                    ob_end_clean();
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
                    ob_end_clean();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Month Management - AquaTrack Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert-email{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:8px;margin-bottom:16px;font-size:14px;}
        .alert-email.success{background:#e8f5e9;border:1px solid #a5d6a7;color:#2e7d32;}
        .alert-email.warning{background:#fff8e1;border:1px solid #ffe082;color:#f57f17;}
        .alert-email .email-icon{font-size:20px;flex-shrink:0;}
        .alert-email ul{margin:6px 0 0;padding-left:18px;font-size:12px;}
        .phpmailer-warn{background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 16px;
                        margin-bottom:14px;font-size:13px;color:#856404;}
    </style>
</head>
<body>
<div class="main-container">
    <?php include 'nav_bar.php'; ?>

    <div class="main-content">
        <div class="page-content">
            <div class="content-wrapper">
                <div class="container">

                    <!-- Page Header -->
                    <div class="page-header">
                        <h1>📅 Month Management</h1>
                        <p>Create and manage reporting periods for water system data collection</p>
                        <div class="admin-badge"><i class="bi bi-shield-check"></i> Administrator Access</div>
                    </div>

                    <!-- PHPMailer notice if not available -->
                    <?php if (!$phpmailer_available): ?>
                    <div class="phpmailer-warn">
                        <i class="bi bi-envelope-exclamation"></i>
                        <strong>Email not configured:</strong> PHPMailer could not be loaded.
                        Months can still be created, but employees will not receive email notifications.
                        Run <code>composer require phpmailer/phpmailer</code> to enable emails.
                    </div>
                    <?php endif; ?>

                    <!-- Success -->
                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <span class="alert-icon">✅</span>
                        <div><strong>Success!</strong><br><?php echo htmlspecialchars($success); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Email result -->
                    <?php if ($emailStatus): ?>
                        <?php if ($emailStatus['sent'] > 0 && $emailStatus['failed'] === 0): ?>
                        <div class="alert-email success">
                            <span class="email-icon">📧</span>
                            <div>
                                <strong>Email Notifications Sent</strong><br>
                                Successfully notified <strong><?php echo $emailStatus['sent']; ?></strong>
                                employee<?php echo $emailStatus['sent'] > 1 ? 's' : ''; ?> about the new reporting month.
                            </div>
                        </div>
                        <?php elseif ($emailStatus['sent'] > 0): ?>
                        <div class="alert-email warning">
                            <span class="email-icon">⚠️</span>
                            <div>
                                <strong>Partial Email Delivery</strong><br>
                                Sent to <strong><?php echo $emailStatus['sent']; ?></strong>, failed for <strong><?php echo $emailStatus['failed']; ?></strong>.
                                <?php if (!empty($emailStatus['errors'])): ?>
                                <ul><?php foreach ($emailStatus['errors'] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert-email warning">
                            <span class="email-icon">📭</span>
                            <div>
                                <strong>Email Notifications Failed</strong><br>
                                The month was created, but no notification emails could be sent.
                                <?php if (!empty($emailStatus['errors'])): ?>
                                <ul><?php foreach ($emailStatus['errors'] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Error -->
                    <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <span class="alert-icon">⚠️</span>
                        <div><strong>Error!</strong><br><?php echo htmlspecialchars($error); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Create Month Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="bi bi-plus-circle"></i> Create New Month</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="createMonthForm">
                                <input type="hidden" name="action" value="create_month">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Month Name *</label>
                                        <input type="text" name="name" class="form-control"
                                               placeholder="e.g., December 2024" required>
                                        <span class="form-hint">Display name for the reporting period</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Start Date *</label>
                                        <input type="date" name="start_date" class="form-control"
                                               required onchange="updateEndDate()">
                                        <span class="form-hint">First day of the reporting period</span>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">End Date *</label>
                                        <input type="date" name="end_date" class="form-control" required>
                                        <span class="form-hint">Last day of the reporting period (auto-set)</span>
                                    </div>
                                </div>
                                <div class="form-submit">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="bi bi-plus-circle"></i> Create Month<?php echo $phpmailer_available ? ' &amp; Notify Employees' : ''; ?>
                                    </button>
                                    <?php if ($phpmailer_available): ?>
                                    <span class="form-hint" style="margin-top:8px;display:block;">
                                        <i class="bi bi-envelope"></i>
                                        All active employees will receive an email notification upon creation.
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Months List Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="bi bi-calendar-month"></i> Existing Months</h2>
                            <span class="badge badge-info"><?php echo $months_result->num_rows; ?> months</span>
                        </div>
                        <div class="card-body">
                            <?php if ($months_result->num_rows === 0): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="bi bi-calendar-x"></i></div>
                                <h3>No Months Created Yet</h3>
                                <p>Start by creating your first reporting month using the form above.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Month Details</th>
                                            <th>Reporting Period</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while ($month = $months_result->fetch_assoc()):
                                        $is_new = isset($_SESSION['last_created_month']) && $_SESSION['last_created_month'] == $month['id'];
                                    ?>
                                    <tr <?php echo $is_new ? 'class="new-row"' : ''; ?>>
                                        <td><span class="badge badge-secondary">#<?php echo $month['id']; ?></span></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($month['name'] ?? date('F Y', strtotime($month['month_year']))); ?></strong><br>
                                            <small class="text-muted"><?php echo date('F Y', strtotime($month['month_year'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="date-range">
                                                <span class="date-label"><i class="bi bi-calendar-check"></i> Start:</span>
                                                <?php echo date('M d, Y', strtotime($month['start_date'])); ?><br>
                                                <span class="date-label"><i class="bi bi-calendar-x"></i> End:</span>
                                                <?php echo date('M d, Y', strtotime($month['end_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($month['status'] === 'draft'): ?>
                                            <span class="status-badge status-draft"><i class="bi bi-pencil"></i> Draft</span>
                                            <?php elseif ($month['status'] === 'submitted'): ?>
                                            <span class="status-badge status-submitted"><i class="bi bi-check-circle"></i> Submitted</span>
                                            <?php else: ?>
                                            <span class="status-badge"><?php echo ucfirst($month['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($month['created_at'])); ?><br>
                                            <small class="text-muted">
                                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($month['created_by']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($month['status'] === 'draft'): ?>
                                                <!-- Use absolute URL to avoid redirect issues in production -->
                                                <a href="<?php echo site_base(); ?>/add_data.php?month_id=<?php echo $month['id']; ?>"
                                                   class="btn btn-info btn-sm" title="Enter data for this month">
                                                    <i class="bi bi-pencil"></i> Enter Data
                                                </a>
                                                <form method="POST" style="display:inline;"
                                                      onsubmit="return confirm('Are you sure you want to delete this month? This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_month">
                                                    <input type="hidden" name="month_id" value="<?php echo $month['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <a href="<?php echo site_base(); ?>/add_data.php?month_id=<?php echo $month['id']; ?>"
                                                   class="btn btn-info btn-sm" title="View data for this month">
                                                    <i class="bi bi-eye"></i> View Data
                                                </a>
                                                <span class="text-muted small" style="display:block;margin-top:5px;">
                                                    <i class="bi bi-lock"></i> Submitted (read-only)
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile;
                                    unset($_SESSION['last_created_month']);
                                    unset($_SESSION['last_deleted_month']);
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Statistics Card -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="bi bi-graph-up"></i> Month Statistics</h2>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['total_months']; ?></div>
                                    <div class="stat-label">Total Months</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['draft_months']; ?></div>
                                    <div class="stat-label">Draft Months</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $stats['submitted_months']; ?></div>
                                    <div class="stat-label">Submitted Months</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">
                                        <?php echo $stats['latest_month'] ? date('M Y', strtotime($stats['latest_month'])) : '—'; ?>
                                    </div>
                                    <div class="stat-label">Latest Month</div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /.container -->
            </div>
        </div>
    </div>
</div><!-- /.main-container -->

<script>
function updateEndDate() {
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput   = document.querySelector('input[name="end_date"]');
    const nameInput  = document.querySelector('input[name="name"]');
    if (!startInput.value) return;

    const d     = new Date(startInput.value);
    const year  = d.getFullYear();
    const month = d.getMonth();

    const lastDay  = new Date(year, month + 1, 0);
    endInput.value = lastDay.toISOString().split('T')[0];

    if (!nameInput.value.trim()) {
        nameInput.value = d.toLocaleString('default', { month: 'long', year: 'numeric' });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput   = document.querySelector('input[name="end_date"]');
    const nameInput  = document.querySelector('input[name="name"]');

    const today     = new Date();
    const prevMonth = today.getMonth() === 0 ? 11 : today.getMonth() - 1;
    const prevYear  = today.getMonth() === 0 ? today.getFullYear() - 1 : today.getFullYear();

    const firstDay = new Date(prevYear, prevMonth, 1);
    const lastDay  = new Date(prevYear, prevMonth + 1, 0);

    startInput.value = firstDay.toISOString().split('T')[0];
    endInput.value   = lastDay.toISOString().split('T')[0];

    if (!nameInput.value.trim()) {
        nameInput.value = firstDay.toLocaleString('default', { month: 'long', year: 'numeric' });
    }

    nameInput.focus();

    document.getElementById('createMonthForm').addEventListener('submit', function () {
        const btn     = document.getElementById('submitBtn');
        btn.innerHTML = '<span class="spinner"></span> Creating…';
        btn.disabled  = true;
    });

    document.querySelectorAll('.new-row').forEach(row => {
        setTimeout(() => row.classList.remove('new-row'), 5000);
    });
});
</script>
</body>
</html>
<?php
// Flush output buffer cleanly
ob_end_flush();
if (isset($conn)) $conn->close();
?>