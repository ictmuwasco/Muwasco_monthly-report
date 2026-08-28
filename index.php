<?php
/**
 * index.php — Dashboard (operational overview, Tailwind UI).
 * Uses only aggregated SQL — no heavy data loading in PHP.
 */

require_once __DIR__ . '/bootstrap/init.php';
require_once __DIR__ . '/auth_functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

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
?>
<div class="space-y-6">

    <!-- Header + quick actions -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Dashboard</h1>
            <p class="mt-1 text-sm text-gray-500">
                Reporting period: <span class="font-semibold text-gray-700"><?= htmlspecialchars($latest['month_year'] ?? '—') ?></span> ·
                Welcome back, <?= htmlspecialchars($user_info['full_name']) ?>.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="add_data.php" class="btn-primary">✏️ Enter Data</a>
            <a href="reports.php" class="btn-secondary">📊 Reports</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Data Completion · <?= htmlspecialchars($latest['month_year'] ?? '—') ?></p>
            <p class="mt-2 text-3xl font-bold text-primary"><?= $completion ?>%</p>
            <div class="mt-3 h-2 w-full rounded-full bg-gray-100">
                <div class="h-2 rounded-full bg-primary transition-all" style="width: <?= $completion ?>%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-400"><?= $entered ?> of <?= $expected ?> numeric parameters recorded</p>
        </div>

        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pending Approvals</p>
            <p class="mt-2 text-3xl font-bold <?= ((int)$stats['pending_approvals']) > 0 ? 'text-amber-500' : 'text-emerald-600' ?>">
                <?= (int) $stats['pending_approvals'] ?>
            </p>
            <p class="mt-4 text-xs text-gray-400">Reports awaiting manager review</p>
        </div>

        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Approved Reports</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600"><?= (int) $stats['approved_count'] ?></p>
            <p class="mt-4 text-xs text-gray-400">All-time manager approvals</p>
        </div>

        <div class="card">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Historical Records</p>
            <p class="mt-2 text-3xl font-bold text-gray-900"><?= number_format((int) $stats['total_records']) ?></p>
            <p class="mt-4 text-xs text-gray-400">Data points preserved across all periods</p>
        </div>
    </div>

    <!-- Recent periods table -->
    <div class="card overflow-hidden p-0">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <h2 class="card-title mb-0">Recent Reporting Periods</h2>
            <a href="months.php" class="text-sm font-medium text-primary hover:text-primary-dark hover:underline">View all →</a>
        </div>

        <?php if (!$recentMonths): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400">No reporting periods exist yet.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-base w-full">
                <thead>
                    <tr>
                        <th class="table-th">Period</th>
                        <th class="table-th">Data Points</th>
                        <th class="table-th">Approval Status</th>
                        <th class="table-th">Last Updated</th>
                        <th class="table-th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <?php foreach ($recentMonths as $m):
                        $approvals = (int) $m['approvals'];
                        $badgeClass = $approvals >= 2 ? 'badge-approved' : 'badge-pending';
                        $label      = $approvals >= 2 ? 'Fully Approved' : ($approvals == 1 ? 'Partially Approved' : 'No Approval Yet'); ?>
                        <tr class="hover:bg-gray-50/60">
                            <td class="table-td font-medium text-gray-900"><?= htmlspecialchars($m['month_year']) ?></td>
                            <td class="table-td"><?= (int) $m['records'] ?></td>
                            <td class="table-td"><span class="<?= $badgeClass ?>"><?= $label ?></span></td>
                            <td class="table-td text-gray-500"><?= htmlspecialchars($m['last_updated'] ? date('d M Y H:i', strtotime($m['last_updated'])) : '—') ?></td>
                            <td class="table-td text-right">
                                <a href="reports.php?month_id=<?= (int) $m['id'] ?>" class="font-medium text-primary hover:underline">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content     = ob_get_clean();
$pageTitle   = 'Dashboard · MUWASCO Monthly Report';
$currentPage = 'index.php';
require __DIR__ . '/resources/views/layouts/app.php';

