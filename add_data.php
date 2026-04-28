<?php
/**
 * add_data.php — Monthly Data Entry, Validation & Manager Approval
 * Roles: admin (edit + notify), technical_manager / commercial_manager (approve)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'auth_functions.php';
require_once 'role_functions.php';
require_once 'email_config.php';

// ═══════════════════════════════════════════════════════════════════
// 1. PHPMAILER BOOTSTRAP
// ═══════════════════════════════════════════════════════════════════
$phpmailer_loaded = false;
foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/phpmailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
] as $path) {
    if (!file_exists($path)) continue;
    require_once $path;
    if (strpos($path, 'autoload.php') === false) {
        $d = dirname($path);
        foreach (['SMTP.php', 'Exception.php'] as $f)
            if (file_exists("$d/$f")) require_once "$d/$f";
    }
    $phpmailer_loaded = true;
    break;
}

// ═══════════════════════════════════════════════════════════════════
// 2. SMTP SEND — single reusable function
// ═══════════════════════════════════════════════════════════════════
function smtp_send(string $to_email, string $to_name, string $subject, string $html): bool {
    global $phpmailer_loaded;
    if (!$phpmailer_loaded) { error_log("PHPMailer missing — cannot email $to_email"); return false; }
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

// ═══════════════════════════════════════════════════════════════════
// 3. EMAIL TEMPLATES — professional design
// ═══════════════════════════════════════════════════════════════════
function email_wrap(string $accent, string $icon_emoji, string $title, string $body_html): string {
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

/**
 * Email 1 — Sent to manager asking them to review the report.
 */
function mail_review_request(int $month_id, string $mgr_role, array $mgr, string $token): bool {
    global $conn;
    $s = $conn->prepare("SELECT * FROM months WHERE id = ?");
    $s->bind_param("i", $month_id); $s->execute();
    $r = $s->get_result(); $month = $r->fetch_assoc(); $r->free(); $s->close();

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

    $html = email_wrap(
        'linear-gradient(135deg,#1565c0,#1976d2)',
        '📋',
        'Monthly Report — Review &amp; Approval Required',
        $body
    );

    return smtp_send($mgr['email'], $name,
        "Action Required: Please Review and Approve the {$month['name']} Monthly Report",
        $html);
}

/**
 * Email 2 — Sent to all admins after a manager approves or rejects.
 */
function mail_admin_decision(int $month_id, string $mgr_role, array $mgr, string $action, string $reason = ''): int {
    global $conn;
    $s = $conn->prepare("SELECT * FROM months WHERE id = ?");
    $s->bind_param("i", $month_id); $s->execute();
    $r = $s->get_result(); $month = $r->fetch_assoc(); $r->free(); $s->close();

    $q      = $conn->query("SELECT u.email, u.first_name, u.last_name, u.username FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name='admin' AND u.email IS NOT NULL AND u.email != ''");
    $admins = []; while ($row = $q->fetch_assoc()) $admins[] = $row; $q->free();

    $title    = $mgr_role === 'technical_manager' ? 'Technical Manager' : 'Commercial Manager';
    $mgr_name = trim("{$mgr['first_name']} {$mgr['last_name']}") ?: $mgr['username'];
    $ts       = date('d M Y \a\t H:i');
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

    $html = email_wrap($accent, $emoji, $header, $body);

    $sent = 0;
    foreach ($admins as $admin) {
        $aname = trim("{$admin['first_name']} {$admin['last_name']}") ?: $admin['username'];
        if (smtp_send($admin['email'], $aname, $subj, $html)) $sent++;
    }
    return $sent;
}

// ═══════════════════════════════════════════════════════════════════
// 4. HELPERS
// ═══════════════════════════════════════════════════════════════════
function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function base_url(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

/** Fetch one row, free & close. */
function db_row(string $sql, string $types, ...$vals): ?array {
    global $conn;
    $s = $conn->prepare($sql); $s->bind_param($types, ...$vals); $s->execute();
    $r = $s->get_result(); $row = $r->fetch_assoc(); $r->free(); $s->close();
    return $row ?: null;
}

/** Fetch all rows, free & close. */
function db_rows(string $sql, string $types = '', ...$vals): array {
    global $conn;
    if ($types === '') {
        $r = $conn->query($sql); $rows = []; while ($row = $r->fetch_assoc()) $rows[] = $row; $r->free(); return $rows;
    }
    $s = $conn->prepare($sql); $s->bind_param($types, ...$vals); $s->execute();
    $r = $s->get_result(); $rows = []; while ($row = $r->fetch_assoc()) $rows[] = $row; $r->free(); $s->close();
    return $rows;
}

/** Execute a write statement, return bool. */
function db_exec(string $sql, string $types, ...$vals): bool {
    global $conn;
    $s = $conn->prepare($sql); $s->bind_param($types, ...$vals); $ok = $s->execute(); $s->close();
    return $ok;
}

// ═══════════════════════════════════════════════════════════════════
// 5. DOMAIN HELPERS
// ═══════════════════════════════════════════════════════════════════
function getApproval(int $month_id, string $role): ?array {
    return db_row("SELECT * FROM month_approvals WHERE month_id=? AND manager_role=?", "is", $month_id, $role);
}

function catsFilled(int $month_id, array $cats): bool {
    if (empty($cats)) return false;
    $filled = 0;
    foreach ($cats as $cid) {
        $row = db_row(
            "SELECT COUNT(*) AS t,
                    SUM(CASE WHEN md.value IS NULL OR TRIM(md.value)='' THEN 1 ELSE 0 END) AS e
             FROM parameters p
             LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=?
             WHERE p.category_id=?",
            "ii", $month_id, $cid
        );
        if ($row && $row['t'] > 0 && $row['e'] == 0) $filled++;
    }
    return $filled === count($cats);
}

function getCategoriesWithParams(int $role_id, bool $is_admin): array {
    global $conn;
    // Step 1 – categories
    if ($is_admin) {
        $s = $conn->prepare("SELECT DISTINCT pc.* FROM parameter_categories pc JOIN parameters p ON pc.id=p.category_id ORDER BY pc.display_order");
        $s->execute();
    } else {
        $s = $conn->prepare("SELECT DISTINCT pc.* FROM parameter_categories pc JOIN parameters p ON pc.id=p.category_id JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? ORDER BY pc.display_order");
        $s->bind_param("i", $role_id); $s->execute();
    }
    $r = $s->get_result(); $cats = []; while ($row = $r->fetch_assoc()) $cats[] = $row; $r->free(); $s->close();

    // Step 2 – parameters per category
    $out = [];
    foreach ($cats as $cat) {
        $params = $is_admin
            ? db_rows("SELECT p.* FROM parameters p WHERE p.category_id=? ORDER BY p.code", "i", $cat['id'])
            : db_rows("SELECT p.* FROM parameters p JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? AND p.category_id=? ORDER BY p.code", "ii", $role_id, $cat['id']);
        if (!empty($params)) $out[] = ['category' => $cat, 'parameters' => $params];
    }
    return $out;
}

function isSaved(int $month_id, int $cat_id, int $role_id, bool $is_admin): bool {
    $ids = $is_admin
        ? array_column(db_rows("SELECT id FROM parameters WHERE category_id=?", "i", $cat_id), 'id')
        : array_column(db_rows("SELECT p.id FROM parameters p JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? AND p.category_id=?", "ii", $role_id, $cat_id), 'id');
    if (empty($ids)) return false;
    $in  = implode(',', array_map('intval', $ids));
    $row = db_row("SELECT COUNT(*) AS n FROM monthly_data WHERE month_id=? AND parameter_id IN($in)", "i", $month_id);
    return $row && $row['n'] > 0;
}

function validateSection(int $month_id, int $cat_id): true|array {
    $rows  = db_rows("SELECT p.code, p.label, p.required, md.value FROM parameters p LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=? WHERE p.category_id=? ORDER BY p.code", "ii", $month_id, $cat_id);
    $miss  = [];
    $empty = [];
    foreach ($rows as $r) {
        $v = trim($r['value'] ?? '');
        $e = $v === '';
        if ($r['required'] && $e) $miss[]  = ['code' => $r['code'], 'label' => $r['label']];
        if ($e)                   $empty[] = ['code' => $r['code'], 'label' => $r['label'], 'required' => $r['required']];
    }
    return (!empty($miss) || !empty($empty)) ? ['missing_required' => $miss, 'empty_values' => $empty] : true;
}

function aprBadge(string $status): string {
    $map = [
        'pending'  => ['clock',        '#6c757d', 'Not Notified'],
        'notified' => ['paper-plane',  '#e6a817', 'Awaiting Review'],
        'approved' => ['check-circle', '#28a745', 'Approved'],
        'rejected' => ['times-circle', '#dc3545', 'Rejected'],
    ];
    [$ic, $col, $lb] = $map[$status] ?? ['question-circle', '#999', ucfirst($status)];
    return "<span class='aprbadge $status' style='color:$col'><i class='fas fa-$ic'></i> $lb</span>";
}

// ═══════════════════════════════════════════════════════════════════
// 6. SESSION & AUTH
// ═══════════════════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php'); exit;
}
if (!isset($_GET['month_id'])) { header('Location: months.php'); exit; }

