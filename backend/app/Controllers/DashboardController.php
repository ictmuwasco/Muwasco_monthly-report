<?php
/**
 * index.php — Dashboard (operational overview, Tailwind UI).
 * Uses only aggregated SQL — no heavy data loading in PHP.
 */

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../Helpers/AuthFunctions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Current user info for the layout
$stmt = $conn->prepare('SELECT u.*, r.name AS role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$user_info['full_name'] = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? '')) ?: ($user_info['username'] ?? 'User');

/* ── Dashboard metrics (aggregated in MySQL) ─────────────────── */
$latest   = $conn->query('SELECT id, month_year FROM months ORDER BY id DESC LIMIT 1')->fetch_assoc();
$latestId = (int) ($latest['id'] ?? 0);

$stats = $conn->query("
    SELECT
      (SELECT COUNT(*) FROM parameters WHERE data_type <> 'text') AS expected_params,
      (SELECT COUNT(*) FROM monthly_data WHERE month_id = {$latestId}) AS entered_latest,
      (SELECT COUNT(*) FROM month_approvals WHERE status IN ('pending','notified')) AS pending_approvals,
      (SELECT COUNT(*) FROM month_approvals WHERE status = 'approved') AS approved_count,
      (SELECT COUNT(*) FROM monthly_data) AS total_records
")->fetch_assoc();

$expected   = max(1, (int) $stats['expected_params']);
$entered    = (int) $stats['entered_latest'];
$completion = min(100, round($entered / $expected * 100));

// Recent reporting periods with approval state (single joined query)
$recentMonths = $conn->query("
    SELECT m.id, m.month_year,
           SUM(ma.status = 'approved') AS approvals,
           COUNT(md.id) AS records,
           MAX(md.created_at) AS last_updated
    FROM months m
    LEFT JOIN month_approvals ma ON ma.month_id = m.id
    LEFT JOIN monthly_data  md ON md.month_id = m.id
    GROUP BY m.id ORDER BY m.id DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

ob_start();
require __DIR__ . '/../../../frontend/src/pages/dashboard.php';
$content = ob_get_clean();

$pageTitle   = 'Dashboard · MUWASCO Monthly Report';
$currentPage = 'index.php';
require __DIR__ . '/../../../frontend/src/layouts/app-layout.php';
