<div class="space-y-6">

    <!-- Page header: title + description (left), actions (right) -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-desc">
                Reporting period: <span class="font-semibold text-gray-700"><?= htmlspecialchars($latest['month_year'] ?? '—') ?></span> ·
                Welcome back, <?= htmlspecialchars($user_info['full_name']) ?>.
            </p>
        </div>
        <div class="page-actions">
            <a href="add_data.php" class="btn-primary">✏️ Enter Data</a>
            <a href="reports.php" class="btn-secondary">📊 Reports</a>
        </div>
    </div>

    <!-- Stat cards: icon + label, metric, supporting explanation -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card flex flex-col">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4.5 w-4.5" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75"/></svg>
                </span>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Data Completion<?= ($latest['month_year'] ?? '') ? ' · ' . htmlspecialchars($latest['month_year']) : '' ?></p>
            </div>
            <p class="mt-3 text-3xl font-bold text-primary"><?= $completion ?>%</p>
            <div class="mt-3 h-2 w-full rounded-full bg-gray-100" role="progressbar" aria-valuenow="<?= $completion ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Data completion">
                <div class="h-2 rounded-full bg-primary transition-all" style="width: <?= $completion ?>%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-400"><?= $entered ?> of <?= $expected ?> numeric parameters recorded</p>
        </div>

        <div class="card flex flex-col">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-amber-100 text-amber-600" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pending Approvals</p>
            </div>
            <p class="mt-3 text-3xl font-bold <?= ((int)$stats['pending_approvals']) > 0 ? 'text-amber-500' : 'text-emerald-600' ?>">
                <?= (int) $stats['pending_approvals'] ?>
            </p>
            <p class="mt-auto pt-4 text-xs text-gray-400">Reports awaiting manager review</p>
        </div>

        <div class="card flex flex-col">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-emerald-100 text-emerald-600" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </span>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Approved Reports</p>
            </div>
            <p class="mt-3 text-3xl font-bold text-emerald-600"><?= (int) $stats['approved_count'] ?></p>
            <p class="mt-auto pt-4 text-xs text-gray-400">All-time manager approvals</p>
        </div>

        <div class="card flex flex-col">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-slate-100 text-slate-500" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg>
                </span>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Historical Records</p>
            </div>
            <p class="mt-3 text-3xl font-bold text-gray-900"><?= number_format((int) $stats['total_records']) ?></p>
            <p class="mt-auto pt-4 text-xs text-gray-400">Data points preserved across all periods</p>
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
