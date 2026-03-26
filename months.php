<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session FIRST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
require_once 'auth_functions.php';
require_once 'role_functions.php';
require_once 'email_config.php';

// PHPMailer — adjust path if using Composer autoload
// Option A: Composer  → require_once 'vendor/autoload.php';
// Option B: Manual    → require the three files below
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'vendor/autoload.php'; // ← change to manual paths if needed

// Require login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Send month-creation notification to all active data-entry employees
// ─────────────────────────────────────────────────────────────────────────────
function sendMonthCreatedEmails($conn, $monthName, $startDate, $endDate, $createdBy) {
    // Fetch every active user who has an email address (exclude admin role_id = 1 if desired)
    $sql  = "SELECT first_name, last_name, email FROM users 
             WHERE is_active = 1 AND email IS NOT NULL AND email != ''";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) {
        return ['sent' => 0, 'failed' => 0, 'errors' => ['No active users found.']];
    }

    $sent   = 0;
    $failed = 0;
    $errors = [];

    $formattedStart = date('F j, Y', strtotime($startDate));
    $formattedEnd   = date('F j, Y', strtotime($endDate));

    while ($user = $result->fetch_assoc()) {
        $fullName   = trim($user['first_name'] . ' ' . $user['last_name']);
        $emailBody  = buildEmailBody($fullName, $monthName, $formattedStart, $formattedEnd, $createdBy);

        $mail = new PHPMailer(true);
        try {
            // ── Server settings ──────────────────────────────────────────────
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT;

            // ── Recipients ───────────────────────────────────────────────────
            $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
            $mail->addAddress($user['email'], $fullName);

            // ── Content ──────────────────────────────────────────────────────
            $mail->isHTML(true);
            $mail->Subject = "📅 New Reporting Month Created: {$monthName}";
            $mail->Body    = $emailBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $emailBody));

            $mail->send();
            $sent++;
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Failed to send to {$user['email']}: " . $mail->ErrorInfo;
        }
    }

    return compact('sent', 'failed', 'errors');
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Build HTML email body
// ─────────────────────────────────────────────────────────────────────────────
function buildEmailBody($recipientName, $monthName, $startDate, $endDate, $createdBy) {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <style>
    body        { margin:0; padding:0; background:#f4f6f9; font-family:Arial,sans-serif; }
    .wrapper    { max-width:600px; margin:40px auto; background:#fff; border-radius:10px;
                  box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
    .header     { background:linear-gradient(135deg,#1a6fc4,#2196f3); padding:32px 36px; text-align:center; }
    .header h1  { color:#fff; margin:0; font-size:22px; letter-spacing:.5px; }
    .header p   { color:rgba(255,255,255,.85); margin:6px 0 0; font-size:13px; }
    .body       { padding:32px 36px; }
    .greeting   { font-size:16px; color:#2c3e50; font-weight:600; margin-bottom:6px; }
    .intro      { color:#555; font-size:14px; line-height:1.7; margin-bottom:24px; }
    .info-box   { background:#f0f7ff; border-left:4px solid #2196f3; border-radius:6px;
                  padding:18px 22px; margin-bottom:24px; }
    .info-box p { margin:5px 0; font-size:14px; color:#333; }
    .info-box strong { display:inline-block; width:110px; color:#1a6fc4; }
    .action-btn { display:block; width:fit-content; margin:0 auto 28px;
                  background:#2196f3; color:#fff; text-decoration:none;
                  padding:13px 32px; border-radius:6px; font-size:15px; font-weight:600;
                  letter-spacing:.4px; }
    .note       { font-size:12px; color:#888; line-height:1.6; border-top:1px solid #eee;
                  padding-top:18px; margin-top:10px; }
    .footer     { background:#f4f6f9; text-align:center; padding:18px;
                  font-size:11px; color:#aaa; }
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
    <p class="intro">
      A new reporting month has been created in <strong>AquaTrack Pro</strong>.
      Please log in and submit your section of the monthly report before the deadline.
    </p>

    <div class="info-box">
      <p><strong>📋 Month:</strong> {$monthName}</p>
      <p><strong>📅 Start Date:</strong> {$startDate}</p>
      <p><strong>🏁 End Date:</strong> {$endDate}</p>
      <p><strong>👤 Created By:</strong> {$createdBy}</p>
      <p><strong>⚡ Status:</strong> Draft — data entry now open</p>
    </div>

    <a href="hmuwasco.co.ke/monthly_report/" class="action-btn">
      ➜ &nbsp; Enter My Data Now
    </a>

    <p class="note">
      This is an automated notification from the MUWASCO AquaTrack Pro system.
      Please do not reply to this email. If you need assistance, contact your system administrator.
    </p>
  </div>
  <div class="footer">
    © 2025 MUWASCO · AquaTrack Pro · All rights reserved
  </div>
</div>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// MAIN FORM HANDLING
// ─────────────────────────────────────────────────────────────────────────────
$success      = '';
$error        = '';
$emailStatus  = null; // holds email send result after month creation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // ─────────────── CREATE MONTH ───────────────────────────────────
            case 'create_month':
                $name       = trim($_POST['name']);
                $start_date = $_POST['start_date'];
                $end_date   = $_POST['end_date'];

                // ── Basic validation ─────────────────────────────────────────
                if (empty($name) || empty($start_date) || empty($end_date)) {
                    $error = "Please fill in all required fields.";
                    break;
                }

                $start_timestamp = strtotime($start_date);
                $end_timestamp   = strtotime($end_date);

                if (!$start_timestamp || !$end_timestamp) {
                    $error = "Invalid date format.";
                    break;
                }

                if ($end_timestamp < $start_timestamp) {
                    $error = "End date must be after start date.";
                    break;
                }

                // ── Same-month validation ─────────────────────────────────────
                if (date('Y-m', $start_timestamp) !== date('Y-m', $end_timestamp)) {
                    $error = "Start and end dates must be within the same calendar month.";
                    break;
                }

                $month_year = date('Y-m', $start_timestamp);

                try {
                    // Duplicate month-year check
                    $check_stmt = $conn->prepare("SELECT COUNT(*) AS count FROM months WHERE month_year = ?");
                    $check_stmt->bind_param("s", $month_year);
                    $check_stmt->execute();
                    $dup = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();

                    if ($dup['count'] > 0) {
                        $error = "A month for " . date('F Y', $start_timestamp) . " already exists.";
                        break;
                    }

                    // Overlapping date-range check
                    $overlap_check = $conn->prepare("
                        SELECT COUNT(*) AS count FROM months
                        WHERE (start_date <= ? AND end_date >= ?)
                           OR (start_date <= ? AND end_date >= ?)
                           OR (? <= start_date AND ? >= end_date)
                    ");
                    $overlap_check->bind_param("ssssss",
                        $start_date, $start_date,
                        $end_date,   $end_date,
                        $start_date, $end_date
                    );
                    $overlap_check->execute();
                    $overlap = $overlap_check->get_result()->fetch_assoc();
                    $overlap_check->close();

                    if ($overlap['count'] > 0) {
                        $error = "This date range overlaps with an existing month.";
                        break;
                    }

                    // Get creator username
                    $user_info  = getUserInfo($conn, $_SESSION['user_id'] ?? null);
                    $created_by = $user_info['username'] ?? 'System';

                    // Insert new month
                    $stmt = $conn->prepare(
                        "INSERT INTO months (name, month_year, start_date, end_date, status, created_by)
                         VALUES (?, ?, ?, ?, 'draft', ?)"
                    );
                    $stmt->bind_param("sssss", $name, $month_year, $start_date, $end_date, $created_by);

                    if ($stmt->execute()) {
                        $new_month_id = $conn->insert_id;
                        $_SESSION['last_created_month'] = $new_month_id;

                        // ── Send email notifications ──────────────────────────
                        $emailStatus = sendMonthCreatedEmails(
                            $conn,
                            $name,
                            $start_date,
                            $end_date,
                            $created_by
                        );

                        // Store email result in session for display after redirect
                        $_SESSION['email_status'] = $emailStatus;

                        header("Location: months.php?success=1");
                        exit();
                    } else {
                        $error = ($conn->errno === 1062)
                            ? "Duplicate entry. A month with this name or period already exists."
                            : "Database error: " . $conn->error . " (Error code: " . $conn->errno . ")";
                    }
                    $stmt->close();

                } catch (Exception $e) {
                    $error = "Error creating month: " . $e->getMessage();
                }
                break;

            // ─────────────── DELETE MONTH ───────────────────────────────────
            case 'delete_month':
                $month_id = intval($_POST['month_id']);

                $check = $conn->prepare("SELECT COUNT(*) AS count FROM months WHERE id = ?");
                $check->bind_param("i", $month_id);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();

                if ($exists['count'] === 0) {
                    $error = "Month not found.";
                    break;
                }

                $check_data = $conn->prepare("SELECT COUNT(*) AS count FROM monthly_data WHERE month_id = ?");
                $check_data->bind_param("i", $month_id);
                $check_data->execute();
                $data_exists = $check_data->get_result()->fetch_assoc();
                $check_data->close();

                if ($data_exists['count'] > 0) {
                    $error = "Cannot delete a month that already has data. Please remove the data first.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM months WHERE id = ?");
                    $stmt->bind_param("i", $month_id);
                    if ($stmt->execute()) {
                        $_SESSION['last_deleted_month'] = $month_id;
                        header("Location: months.php?deleted=1");
                        exit();
                    } else {
                        $error = "Error deleting month: " . $conn->error;
                    }
                    $stmt->close();
                }
                break;
        }
    }
}

// ── Success / redirect messages ───────────────────────────────────────────────
if (isset($_GET['success'])) {
    $success = "Month created successfully!";
}
if (isset($_GET['deleted'])) {
    $success = "Month deleted successfully!";
}

// Pull email result from session (set before redirect)
if (isset($_SESSION['email_status'])) {
    $emailStatus = $_SESSION['email_status'];
    unset($_SESSION['email_status']);
}

// ── Fetch all months ──────────────────────────────────────────────────────────
$months_result = $conn->query("SELECT * FROM months ORDER BY start_date DESC");
if (!$months_result) {
    die("Database error: " . $conn->error);
}
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
        /* ── Email notification banner ───────────────────────────────────── */
        .alert-email {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-email.success {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
        }
        .alert-email.warning {
            background: #fff8e1;
            border: 1px solid #ffe082;
            color: #f57f17;
        }
        .alert-email .email-icon { font-size: 20px; flex-shrink: 0; }
        .alert-email ul { margin: 6px 0 0 0; padding-left: 18px; font-size: 12px; }
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
                        <div class="admin-badge">
                            <i class="bi bi-shield-check"></i> Administrator Access
                        </div>
                    </div>

                    <!-- ── Success Alert ───────────────────────────────────── -->
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <span class="alert-icon">✅</span>
                            <div>
                                <strong>Success!</strong><br>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ── Email Notification Result ──────────────────────── -->
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
                        <?php elseif ($emailStatus['sent'] > 0 && $emailStatus['failed'] > 0): ?>
                            <div class="alert-email warning">
                                <span class="email-icon">⚠️</span>
                                <div>
                                    <strong>Partial Email Delivery</strong><br>
                                    Sent to <strong><?php echo $emailStatus['sent']; ?></strong> employee(s),
                                    but <strong><?php echo $emailStatus['failed']; ?></strong> failed.
                                    <?php if (!empty($emailStatus['errors'])): ?>
                                        <ul>
                                            <?php foreach ($emailStatus['errors'] as $err): ?>
                                                <li><?php echo htmlspecialchars($err); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
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
                                        <ul>
                                            <?php foreach ($emailStatus['errors'] as $err): ?>
                                                <li><?php echo htmlspecialchars($err); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- ── Error Alert ────────────────────────────────────── -->
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <span class="alert-icon">⚠️</span>
                            <div>
                                <strong>Error!</strong><br>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ── Create Month Card ──────────────────────────────── -->
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
                                        <input type="text"
                                               name="name"
                                               class="form-control"
                                               placeholder="e.g., December 2024"
                                               required>
                                        <span class="form-hint">Display name for the reporting period</span>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Start Date *</label>
                                        <input type="date"
                                               name="start_date"
                                               class="form-control"
                                               required
                                               onchange="updateEndDate()">
                                        <span class="form-hint">First day of the reporting period</span>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">End Date *</label>
                                        <input type="date"
                                               name="end_date"
                                               class="form-control"
                                               required>
                                        <span class="form-hint">Last day of the reporting period (auto-set)</span>
                                    </div>
                                </div>

                                <div class="form-submit">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="bi bi-plus-circle"></i> Create Month &amp; Notify Employees
                                    </button>
                                    <span class="form-hint" style="margin-top:8px;display:block;">
                                        <i class="bi bi-envelope"></i>
                                        All active employees will receive an email notification upon creation.
                                    </span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ── Months List Card ───────────────────────────────── -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="bi bi-calendar-month"></i> Existing Months</h2>
                            <span class="badge badge-info"><?php echo $months_result->num_rows; ?> months</span>
                        </div>
                        <div class="card-body">
                            <?php if ($months_result->num_rows === 0): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="bi bi-calendar-x"></i>
                                    </div>
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
                                                $is_new     = isset($_SESSION['last_created_month']) && $_SESSION['last_created_month'] == $month['id'];
                                                $is_deleted = isset($_SESSION['last_deleted_month'])  && $_SESSION['last_deleted_month']  == $month['id'];
                                            ?>
                                            <tr <?php echo $is_new ? 'class="new-row"' : ($is_deleted ? 'class="deleted-row"' : ''); ?>>
                                                <td>
                                                    <span class="badge badge-secondary">#<?php echo $month['id']; ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($month['name'] ?? date('F Y', strtotime($month['month_year']))); ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('F Y', strtotime($month['month_year'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="date-range">
                                                        <span class="date-label"><i class="bi bi-calendar-check"></i> Start:</span>
                                                        <?php echo date('M d, Y', strtotime($month['start_date'])); ?>
                                                        <br>
                                                        <span class="date-label"><i class="bi bi-calendar-x"></i> End:</span>
                                                        <?php echo date('M d, Y', strtotime($month['end_date'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($month['status'] === 'draft'): ?>
                                                        <span class="status-badge status-draft">
                                                            <i class="bi bi-pencil"></i> Draft
                                                        </span>
                                                    <?php elseif ($month['status'] === 'submitted'): ?>
                                                        <span class="status-badge status-submitted">
                                                            <i class="bi bi-check-circle"></i> Submitted
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="status-badge">
                                                            <?php echo ucfirst($month['status']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="created-info">
                                                        <?php echo date('M d, Y', strtotime($month['created_at'])); ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <i class="bi bi-person"></i>
                                                            <?php echo htmlspecialchars($month['created_by']); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($month['status'] === 'draft'): ?>
                                                            <a href="add_data.php?month_id=<?php echo $month['id']; ?>"
                                                               class="btn btn-info btn-sm"
                                                               title="Enter data for this month">
                                                                <i class="bi bi-pencil"></i> Enter Data
                                                            </a>
                                                            <form method="POST"
                                                                  onsubmit="return confirm('Are you sure you want to delete this month? This action cannot be undone.');"
                                                                  style="display:inline;">
                                                                <input type="hidden" name="action" value="delete_month">
                                                                <input type="hidden" name="month_id" value="<?php echo $month['id']; ?>">
                                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete this month">
                                                                    <i class="bi bi-trash"></i> Delete
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <a href="add_data.php?month_id=<?php echo $month['id']; ?>"
                                                               class="btn btn-info btn-sm"
                                                               title="View data for this month">
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

                    <!-- ── Statistics Card ────────────────────────────────── -->
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="bi bi-graph-up"></i> Month Statistics</h2>
                        </div>
                        <div class="card-body">
                            <?php
                            $stats = $conn->query("
                                SELECT
                                    COUNT(*)                                            AS total_months,
                                    SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END) AS draft_months,
                                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted_months,
                                    MIN(start_date)                                     AS earliest_month,
                                    MAX(end_date)                                       AS latest_month
                                FROM months
                            ")->fetch_assoc();
                            ?>
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

        // Last day of the same month
        const lastDay    = new Date(year, month + 1, 0);
        endInput.value   = lastDay.toISOString().split('T')[0];

        // Auto-fill name if still empty
        if (!nameInput.value.trim()) {
            nameInput.value = d.toLocaleString('default', { month: 'long', year: 'numeric' });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const startInput = document.querySelector('input[name="start_date"]');
        const endInput   = document.querySelector('input[name="end_date"]');
        const nameInput  = document.querySelector('input[name="name"]');

        // Default to previous calendar month
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

        // Button loading state on submit
        document.getElementById('createMonthForm').addEventListener('submit', function () {
            const btn   = document.getElementById('submitBtn');
            btn.innerHTML = '<span class="spinner"></span> Creating &amp; Sending Emails…';
            btn.disabled  = true;
        });

        // Fade out highlighted new/deleted rows after 5 s
        document.querySelectorAll('.new-row, .deleted-row').forEach(row => {
            setTimeout(() => row.classList.remove('new-row', 'deleted-row'), 5000);
        });
    });
</script>
</body>
</html>