$month_id = intval($_GET['month_id']);
$month    = db_row("SELECT * FROM months WHERE id=?", "i", $month_id);
if (!$month) die("Month not found.");

$is_submitted = $month['status'] === 'submitted';
$user_id      = $_SESSION['user_id'];
$user_info    = db_row("SELECT u.*, r.name AS role_name, r.description AS role_description FROM users u LEFT JOIN roles r ON u.role_id=r.id WHERE u.id=?", "i", $user_id);
if (!$user_info) { session_destroy(); header('Location: login.php'); exit; }

$role_id    = (int)$user_info['role_id'];
$is_admin   = $user_info['role_name'] === 'admin';
$is_tech_mgr= $user_info['role_name'] === 'technical_manager';
$is_comm_mgr= $user_info['role_name'] === 'commercial_manager';

// Category assignment constants
const TECH_CATS = [12, 13, 14, 15, 16];
const COMM_CATS = [17, 18, 19, 20, 21, 22, 23, 24];
const ML_PARAMS  = [221, 222, 172, 174, 305]; // multiline textarea IDs

// ═══════════════════════════════════════════════════════════════════
// 7. POST HANDLERS
// ═══════════════════════════════════════════════════════════════════
$success = $error = $email_note = null;
$appr_token = trim($_GET['approval_token'] ?? '');
$appr_role  = trim($_GET['manager_role']   ?? '');
$is_POST    = $_SERVER['REQUEST_METHOD'] === 'POST';

// ── 7a. Manager: approve/reject via email token ──────────────────
if ($appr_token && $appr_role && ($is_tech_mgr || $is_comm_mgr) && $is_POST) {
    $va = db_row("SELECT * FROM month_approvals WHERE month_id=? AND manager_role=? AND approval_token=? AND token_expires_at>NOW()", "iss", $month_id, $appr_role, $appr_token);
    if ($va) {
        $resp   = $_POST['response'] ?? '';
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($resp === 'approve') {
            db_exec("UPDATE month_approvals SET status='approved', approved_at=NOW(), approved_by=? WHERE id=?", "ii", $user_id, $va['id']);
            $sent    = mail_admin_decision($month_id, $appr_role, $user_info, 'approve');
            $success = "Your approval has been recorded successfully." . ($sent ? " The administrator has been notified." : " Note: Admin email notification could not be sent.");
        } elseif ($resp === 'reject') {
            db_exec("UPDATE month_approvals SET status='rejected', rejection_reason=?, approved_at=NOW(), approved_by=? WHERE id=?", "sii", $reason, $user_id, $va['id']);
            $sent  = mail_admin_decision($month_id, $appr_role, $user_info, 'reject', $reason);
            $error = "Your feedback has been submitted." . ($sent ? " The administrator has been notified." : " Note: Admin email notification could not be sent.");
        }
    }
}

// ── 7b. Manager: direct approve (logged-in, no token) ───────────
if ($is_POST && ($is_tech_mgr || $is_comm_mgr) && ($_POST['action'] ?? '') === 'manager_direct_approve') {
    $my_role = $is_tech_mgr ? 'technical_manager' : 'commercial_manager';
    $my_cats = $is_tech_mgr ? TECH_CATS : COMM_CATS;
    if (!catsFilled($month_id, $my_cats)) {
        $error = "Approval cannot be submitted at this time — your assigned sections are not yet fully completed.";
    } else {
        $ex = db_row("SELECT id FROM month_approvals WHERE month_id=? AND manager_role=?", "is", $month_id, $my_role);
        if ($ex) {
            db_exec("UPDATE month_approvals SET status='approved', approved_at=NOW(), approved_by=? WHERE month_id=? AND manager_role=?", "iis", $user_id, $month_id, $my_role);
        } else {
            db_exec("INSERT INTO month_approvals (month_id, manager_role, status, approved_at, approved_by, notified_at) VALUES(?,?,'approved',NOW(),?,NOW())", "isi", $month_id, $my_role, $user_id);
        }
        $sent    = mail_admin_decision($month_id, $my_role, $user_info, 'approve');
        $success = "Your approval has been recorded. All data in your sections has been confirmed as accurate.";
        if (!$sent) $email_note = "The admin notification email could not be sent. Please verify your PHPMailer configuration.";
    }
}

// ── 7c. Admin: notify manager ────────────────────────────────────
if ($is_POST && $is_admin && ($_POST['action'] ?? '') === 'notify_manager') {
    $mgr_role = $_POST['manager_role'] ?? '';
    $mgr      = db_row("SELECT u.id, u.email, u.first_name, u.last_name, u.username FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name=? AND u.email IS NOT NULL AND u.email!='' LIMIT 1", "s", $mgr_role);
    if (!$mgr) {
        $error = "No user account was found for the role '$mgr_role' with a registered email address. Please check the user settings.";
    } else {
        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        $ex     = db_row("SELECT id FROM month_approvals WHERE month_id=? AND manager_role=?", "is", $month_id, $mgr_role);
        if ($ex) {
            db_exec("UPDATE month_approvals SET approval_token=?, token_expires_at=?, notified_at=NOW(), status='notified' WHERE month_id=? AND manager_role=?", "ssis", $token, $expiry, $month_id, $mgr_role);
        } else {
            db_exec("INSERT INTO month_approvals (month_id, manager_role, approval_token, token_expires_at, notified_at, status) VALUES(?,?,?,?,NOW(),'notified')", "isss", $month_id, $mgr_role, $token, $expiry);
        }
        $label   = $mgr_role === 'technical_manager' ? 'Technical' : 'Commercial';
        $mgr_name= trim("{$mgr['first_name']} {$mgr['last_name']}") ?: $mgr['username'];
        $ok      = mail_review_request($month_id, $mgr_role, $mgr, $token);
        if ($ok) {
            $success = "The review request has been sent to {$label} Manager {$mgr_name} ({$mgr['email']}) successfully.";
        } else {
            $success    = "The approval record has been saved, however the email to {$mgr['email']} could not be delivered.";
            $email_note = "PHPMailer Setup Required: (1) Run <code>composer require phpmailer/phpmailer</code> in your project root. (2) Confirm your Gmail App Password is correct — not your regular Google password. (3) Enable 2-Step Verification in Gmail, then create an App Password under Security → App Passwords. (4) In XAMPP php.ini, ensure <code>extension=openssl</code> is uncommented, then restart Apache. (5) For persistent SSL errors, uncomment the SMTPOptions line in <code>smtp_send()</code>.";
        }
    }
}

