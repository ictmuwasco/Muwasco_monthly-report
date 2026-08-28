<?php
/**
 * add_data.php — Thin front controller (MVC entry point).
 * Monthly Data Entry, Validation & Manager Approval
 * Roles: admin (edit + notify), technical_manager / commercial_manager (approve)
 *
 * Logic lives in:
 *   app/Models/MonthlyDataModel.php       — persistence
 *   app/Services/MonthlyDataService.php   — business rules
 *   app/Services/EmailService.php         — SMTP + notification templates
 *   app/Controllers/MonthlyDataController.php — HTTP dispatch
 *   frontend/src/pages/data-entry.php    — Tailwind presentation
 */

require_once __DIR__ . '/backend/bootstrap/app.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backend/app/Helpers/AuthFunctions.php';
require_once __DIR__ . '/backend/app/Helpers/RoleFunctions.php';
require_once __DIR__ . '/email_config.php';

/* ── Shared view helpers (used by views across the app) ── */
function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function base_url(): string {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

function db_row(string $sql, string $types, ...$vals): ?array {
    global $conn;
    $s = $conn->prepare($sql); $s->bind_param($types, ...$vals); $s->execute();
    $r = $s->get_result(); $row = $r->fetch_assoc(); $r->free(); $s->close();
    return $row ?: null;
}

function db_rows(string $sql, string $types = '', ...$vals): array {
    global $conn;
    if ($types === '') {
        $r = $conn->query($sql); $rows = []; while ($row = $r->fetch_assoc()) $rows[] = $row; $r->free(); return $rows;
    }
    $s = $conn->prepare($sql); $s->bind_param($types, ...$vals); $s->execute();
    $r = $s->get_result(); $rows = []; while ($row = $r->fetch_assoc()) $rows[] = $row; $r->free(); $s->close();
    return $rows;
}

/** Tailwind status badge for approval workflow (no inline CSS). */
function aprBadgeTailwind(string $status): string {
    $map = [
        'pending'  => ['badge-pending', 'Not Notified'],
        'notified' => ['badge-pending', 'Awaiting Review'],
        'approved' => ['badge-approved', 'Approved'],
        'rejected' => ['badge-rejected', 'Corrections Requested'],
    ];
    [$cls, $lb] = $map[$status] ?? ['badge-pending', ucfirst($status)];
    return "<span class='$cls'>$lb</span>";
}

/* ── View-facing delegates to the model (legacy helper names) ── */
$__mdm = null;
function isSaved(int $month_id, int $cat_id, int $role_id, bool $is_admin): bool {
    global $__mdm; return $__mdm->isSaved($month_id, $cat_id, $role_id, $is_admin);
}
function catsFilled(int $month_id, array $cats): bool {
    global $__mdm; return $__mdm->catsFilled($month_id, $cats);
}
function validateSection(int $month_id, int $cat_id) {
    global $__mdm; return $__mdm->validateSection($month_id, $cat_id);
}


/* ── Category assignment constants (global for views; service has class consts) ── */
const TECH_CATS = [12, 13, 14, 15, 16];
const COMM_CATS = [17, 18, 19, 20, 21, 22, 23, 24];
const ML_PARAMS = [221, 222, 172, 174, 305]; // multiline textarea IDs

/* ── Auth gate ── */
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php'); exit;
}
if (!isset($_GET['month_id'])) { header('Location: months.php'); exit; }

$month_id = intval($_GET['month_id']);
$month    = db_row("SELECT * FROM months WHERE id=?", "i", $month_id);
if (!$month) die("Month not found.");

$user_id   = (int)$_SESSION['user_id'];
$user_info = db_row("SELECT u.*, r.name AS role_name, r.description AS role_description FROM users u LEFT JOIN roles r ON u.role_id=r.id WHERE u.id=?", "i", $user_id);
if (!$user_info) { session_destroy(); header('Location: login.php'); exit; }

/* ── CSRF: verify every POST + auto-inject token into POST forms ── */
CsrfMiddleware::verifyOrDie();
ob_start(function ($html) {
    return preg_replace_callback('/<form\b[^>]*>/i', function ($m) {
        return (stripos($m[0], 'method=') !== false && stripos($m[0], 'csrf_token') === false)
            ? $m[0] . csrf_field()
            : $m[0];
    }, $html);
});

/* ── MVC wiring ── */
$model    = new MonthlyDataModel($conn);
$__mdm    = $model; // for view-facing delegates
$mailer   = new EmailService($model);
$service  = new MonthlyDataService($conn, $model, $mailer);
$ctrl     = new MonthlyDataController($service, $model);

$vars = $ctrl->handle([
    'month'        => $month,
    'month_id'     => $month_id,
    'user_info'    => $user_info,
    'user_id'      => $user_id,
    'role_id'      => (int)$user_info['role_id'],
    'is_admin'     => $user_info['role_name'] === 'admin',
    'is_tech_mgr'  => $user_info['role_name'] === 'technical_manager',
    'is_comm_mgr'  => $user_info['role_name'] === 'commercial_manager',
    'is_POST'      => $_SERVER['REQUEST_METHOD'] === 'POST',
    'appr_token'   => trim($_GET['approval_token'] ?? ''),
    'appr_role'    => trim($_GET['manager_role'] ?? ''),
]);
extract($vars);

/* ═══ RENDER: Tailwind view inside shared app layout ═══ */
ob_start();
require __DIR__ . '/frontend/src/pages/data-entry.php';
$content = ob_get_clean();

if (isset($conn)) $conn->close();

$pageTitle   = 'Data Entry · ' . he($month['name']) . ' — MUWASCO';
$currentPage = 'add_data.php';
require __DIR__ . '/frontend/src/layouts/app-layout.php';
exit;