// ── 7d. Admin: save section ──────────────────────────────────────
if ($is_POST && $is_admin && ($_POST['action'] ?? '') === 'save_section') {
    $cat_id = intval($_POST['category_id']);
    $data   = $_POST['data'] ?? [];
    foreach ($data as $c => $v) { if (trim($v) === '') $data[$c] = '-'; }

    $conn->begin_transaction();
    try {
        foreach ($data as $c => $v) {
            $v   = trim($v);
            $pm  = db_row("SELECT id FROM parameters WHERE code=?", "s", $c);
            if ($pm) {
                if (!hasParameterAccess($pm['id'], $role_id, $is_admin))
                    throw new Exception("Permission denied for parameter: $c");
                db_exec("INSERT INTO monthly_data (month_id, parameter_id, value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", "iis", $month_id, $pm['id'], $v);
            }
        }
        $conn->commit();
        $success = "Section data saved successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "An error occurred while saving: " . $e->getMessage();
    }
}

// ── 7e. Admin: final submit ──────────────────────────────────────
if ($is_POST && $is_admin && ($_POST['action'] ?? '') === 'submit_final') {
    $all_cats = getCategoriesWithParams($role_id, $is_admin);
    $all_ok   = true;
    foreach ($all_cats as $cd) {
        if (validateSection($month_id, $cd['category']['id']) !== true) { $all_ok = false; break; }
    }
    if (!$all_ok) {
        $error = "The report cannot be submitted until all sections are completely and correctly filled.";
    } else {
        db_exec("UPDATE monthly_data md JOIN parameters p ON md.parameter_id=p.id SET md.value='-' WHERE md.month_id=? AND (md.value='' OR md.value IS NULL)", "i", $month_id);
        if (db_exec("UPDATE months SET status='submitted' WHERE id=?", "i", $month_id)) {
            $success      = "The monthly report has been successfully submitted and is now locked for editing.";
            $is_submitted = true;
            $month['status'] = 'submitted';
        } else {
            $error = "A database error occurred during submission. Please try again.";
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// 8. PAGE DATA
// ═══════════════════════════════════════════════════════════════════
$existing_data = [];
foreach (db_rows("SELECT p.code, md.value FROM monthly_data md JOIN parameters p ON md.parameter_id=p.id WHERE md.month_id=?", "i", $month_id) as $r)
    $existing_data[$r['code']] = $r['value'];

$cats_params   = getCategoriesWithParams($role_id, $is_admin);
$total_cats    = count($cats_params);
$saved_cats    = $complete_cats = 0;
foreach ($cats_params as $cd) {
    if (isSaved($month_id, $cd['category']['id'], $role_id, $is_admin)) {
        $saved_cats++;
        if (validateSection($month_id, $cd['category']['id']) === true) $complete_cats++;
    }
}

$tech_appr   = getApproval($month_id, 'technical_manager');
$comm_appr   = getApproval($month_id, 'commercial_manager');
$tech_filled = catsFilled($month_id, TECH_CATS);
$comm_filled = catsFilled($month_id, COMM_CATS);

$full_name = trim("{$user_info['first_name']} {$user_info['last_name']}") ?: $user_info['username'];
$pct       = $total_cats > 0 ? round($saved_cats / $total_cats * 100) : 0;
$cpct      = $total_cats > 0 ? round($complete_cats / $total_cats * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Entry — <?php echo he($month['name']); ?> — AquaTrack Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="style.css">
<style>
/* ── Layout ── */
.de-container{max-width:1380px;margin:0 auto;position:relative;z-index:2;}

/* ── Cards ── */
.glass-card{background:var(--glass-bg);backdrop-filter:blur(20px);border:1px solid var(--glass-border);border-radius:var(--radius-lg);margin-bottom:var(--spacing-lg);}

/* ── User info ── */
.user-card{padding:var(--spacing-lg);}
.user-row{display:flex;align-items:center;gap:var(--spacing-lg);flex-wrap:wrap;}
.avatar{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--primary-blue),var(--accent-cyan));display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:#fff;flex-shrink:0;}
.user-meta{flex:1;}
.user-meta h5{margin:0 0 4px;font-size:1.15rem;}
.user-stats{min-width:210px;}

/* ── Month header ── */
.month-header{background:linear-gradient(135deg,rgba(0,102,204,.18),rgba(0,168,255,.08));border-left:5px solid var(--accent-cyan);padding:var(--spacing-xl);margin-bottom:var(--spacing-xl);}
.month-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--spacing-md);margin-bottom:var(--spacing-md);}
.month-top h2{font-size:1.75rem;font-weight:800;margin:0 0 6px;background:linear-gradient(135deg,var(--text-primary),var(--accent-cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.month-period{color:var(--text-secondary);font-size:.95rem;}
.read-only-banner{background:rgba(23,162,184,.15);border:1px solid rgba(23,162,184,.35);padding:var(--spacing-md);border-radius:var(--radius-md);margin-top:var(--spacing-md);display:flex;align-items:center;gap:10px;}
.perm-note{background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);border-radius:var(--radius-md);padding:var(--spacing-md);margin-top:var(--spacing-md);color:#ffc107;font-size:.9rem;}
.quality-note{background:rgba(0,168,255,.08);border:1px solid rgba(0,168,255,.25);border-radius:var(--radius-sm);padding:10px var(--spacing-md);margin-top:var(--spacing-md);font-size:13px;color:var(--accent-cyan);display:flex;align-items:center;gap:8px;}

/* ── Approval panels ── */
.apr-panel{border-left:4px solid;padding:var(--spacing-lg);}
.apr-panel.tech{border-left-color:var(--primary-blue);}
.apr-panel.comm{border-left-color:var(--success-green);}
.apr-hdr{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:var(--spacing-md);}
.apr-title-row{display:flex;align-items:center;gap:14px;}
.apr-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.tech .apr-icon{background:rgba(0,168,255,.1);color:var(--primary-blue);}
.comm .apr-icon{background:rgba(40,167,69,.1);color:var(--success-green);}
.apr-icon-text h4{margin:0;font-size:1rem;}
.apr-icon-text p{margin:3px 0 0;color:var(--text-secondary);font-size:.83rem;}
.aprbadge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:600;background:rgba(255,255,255,.07);}
.apr-body{background:rgba(255,255,255,.04);border-radius:var(--radius-md);padding:var(--spacing-md);margin-bottom:var(--spacing-md);font-size:.9rem;line-height:1.65;}
.apr-body p{margin:0 0 4px;}
.readiness{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:8px;font-size:.82rem;margin-top:6px;}
.readiness.ok{background:rgba(40,167,69,.1);color:var(--success-green);}
.readiness.no{background:rgba(255,193,7,.1);color:var(--warning-orange);}
.apr-action{padding:0 0 2px;}
.btn-notify{padding:10px 20px;border:none;border-radius:var(--radius-md);font-weight:600;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;gap:8px;font-size:.9rem;}
.tech .btn-notify{background:linear-gradient(135deg,var(--primary-blue),var(--accent-cyan));color:#fff;}
.comm .btn-notify{background:linear-gradient(135deg,#1b5e20,var(--success-green));color:#fff;}
.btn-notify:disabled{opacity:.38;cursor:not-allowed;transform:none!important;box-shadow:none!important;}
.btn-notify:not(:disabled):hover{transform:translateY(-2px);box-shadow:0 5px 16px rgba(0,168,255,.35);}

/* ── Email note ── */
.email-note{background:rgba(255,193,7,.07);border:1px solid rgba(255,193,7,.32);border-radius:var(--radius-md);padding:var(--spacing-md);margin-bottom:var(--spacing-md);color:var(--warning-orange);font-size:.85rem;line-height:1.75;}
.email-note code{background:rgba(0,0,0,.28);padding:1px 5px;border-radius:4px;font-size:.82rem;}

/* ── Validation summary ── */
.val-panel{padding:var(--spacing-lg);}
.val-hdr{display:flex;align-items:center;gap:10px;margin-bottom:var(--spacing-md);padding-bottom:10px;border-bottom:1px solid var(--glass-border);}
.val-hdr h4{margin:0;color:var(--text-primary);font-size:1rem;}
.val-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--spacing-md);margin-bottom:var(--spacing-md);}
.val-item{padding:var(--spacing-md);background:rgba(255,255,255,.04);border-radius:var(--radius-md);border:1px solid var(--glass-border);}
.val-item.good{border-left:4px solid var(--success-green);}
.val-item.warn{border-left:4px solid var(--warning-orange);}
.val-item.err{border-left:4px solid var(--danger-red);}
.val-item h5{margin:0 0 4px;font-size:.82rem;color:var(--text-secondary);}
.val-item p{margin:0;font-size:1.45rem;font-weight:700;color:var(--text-primary);}

/* ── Section cards ── */
.section-card{background:var(--glass-bg);backdrop-filter:blur(20px);border:1px solid var(--glass-border);border-radius:var(--radius-lg);margin-bottom:var(--spacing-lg);transition:var(--transition);overflow:hidden;}
.section-card:hover{transform:translateY(-2px);box-shadow:0 16px 40px rgba(0,0,0,.28);}
.section-card.saved{border-left:4px solid var(--success-green);}
.section-card.completely-filled{border-left:4px solid var(--primary-blue);}
.sec-hdr{background:linear-gradient(135deg,rgba(0,168,255,.18),rgba(0,255,255,.08));padding:var(--spacing-lg);border-bottom:1px solid rgba(255,255,255,.08);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
.sec-title{font-size:1.1rem;font-weight:600;margin:0;display:flex;align-items:center;gap:10px;}
.sec-status{display:flex;align-items:center;gap:10px;}
.param-count{background:rgba(255,255,255,.1);padding:4px 11px;border-radius:10px;font-size:.76rem;font-weight:600;color:var(--text-secondary);}
.sec-warn{background:rgba(255,193,7,.08);border:1px solid rgba(255,193,7,.28);border-radius:var(--radius-sm);padding:9px var(--spacing-md);margin:var(--spacing-md) var(--spacing-lg);color:var(--warning-orange);display:flex;align-items:center;gap:8px;font-size:.88rem;}

/* ── Parameter grid ── */
.param-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:var(--spacing-md);padding:var(--spacing-lg);}
.param-item{background:rgba(255,255,255,.04);border-radius:var(--radius-md);padding:var(--spacing-md);border:2px solid rgba(255,255,255,.1);position:relative;transition:var(--transition);}
.param-item:hover{border-color:rgba(0,168,255,.35);background:rgba(255,255,255,.07);}
.param-item.required{border-left:4px solid var(--danger-red);}
.param-item.missing-required{border-color:var(--danger-red)!important;background:rgba(220,53,69,.05);}
.param-item.empty-field{border-color:var(--warning-orange)!important;background:rgba(255,193,7,.04);}
.param-label{display:flex;gap:10px;margin-bottom:10px;}
.param-code{background:linear-gradient(135deg,rgba(0,168,255,.28),rgba(0,255,255,.18));color:var(--accent-cyan);padding:4px 10px;border-radius:var(--radius-sm);font-size:.75rem;font-weight:700;min-width:50px;text-align:center;flex-shrink:0;}
.param-text{color:var(--text-primary);font-weight:600;font-size:.9rem;}
.param-unit{color:var(--text-tertiary);font-size:.76rem;margin-top:3px;}
.val-hint{font-size:11px;color:var(--text-tertiary);margin-top:4px;display:flex;align-items:center;gap:4px;}
.param-input,.param-ta{width:100%;padding:var(--spacing-sm) var(--spacing-md);background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.18);border-radius:var(--radius-md);color:var(--text-primary);font-size:.9rem;font-family:inherit;transition:var(--transition);box-sizing:border-box;}
.param-ta{min-height:78px;resize:vertical;}
.param-input:focus,.param-ta:focus{outline:none;background:rgba(255,255,255,.11);border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(0,168,255,.18);}
.fld-dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;}
.fld-dot.filled{background:var(--success-green);}
.fld-dot.empty{background:var(--warning-orange);}
.fld-dot.req-empty{background:var(--danger-red);animation:blink 1s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}

/* ── Section actions ── */
.sec-actions{padding:var(--spacing-lg);background:rgba(0,0,0,.18);border-top:1px solid rgba(255,255,255,.08);text-align:center;}
.btn-save{background:linear-gradient(135deg,var(--primary-light),var(--accent-teal));color:#fff;border:none;padding:var(--spacing-sm) var(--spacing-xl);border-radius:var(--radius-md);font-weight:600;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;gap:8px;}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 5px 24px rgba(0,168,255,.45);}
.save-hint{margin-top:8px;color:var(--text-tertiary);font-size:.82rem;}
.last-saved-t{font-size:11px;color:var(--text-tertiary);margin-top:4px;font-style:italic;}

/* ── Final submission ── */
.final-card{background:rgba(40,167,69,.08);border-radius:var(--radius-lg);padding:var(--spacing-xl);margin:var(--spacing-xl) 0;border:2px solid rgba(40,167,69,.25);}
.final-hdr{text-align:center;margin-bottom:var(--spacing-lg);}
.final-hdr h3{color:var(--text-primary);font-size:1.4rem;font-weight:700;margin-bottom:6px;}
.prog-wrap{background:rgba(255,255,255,.04);border-radius:var(--radius-md);padding:var(--spacing-md);margin-bottom:var(--spacing-lg);}
.prog-bar{height:38px;background:rgba(255,255,255,.1);border-radius:20px;overflow:hidden;margin-bottom:8px;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--success-green),#20c997);border-radius:20px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;transition:width 1s ease;}
.prog-stats{display:flex;justify-content:space-between;color:var(--text-tertiary);font-size:.82rem;}
.btn-final{background:linear-gradient(135deg,var(--success-green),#2ecc71);color:#fff;border:none;padding:var(--spacing-md) var(--spacing-xl);border-radius:var(--radius-md);font-size:.98rem;font-weight:700;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;gap:8px;width:100%;justify-content:center;}
.btn-final:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 7px 28px rgba(40,167,69,.5);}
.btn-final:disabled{background:rgba(108,117,125,.28);color:rgba(255,255,255,.38);cursor:not-allowed;}

/* ── Action row ── */
.action-row{display:flex;gap:var(--spacing-md);justify-content:center;margin:var(--spacing-xl) 0;flex-wrap:wrap;}
.btn-back,.btn-rpt,.btn-val{padding:var(--spacing-sm) var(--spacing-xl);border-radius:var(--radius-md);font-weight:600;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;gap:8px;text-decoration:none;min-width:170px;justify-content:center;border:none;}
.btn-back{background:rgba(255,255,255,.08);color:var(--text-primary);border:1px solid rgba(255,255,255,.16);}
.btn-back:hover{background:rgba(255,255,255,.14);}
.btn-rpt{background:linear-gradient(135deg,var(--primary-light),var(--accent-teal));color:#fff;}
.btn-rpt:hover{transform:translateY(-2px);box-shadow:0 5px 22px rgba(0,168,255,.4);}
.btn-val{background:linear-gradient(135deg,var(--accent-cyan),var(--accent-teal));color:var(--primary-dark);}
.btn-val:hover{transform:translateY(-2px);box-shadow:0 5px 22px rgba(0,247,255,.35);}

/* ── Manager review table ── */
.mgr-review{background:var(--glass-bg);backdrop-filter:blur(20px);border-radius:var(--radius-lg);padding:var(--spacing-xl);margin-bottom:var(--spacing-lg);}
.mgr-review h2{margin-bottom:12px;}
.dtable{width:100%;border-collapse:collapse;background:rgba(0,20,41,.75);border-radius:var(--radius-sm);overflow:hidden;margin-top:10px;}
.dtable th,.dtable td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--glass-border);font-size:.88rem;}
.dtable th{background:linear-gradient(135deg,rgba(0,102,204,.28),rgba(0,168,255,.28));color:var(--text-primary);font-weight:600;}
.dtable td{color:var(--text-secondary);}
.apr-btns{display:flex;gap:14px;justify-content:center;margin-top:var(--spacing-lg);flex-wrap:wrap;}
.btn-apr,.btn-rej{padding:12px 28px;border:none;border-radius:var(--radius-md);font-weight:600;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;gap:8px;font-size:.95rem;}
.btn-apr{background:linear-gradient(135deg,#1b5e20,var(--success-green));color:#fff;}
.btn-rej{background:linear-gradient(135deg,#7f0000,var(--danger-red));color:#fff;}
.btn-apr:hover,.btn-rej:hover{transform:translateY(-2px);}
.rej-ta{width:100%;padding:12px;border:1px solid var(--glass-border);border-radius:var(--radius-sm);margin-top:14px;background:rgba(255,255,255,.07);color:var(--text-primary);display:none;resize:vertical;box-sizing:border-box;font-size:.9rem;}
.exp-warn{background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);border-radius:var(--radius-sm);padding:10px 16px;margin-top:14px;color:var(--warning-orange);font-size:.87rem;}

/* ── Toast ── */
.toast{position:fixed;top:22px;right:22px;background:#fff;padding:14px 20px;border-radius:var(--radius-md);box-shadow:0 4px 16px rgba(0,0,0,.14);display:flex;align-items:center;gap:10px;z-index:9999;animation:tin .3s;max-width:400px;}
@keyframes tin{from{opacity:0;transform:translateX(100%)}to{opacity:1;transform:translateX(0)}}
.toast.ts{border-left:4px solid var(--success-green);}
.toast.te{border-left:4px solid var(--danger-red);}
.toast.tw{border-left:4px solid var(--warning-orange);}

/* ── Responsive ── */
@media(max-width:768px){
  .param-grid{grid-template-columns:1fr;padding:var(--spacing-md);}
  .user-row,.month-top,.sec-hdr,.action-row{flex-direction:column;align-items:flex-start;}
  .action-row{align-items:stretch;}
  .btn-back,.btn-rpt,.btn-val{width:100%;}
}
</style>
</head>
<body class="data-entry-page">
<div class="water-bg"><div class="water-wave"></div><div class="water-wave"></div><div class="water-wave"></div></div>
<div class="main-container">
<?php include 'nav_bar.php'; ?>
<div class="main-content"><div class="page-content"><div class="content-wrapper">
<div class="de-container">

<?php /* ── Alerts ── */ ?>
<?php if ($success): ?>
<div class="alert alert-success">
  <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
  <div class="alert-content"><div class="alert-heading">Success</div><p><?php echo $success; ?></p></div>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger">
  <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
  <div class="alert-content"><div class="alert-heading">Notice</div><p><?php echo $error; ?></p></div>
</div>
<?php endif; ?>
<?php if ($email_note): ?>
<div class="email-note">
  <i class="fas fa-envelope-open-text"></i> <strong>Email Configuration Note:</strong><br><?php echo $email_note; ?>
</div>
<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
         MANAGER TOKEN REVIEW VIEW
         ════════════════════════════════════════════════════ */ ?>
<?php if ($appr_token && ($is_tech_mgr || $is_comm_mgr) && !$is_POST): ?>
<?php
    $mc    = $appr_role === 'technical_manager' ? TECH_CATS : COMM_CATS;
    $ph    = implode(',', array_fill(0, count($mc), '?'));
    $rows  = db_rows("SELECT pc.name AS cn, p.code, p.label, p.unit, md.value FROM parameters p JOIN parameter_categories pc ON p.category_id=pc.id LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=? WHERE p.category_id IN($ph) ORDER BY pc.display_order, p.code", "i" . str_repeat('i', count($mc)), ...array_merge([$month_id], $mc));
    $cd2   = [];
    foreach ($rows as $r) $cd2[$r['cn']][] = $r;
    $rt    = $appr_role === 'technical_manager' ? 'Technical Manager' : 'Commercial Manager';
?>
<div class="mgr-review">
  <h2><i class="fas fa-clipboard-list"></i> Monthly Report — Review &amp; Approval</h2>
  <div style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--primary-blue),var(--accent-cyan));color:#fff;padding:6px 16px;border-radius:20px;margin:10px 0;font-size:.88rem;">
    <i class="fas fa-calendar-alt"></i> <?php echo he($month['name']); ?> &nbsp;|&nbsp; <?php echo $rt; ?>
  </div>
  <div class="exp-warn"><i class="fas fa-clock"></i> <strong>Note:</strong> This review link will expire 7 days from the date it was issued. Please act promptly.</div>
  <p style="margin-top:18px;color:var(--text-secondary);font-size:.9rem;">Please review the data entries in your assigned sections below. If all figures are correct, click <strong>Approve</strong>. If corrections are needed, click <strong>Request Changes</strong> and provide details.</p>
  <?php foreach ($cd2 as $cname => $rs): ?>
  <div style="margin-top:24px;">
    <h3 style="font-size:1rem;color:var(--text-primary);margin-bottom:8px;"><i class="fas fa-folder"></i> <?php echo he($cname); ?></h3>
    <table class="dtable">
      <thead><tr><th>Code</th><th>Parameter</th><th>Unit</th><th>Value</th></tr></thead>
      <tbody>
      <?php foreach ($rs as $r): ?>
      <tr>
        <td><code><?php echo he($r['code']); ?></code></td>
        <td><?php echo he($r['label']); ?></td>
        <td><?php echo he($r['unit'] ?? '—'); ?></td>
        <td><strong><?php echo he($r['value'] ?? '—'); ?></strong></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <div class="apr-btns">
    <button class="btn-apr" onclick="doApprove()"><i class="fas fa-check-circle"></i> Approve Report Data</button>
    <button class="btn-rej" onclick="showReject()"><i class="fas fa-times-circle"></i> Request Corrections</button>
  </div>
  <textarea id="rejta" class="rej-ta" rows="4" placeholder="Please describe the corrections required in detail…"></textarea>
  <div id="rej_submit" style="display:none;text-align:center;margin-top:12px;">
    <button class="btn-rej" onclick="doReject()"><i class="fas fa-paper-plane"></i> Submit Correction Request</button>
  </div>
  <form id="fA" method="POST"><input type="hidden" name="response" value="approve"></form>
  <form id="fR" method="POST"><input type="hidden" name="response" value="reject"><input type="hidden" name="rejection_reason" id="rh"></form>
</div>
<script>
function doApprove(){if(confirm('Confirm Approval\n\nBy clicking OK you are confirming that all data entries in your assigned sections are accurate and correct.'))document.getElementById('fA').submit();}
function showReject(){document.getElementById('rejta').style.display='block';document.getElementById('rej_submit').style.display='block';document.getElementById('rejta').focus();}
function doReject(){var r=document.getElementById('rejta').value.trim();if(!r){alert('Please provide details of the corrections required before submitting.');return;}document.getElementById('rh').value=r;document.getElementById('fR').submit();}
</script>
<?php exit; ?>
<?php endif; ?>

<?php /* ── User info card ── */ ?>
<div class="glass-card user-card">
  <div class="user-row">
    <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
    <div class="user-meta">
      <h5><?php echo he($full_name); ?></h5>
      <span class="badge badge-info"><i class="fas fa-user-tag"></i> <?php echo he($user_info['role_name'] ?? 'User'); ?></span>
      <span class="text-muted" style="margin-left:10px;font-size:.87rem;">@<?php echo he($user_info['username']); ?></span>
    </div>
    <div class="user-stats">
      <div style="font-size:.87rem;color:var(--text-secondary);margin-bottom:6px;"><strong><?php echo $total_cats; ?></strong> sections assigned</div>
      <div class="progress-label">Progress <span><?php echo $saved_cats; ?> / <?php echo $total_cats; ?></span></div>
      <div class="progress-indicator"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div>
    </div>
  </div>
</div>

<?php /* ── Month header ── */ ?>
<div class="glass-card month-header">
  <div class="month-top">
    <div>
      <h2><i class="fas fa-edit"></i> Monthly Data Entry</h2>
      <div class="month-period">
        <i class="fas fa-calendar-alt"></i> <?php echo he($month['name']); ?>
        <?php if ($month['start_date']): ?>
        &nbsp;·&nbsp;<i class="fas fa-clock"></i>
        <?php echo date('d M Y', strtotime($month['start_date'])); ?> –
        <?php echo date('d M Y', strtotime($month['end_date'])); ?>
        <?php endif; ?>
      </div>
    </div>
    <span class="status-badge status-<?php echo $is_submitted ? 'submitted' : ($saved_cats === $total_cats ? 'saved' : 'pending'); ?>">
      <?php echo strtoupper($month['status']); ?>
    </span>
  </div>
  <?php if ($is_submitted): ?>
  <div class="read-only-banner"><i class="fas fa-lock fa-lg"></i><div><strong>Read-Only Mode</strong><br><span style="font-size:.88rem;color:var(--text-secondary);">This report has been submitted and is locked for editing.</span></div></div>
  <?php endif; ?>
  <?php if (!$is_admin && !$is_tech_mgr && !$is_comm_mgr): ?>
  <div class="perm-note"><i class="fas fa-info-circle"></i> You can only view and edit parameters that are assigned to your role.</div>
  <?php endif; ?>
  <div class="quality-note"><i class="fas fa-clipboard-check"></i> <strong>Data Guidelines:</strong> All required fields must be completed. For optional fields with no applicable data, enter a dash (<strong>-</strong>).</div>
</div>

<?php if (empty($cats_params)): ?>
<div class="glass-card" style="padding:var(--spacing-xl);text-align:center;">
  <div style="font-size:3.5rem;opacity:.4;margin-bottom:14px;"><i class="fas fa-folder-open"></i></div>
  <h4>No Parameters Assigned</h4>
  <p style="color:var(--text-secondary);">Your role does not have any data entry parameters assigned. Please contact your administrator.</p>
</div>
<?php else: ?>

<?php /* ════════════════════════════════════════════════════
         MANAGER SELF-APPROVAL PANEL
         ════════════════════════════════════════════════════ */ ?>
<?php if (($is_tech_mgr || $is_comm_mgr) && !$is_submitted):
    $my_filled = $is_tech_mgr ? $tech_filled : $comm_filled;
    $my_appr   = $is_tech_mgr ? $tech_appr   : $comm_appr;
    $mst       = $my_appr['status'] ?? 'pending';
    $pcls      = $is_tech_mgr ? 'tech' : 'comm';
    $pico      = $is_tech_mgr ? 'hard-hat' : 'briefcase';
    $psects    = $is_tech_mgr ? 'Production · Infrastructure · Water Quality · NRW · Operations' : 'Revenue · Customer Care · Accounts · GIS · HR · Related Sections';
?>
<div class="glass-card apr-panel <?php echo $pcls; ?>">
  <div class="apr-hdr">
    <div class="apr-title-row">
      <div class="apr-icon"><i class="fas fa-<?php echo $pico; ?>"></i></div>
      <div class="apr-icon-text"><h4>Your Approval Status</h4><p><?php echo $psects; ?></p></div>
    </div>
    <?php echo aprBadge($mst); ?>
  </div>
  <div class="apr-body">
    <?php if ($mst === 'approved'): ?>
      <p><i class="fas fa-check-double" style="color:var(--success-green);"></i> <strong>You have approved this report.</strong> The administrator has been notified.</p>
      <p style="color:var(--text-tertiary);font-size:.82rem;">Approved on <?php echo date('d M Y \a\t H:i', strtotime($my_appr['approved_at'])); ?></p>
    <?php elseif (!$my_filled): ?>
      <p><i class="fas fa-exclamation-triangle" style="color:var(--warning-orange);"></i> <strong>Your sections are not yet fully completed.</strong> All fields must be filled before you can submit your approval.</p>
      <span class="readiness no"><i class="fas fa-lock"></i> Approval locked — pending data entry</span>
    <?php else: ?>
      <p><i class="fas fa-check-circle" style="color:var(--success-green);"></i> <strong>All your assigned sections are complete.</strong> Please review the data above and submit your approval once you are satisfied with the entries.</p>
      <span class="readiness ok"><i class="fas fa-unlock-alt"></i> Ready for approval</span>
    <?php endif; ?>
  </div>
  <?php if ($my_filled && $mst !== 'approved'): ?>
  <div class="apr-action">
    <form method="POST">
      <input type="hidden" name="action" value="manager_direct_approve">
      <button type="submit" class="btn-notify"
        onclick="return confirm('Submit Approval\n\nBy clicking OK you confirm that all data entries in your assigned sections are accurate and complete. This action will notify the administrator immediately.')">
        <i class="fas fa-check-circle"></i> Submit My Approval
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
         ADMIN: VALIDATION SUMMARY + NOTIFY PANELS
         ════════════════════════════════════════════════════ */ ?>
<?php if ($is_admin && !$is_submitted): ?>

<div class="glass-card val-panel">
  <div class="val-hdr"><i class="fas fa-clipboard-check fa-lg" style="color:var(--accent-cyan);"></i><h4>Data Quality Overview</h4></div>
  <div class="val-grid">
    <div class="val-item <?php echo $saved_cats   === $total_cats ? 'good' : 'warn'; ?>"><h5>Saved Sections</h5><p><?php echo $saved_cats; ?> / <?php echo $total_cats; ?></p></div>
    <div class="val-item <?php echo $complete_cats === $total_cats ? 'good' : 'warn'; ?>"><h5>Fully Completed</h5><p><?php echo $complete_cats; ?> / <?php echo $total_cats; ?></p></div>
    <div class="val-item <?php echo $total_cats - $complete_cats === 0 ? 'good' : 'err'; ?>"><h5>Need Attention</h5><p><?php echo $total_cats - $complete_cats; ?></p></div>
  </div>
  <?php if ($total_cats - $complete_cats > 0): ?>
  <div style="background:rgba(0,168,255,.08);border-left:4px solid var(--primary-blue);padding:var(--spacing-md);border-radius:var(--radius-sm);font-size:.88rem;color:var(--text-secondary);">
    <strong style="color:var(--primary-light);"><i class="fas fa-info-circle"></i> Action Required —</strong>
    Please ensure all required fields are filled, optional fields contain data or a dash (-), and each section displays a "Complete" status before sending for manager review.
  </div>
  <?php endif; ?>
</div>

<?php
// Technical Manager panel
$ts = $tech_appr['status'] ?? 'pending';
foreach ([
    ['tech', 'hard-hat',   'Technical Manager Review', 'Production · Infrastructure · Water Quality · NRW · Operations', $ts, $tech_filled, $tech_appr, 'technical_manager'],
    ['comm', 'briefcase',  'Commercial Manager Review', 'Revenue · Customer Care · Accounts · GIS · HR · Related Sections', $comm_appr['status'] ?? 'pending', $comm_filled, $comm_appr, 'commercial_manager'],
] as [$cls, $ico, $ptitle, $psects, $pst, $pfilled, $pappr, $prole]):
?>
<div class="glass-card apr-panel <?php echo $cls; ?>">
  <div class="apr-hdr">
    <div class="apr-title-row">
      <div class="apr-icon"><i class="fas fa-<?php echo $ico; ?>"></i></div>
      <div class="apr-icon-text"><h4><?php echo $ptitle; ?></h4><p><?php echo $psects; ?></p></div>
    </div>
    <?php echo aprBadge($pst); ?>
  </div>
  <div class="apr-body">
    <?php if (!$pfilled && $pst === 'pending'): ?>
      <p><i class="fas fa-exclamation-triangle" style="color:var(--warning-orange);"></i> The <?php echo strtolower($ptitle); ?>'s sections are <strong>not yet fully completed</strong>. All data must be entered before sending a review request.</p>
      <span class="readiness no"><i class="fas fa-times-circle"></i> Awaiting data entry</span>
    <?php elseif ($pst === 'pending'): ?>
      <p><i class="fas fa-check-circle" style="color:var(--success-green);"></i> All sections for this manager are complete. You may now send the review request.</p>
      <span class="readiness ok"><i class="fas fa-check-circle"></i> Ready to send</span>
    <?php elseif ($pst === 'notified'): ?>
      <p><i class="fas fa-paper-plane" style="color:var(--warning-orange);"></i> A review request has been sent. Awaiting the manager's response.</p>
      <p style="color:var(--text-tertiary);font-size:.82rem;">Sent on <?php echo date('d M Y \a\t H:i', strtotime($pappr['notified_at'])); ?></p>
    <?php elseif ($pst === 'approved'): ?>
      <p><i class="fas fa-check-double" style="color:var(--success-green);"></i> The data has been <strong>approved</strong> by the <?php echo strtolower($ptitle); ?>.</p>
      <p style="color:var(--text-tertiary);font-size:.82rem;">Approved on <?php echo date('d M Y \a\t H:i', strtotime($pappr['approved_at'])); ?></p>
    <?php elseif ($pst === 'rejected'): ?>
      <p><i class="fas fa-times-circle" style="color:var(--danger-red);"></i> The manager has <strong>requested corrections</strong>. Please update the data and resend the review request.</p>
      <?php if (!empty($pappr['rejection_reason'])): ?>
      <div style="background:rgba(220,53,69,.08);border-left:3px solid var(--danger-red);padding:10px 14px;border-radius:0 6px 6px 0;margin-top:8px;font-size:.85rem;">
        <strong>Manager's Feedback:</strong> <?php echo he($pappr['rejection_reason']); ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="apr-action">
    <form method="POST">
      <input type="hidden" name="action" value="notify_manager">
      <input type="hidden" name="manager_role" value="<?php echo $prole; ?>">
      <button type="submit" class="btn-notify" <?php echo !$pfilled ? 'disabled title="All sections must be completed before sending."' : ''; ?>>
        <i class="fas fa-paper-plane"></i>
        <?php echo $pst === 'pending' ? 'Send Review Request' : ($pst === 'rejected' ? 'Resend Review Request' : 'Request Re-Review'); ?>
      </button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php endif; /* end admin panels */ ?>

<?php /* ════════════════════════════════════════════════════
         DATA SECTIONS
         ════════════════════════════════════════════════════ */ ?>
<?php foreach ($cats_params as $cd):
    $cat    = $cd['category'];
    $params = $cd['parameters'];
    $sv     = isSaved($month_id, (int)$cat['id'], $role_id, $is_admin);
    $val    = validateSection($month_id, (int)$cat['id']);
    $fld    = $val === true;
    $in_t   = in_array($cat['id'], TECH_CATS);
    $in_c   = in_array($cat['id'], COMM_CATS);
?>
<div class="section-card <?php echo $sv ? ($fld ? 'completely-filled' : 'saved') : 'unsaved'; ?>"
     data-section-id="<?php echo (int)$cat['id']; ?>">
  <div class="sec-hdr">
    <h3 class="sec-title">
      <i class="fas fa-folder"></i> <?php echo he($cat['name']); ?>
      <?php if ($in_t): ?>
      <span style="font-size:.7rem;color:#1976d2;background:rgba(25,118,210,.12);padding:3px 9px;border-radius:10px;">
        <i class="fas fa-hard-hat"></i> Technical
      </span>
      <?php elseif ($in_c): ?>
      <span style="font-size:.7rem;color:#2e7d32;background:rgba(46,125,50,.12);padding:3px 9px;border-radius:10px;">
        <i class="fas fa-briefcase"></i> Commercial
      </span>
      <?php endif; ?>
    </h3>
    <div class="sec-status">
      <span class="param-count"><i class="fas fa-list"></i> <?php echo count($params); ?> fields</span>
      <span class="status-badge status-<?php echo $sv ? ($fld ? 'saved' : 'pending') : 'pending'; ?>" id="sb-<?php echo (int)$cat['id']; ?>">
        <?php echo $sv ? ($fld ? '<i class="fas fa-check-circle"></i> Complete' : '<i class="fas fa-exclamation-circle"></i> Needs Attention') : '<i class="fas fa-clock"></i> Pending'; ?>
      </span>
    </div>
  </div>

  <?php if ($sv && !$fld): ?>
  <div class="sec-warn"><i class="fas fa-exclamation-triangle"></i> This section contains empty fields. Please fill all fields or enter a dash (-) where data is not applicable.</div>
  <?php endif; ?>

  <div class="param-grid">
  <?php foreach ($params as $param):
      $is_ml = in_array($param['id'], ML_PARAMS);
      $cv    = isset($existing_data[$param['code']]) ? he($existing_data[$param['code']]) : '';
      $ie    = trim($cv) === '';
      $fc    = $param['required'] && $ie ? 'missing-required' : ($ie ? 'empty-field' : '');
  ?>
  <div class="param-item <?php echo $param['required'] ? 'required' : ''; ?> <?php echo $fc; ?>"
       data-param-id="<?php echo (int)$param['id']; ?>">
    <div class="param-label">
      <span class="param-code"><?php echo he($param['code']); ?></span>
      <div>
        <div class="param-text"><?php echo he($param['label']); ?><?php if ($param['required']): ?> <span class="text-danger">*</span><?php endif; ?></div>
        <?php if (!empty($param['unit'])): ?><div class="param-unit"><i class="fas fa-ruler"></i> <?php echo he($param['unit']); ?></div><?php endif; ?>
        <div class="val-hint">
          <?php if ($param['required']): ?>
          <i class="fas fa-asterisk text-danger" style="font-size:7px;"></i><span>Required</span>
          <?php else: ?>
          <i class="fas fa-info-circle" style="font-size:7px;color:var(--accent-cyan);"></i><span>Optional — use <strong>-</strong> if not applicable</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php if ($is_ml): ?>
    <textarea name="data[<?php echo he($param['code']); ?>]" class="param-input param-ta" rows="3"
      <?php echo $is_submitted ? 'readonly' : ''; ?>
      data-code="<?php echo he($param['code']); ?>"
      data-orig="<?php echo $cv; ?>"
      placeholder="<?php echo $param['required'] ? 'Required field' : 'Enter value or \"-\" if not applicable'; ?>"><?php echo $cv; ?></textarea>
    <?php else: ?>
    <input type="text" name="data[<?php echo he($param['code']); ?>]" value="<?php echo $cv; ?>"
      class="param-input" <?php echo $is_submitted ? 'readonly' : ''; ?>
      data-code="<?php echo he($param['code']); ?>"
      data-orig="<?php echo $cv; ?>"
      placeholder="<?php echo $param['required'] ? 'Required field' : 'Enter value or \"-\" if not applicable'; ?>">
    <?php endif; ?>
    <div class="fld-dot <?php echo $ie ? ($param['required'] ? 'req-empty' : 'empty') : 'filled'; ?>"></div>
  </div>
  <?php endforeach; ?>
  </div>

  <?php if (!$is_submitted): ?>
  <div class="sec-actions">
    <form method="POST" class="save-form" id="form-<?php echo (int)$cat['id']; ?>">
      <input type="hidden" name="action" value="save_section">
      <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
      <?php foreach ($params as $p): ?>
      <input type="hidden" name="data[<?php echo he($p['code']); ?>]" id="hid_<?php echo he($p['code']); ?>">
      <?php endforeach; ?>
      <button type="submit" class="btn-save" onclick="return prepareSubmit(this, <?php echo (int)$cat['id']; ?>)">
        <i class="fas fa-save"></i> Save Section
      </button>
      <div class="save-hint"><small><i class="fas fa-info-circle"></i> Data is saved to the database. You may return to edit at any time.</small></div>
      <div class="last-saved-t" id="ls-<?php echo (int)$cat['id']; ?>"><?php if ($sv): echo 'Last saved: ' . date('d M Y H:i'); endif; ?></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php /* ── Final submission ── */ ?>
<?php if ($is_admin && !$is_submitted): ?>
<div class="final-card">
  <div class="final-hdr">
    <h3><i class="fas fa-flag-checkered"></i> Final Report Submission</h3>
    <p style="color:var(--text-secondary);font-size:.9rem;">All sections must be fully completed before the report can be submitted.</p>
  </div>
  <div class="prog-wrap">
    <div class="prog-bar">
      <div class="prog-fill" style="width:<?php echo $cpct; ?>%">
        <?php echo $complete_cats; ?> of <?php echo $total_cats; ?> sections complete
      </div>
    </div>
    <div class="prog-stats"><span>Completion</span><span><?php echo $cpct; ?>%</span></div>
  </div>
  <?php if ($complete_cats === $total_cats): ?>
  <form method="POST">
    <input type="hidden" name="action" value="submit_final">
    <button type="submit" class="btn-final"
      onclick="return confirm('Submit Final Report\n\nThis will lock the report and prevent any further editing.\n\nAre you sure you wish to proceed?')">
      <i class="fas fa-paper-plane"></i> Submit Final Report
    </button>
    <p style="text-align:center;margin-top:10px;"><small style="color:var(--text-tertiary);"><i class="fas fa-shield-alt"></i> All sections validated. The report will be permanently locked after submission.</small></p>
  </form>
  <?php else: ?>
  <button class="btn-final" disabled>
    <i class="fas fa-lock"></i> <?php echo $total_cats - $complete_cats; ?> section(s) still require attention
  </button>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* end empty check */ ?>

<div class="action-row">
  <a href="months.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Months</a>
  <a href="report.php?month_id=<?php echo $month_id; ?>" class="btn-rpt"><i class="fas fa-chart-bar"></i> View Report</a>
  <?php if ($is_admin && !$is_submitted): ?>
  <button class="btn-val" onclick="validateAll()"><i class="fas fa-clipboard-check"></i> Validate All Fields</button>
  <?php endif; ?>
</div>

</div><!-- /de-container -->
</div></div></div>
</div><!-- /main-container -->

<script>
/** Copy visible input values into hidden fields before form submit */
function prepareSubmit(btn, catId) {
    const sec = btn.closest('.section-card');
    sec.querySelectorAll('.param-input').forEach(inp => {
        const code = inp.getAttribute('name').replace('data[', '').replace(']', '');
        const hid  = document.getElementById('hid_' + code);
        if (hid) hid.value = inp.value;
    });
    return true;
}

/** Toast notification */
function toast(msg, type = 'ts') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<div style="flex:1;font-weight:500;color:#222;font-size:.9rem;">${msg}</div>
                   <button onclick="this.parentElement.remove()" style="background:none;border:none;font-size:1.1rem;cursor:pointer;color:#999;padding:0 0 0 8px;">&times;</button>`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 6000);
}

/** Highlight empty fields across all sections */
function validateAll() {
    let n = 0;
    document.querySelectorAll('.section-card').forEach(sec => {
        sec.querySelectorAll('.param-input').forEach(inp => {
            const empty = !inp.value.trim();
            const req   = inp.hasAttribute('required');
            inp.style.borderColor = empty ? (req ? 'var(--danger-red)' : 'var(--warning-orange)') : '';
            if (req && empty) n++;
        });
    });
    toast(n ? `${n} required field(s) are empty. Please complete them before submitting.` : 'All fields look good — ready for submission.', n ? 'tw' : 'ts');
}

/** Auto-resize textareas */
document.querySelectorAll('.param-ta').forEach(ta => {
    const resize = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
    ta.addEventListener('input', resize);
    if (ta.value) setTimeout(resize, 80);
});

/** Unsaved changes warning */
let dirty = false;
document.querySelectorAll('.param-input').forEach(i => i.addEventListener('input', () => { dirty = true; }));
document.querySelectorAll('.save-form').forEach(f => f.addEventListener('submit', () => { dirty = false; }));
window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = 'You have unsaved changes. Are you sure you want to leave?'; }
});
</script>
</body>
</html>
<?php if (isset($conn)) $conn->close(); ?>