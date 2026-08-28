<?php

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$num = function ($v) {
    $v = (float)$v;
    if ($v >= 1000000) return number_format($v / 1000000, 1) . 'M';
    if ($v >= 1000) return number_format($v / 1000, 1) . 'K';
    return number_format($v);
};
?>
<div class="space-y-6">

    <!-- Welcome header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Welcome back, <?= $h($_SESSION['user_full_name'] ?? 'User') ?> 👋</h1>
            <p class="mt-1 text-sm text-gray-500">
                <span class="badge-pending mr-2">Role: <?= $h($role_display ?? 'User') ?></span>
                Assigned parameters: <strong class="text-gray-700"><?= (int)$summaryStats['user_parameters'] ?></strong>
                · Last login: <?= date('M d, Y H:i') ?>
            </p>
        </div>
        <a href="add_data.php" class="btn-primary self-start">✏️ Enter Data</a>
    </div>

    <!-- Summary stat cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Total Months</p>
            <p class="mt-1 text-3xl font-bold text-primary"><?= (int)$summaryStats['total_months'] ?></p>
            <p class="mt-2 text-xs text-gray-400"><?= (int)$summaryStats['submitted_months'] ?> submitted · <?= (int)$summaryStats['pending_months'] ?> pending</p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Submitted Reports</p>
            <p class="mt-1 text-3xl font-bold text-emerald-600"><?= (int)$summaryStats['submitted_months'] ?></p>
            <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100">
                <div class="h-1.5 rounded-full bg-emerald-500" style="width: <?= (int)($summaryStats['submitted_months'] / max(1, $summaryStats['total_months']) * 100) ?>%"></div>
            </div>
            <p class="mt-2 truncate text-xs text-gray-400">Latest: <?= $h($summaryStats['latest_month']['name'] ?? 'No reports') ?></p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Draft Reports</p>
            <p class="mt-1 text-3xl font-bold text-amber-500"><?= (int)$summaryStats['pending_months'] ?></p>
            <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100">
                <div class="h-1.5 rounded-full bg-amber-400" style="width:<?= (int)($summaryStats['pending_months'] / max(1, $summaryStats['total_months']) * 100) ?>%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-400">Awaiting submission</p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">My Parameters</p>
            <p class="mt-1 text-3xl font-bold text-gray-900"><?= (int)$summaryStats['user_parameters'] ?></p>
            <p class="mt-2 text-xs text-gray-400">Assigned tracking parameters</p>
        </div>
        <?php if (isAdmin() && isset($overall_stats['overall_percentage'])): ?>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Role Progress</p>
            <p class="mt-1 text-3xl font-bold text-cyan-600"><?= (int)$overall_stats['overall_percentage'] ?>%</p>
            <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100">
                <div class="h-1.5 rounded-full bg-cyan-500" style="width:<?= (int)$overall_stats['overall_percentage'] ?>%"></div>
            </div>
            <p class="mt-2 text-xs text-gray-400"><?= (int)$overall_stats['completed_roles'] ?> of <?= (int)$overall_stats['total_roles'] ?> roles complete</p>
        </div>
<!-- Parameter chart cards -->
    <?php if (!empty($filtered_charts)): ?>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <?php foreach ($filtered_charts as $key => $chart_info):
            $data   = $chart_data[$key];
            $cnt    = count($data);
            $latest = $cnt ? (float)$data[$cnt - 1]['value'] : 0;
            $prev   = $cnt > 1 ? (float)$data[$cnt - 2]['value'] : $latest;
            $change = $prev != 0 ? (($latest - $prev) / $prev) * 100 : 0;
            $first  = $data[0]['month_name'] ?? '';
            $last   = $data[$cnt - 1]['month_name'] ?? '';
            $icMap  = ['bi-people'=>['👥','text-cyan-600 bg-cyan-50'],'bi-cash-stack'=>['💰','text-red-600 bg-red-50'],'bi-graph-up-arrow'=>['📈','text-emerald-600 bg-emerald-50'],'bi-droplet'=>['💧','text-blue-600 bg-blue-50']];
            $icon   = $icMap[$chart_info['icon'] ?? ''] ?? ['📊','text-gray-600 bg-gray-100'];
        ?>
        <div class="card">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg <?= $icon[1] ?> text-lg"><?= $icon[0] ?></span>
                    <div>
                        <p class="font-semibold text-gray-900"><?= $h($chart_info['title']) ?></p>
                        <p class="text-xs text-gray-400"><?= $cnt ?>-Month Trend</p>
                    </div>
                </div>
                <span class="<?= $change >= 0 ? 'badge-approved' : 'badge-rejected' ?>"><?= $change >= 0 ? '▲' : '▼' ?> <?= number_format(abs($change), 1) ?>%</span>
            </div>
            <p class="mt-3 text-3xl font-bold text-gray-900"><?= $num($latest) ?></p>
            <p class="text-xs text-gray-400">Current value<?= ($first && $last) ? ' · ' . $h($first) . ' → ' . $h($last) : '' ?></p>
            <div class="relative mt-3 h-44"><canvas id="chart-<?= $h($key) ?>"></canvas></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">You don't have access to any chart parameters yet. Contact your administrator for access.</div>
    <?php endif; ?>

    <?php if (count($filtered_charts) > 1 && !empty($recent_chart_months)): ?>
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="card-title mb-0">📊 Combined Performance Overview <span class="badge-pending ml-1"><?= count($recent_chart_months) ?>-Month Trend</span></h2>
            <div class="flex gap-1.5">
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" data-timeframe="12m">12M</button>
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" data-timeframe="6m">6M</button>
                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" data-timeframe="3m">3M</button>
            </div>
        </div>
        <div class="relative mt-4 h-72"><canvas id="combined-chart"></canvas></div>
    </div>
    <?php endif; ?>
<!-- Admin: role progress -->
    <?php if (isAdmin() && !empty($role_progress)): ?>
    <div>
        <h2 class="card-title mb-3">🚦 Report Submission Progress by Role</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($role_progress as $rp):
                $rCls = ['completed' => 'badge-approved', 'in_progress' => 'badge-pending', 'not_started' => 'badge-rejected'][$rp['status']] ?? 'badge-pending';
                $bar  = ['completed' => 'bg-emerald-500', 'in_progress' => 'bg-amber-400', 'not_started' => 'bg-red-400'][$rp['status']] ?? 'bg-gray-300';
            ?>
            <div class="card border-l-4 <?= $rp['status'] === 'completed' ? '!border-l-emerald-500' : ($rp['status'] === 'in_progress' ? '!border-l-amber-400' : '!border-l-red-400') ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold text-gray-900"><?= $h($rp['role_name']) ?></p>
                        <?php if ($rp['role_description']): ?><p class="text-xs text-gray-400"><?= $h($rp['role_description']) ?></p><?php endif; ?>
                    </div>
                    <span class="<?= $rCls ?>"><?= $h(ucwords(str_replace('_', ' ', $rp['status']))) ?></span>
                </div>
                <div class="mt-4 flex items-end justify-between">
                    <p class="text-2xl font-bold text-gray-900"><?= (int)$rp['percentage'] ?>%</p>
                    <p class="text-xs text-gray-400"><?= (int)$rp['saved_params'] ?> / <?= (int)$rp['total_params'] ?> params</p>
                </div>
                <div class="mt-2 h-2 w-full rounded-full bg-gray-100">
                    <div class="h-2 rounded-full <?= $bar ?>" style="width:<?= (int)$rp['percentage'] ?>%"></div>
                </div>
                <p class="mt-2 text-xs text-gray-400"><?= $rp['last_updated'] ? 'Last updated ' . date('M d, H:i', strtotime($rp['last_updated'])) : 'Not started' ?></p>
                <?php if (!empty($rp['users'])): ?>
                <div class="mt-3 flex gap-1.5">
                    <?php foreach ($rp['users'] as $u_): ?>
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/15 text-[10px] font-bold text-primary-dark" title="<?= $h(($u_['first_name'] ?? '') . ' ' . ($u_['last_name'] ?? $u_['username'])) ?>"><?= $h(strtoupper(substr($u_['first_name'] ?? $u_['username'], 0, 1))) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Progress charts -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="card"><h2 class="card-title mb-3">Progress by Role</h2><div class="relative h-72"><canvas id="role-progress-chart"></canvas></div></div>
            <div class="card"><h2 class="card-title mb-3">Parameters Completion</h2><div class="relative h-72"><canvas id="parameter-progress-chart"></canvas></div></div>
        </div>
    <?php endif; ?>

    <!-- Recent activity -->
    <?php if (!empty($userActivity)): ?>
    <div class="card overflow-hidden p-0">
        <div class="border-b border-gray-100 px-5 py-4"><h2 class="card-title mb-0">🕒 Recent Activity</h2></div>
        <div class="overflow-x-auto">
            <table class="table-base w-full bg-white">
                <thead><tr><th class="table-th">Month</th><th class="table-th">Status</th><th class="table-th">Entries</th><th class="table-th">Last Updated</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($userActivity as $act): ?>
                    <tr class="hover:bg-gray-50/60">
                        <td class="table-td font-medium text-gray-900"><?= $h($act['name']) ?></td>
                        <td class="table-td"><span class="<?= $act['status'] === 'submitted' ? 'badge-approved' : 'badge-pending' ?>"><?= $h(ucfirst($act['status'])) ?></span></td>
                        <td class="table-td"><?= (int)$act['entries_count'] ?> entries</td>
                        <td class="table-td text-gray-500"><?= $h($act['last_updated'] ? date('M d, Y H:i', strtotime($act['last_updated'])) : 'N/A') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
        <?php endif; ?>
<script src="/monthly_report/public/assets/js/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartTheme = {
        gridColor: 'rgba(148,163,184,0.15)',
        tickColor: 'rgba(100,116,139,0.8)'
    };
    function getChartOptions(title) {
        return {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => { const v = c.parsed.y ?? c.raw ?? 0; return `${title}: ${v.toLocaleString()}`; } } }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: chartTheme.gridColor }, ticks: { color: chartTheme.tickColor, callback: v => v >= 1000 ? (v/1000)+'K' : v } },
                x: { grid: { display: false }, ticks: { color: chartTheme.tickColor } }
            }
        };
    }
    <?php foreach ($filtered_charts as $ck => $ci): ?>
    <?php if (!empty($chart_data[$ck])): ?>
    (() => {
        const ctx = document.getElementById('chart-<?= $h($ck) ?>');
        if (!ctx) return;
        const data = <?= json_encode(array_map(fn($r) => ['name' => $r['month_name'], 'value' => (float)$r['value']], $chart_data[$ck] ?? []), JSON_HEX_APOS) ?>;
        const labels = data.map(d => (d.name || '').split(' ')[0]);
        const values = data.map(d => d.value);
        new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [{
                label: '<?= $h($ci['title']) ?>', data: values,
                borderColor: '<?= $ci['color'] ?>', borderWidth: 3, fill: true, tension: 0.4,
                pointBackgroundColor: '<?= $ci['color'] ?>', pointRadius: 4, pointHoverRadius: 6
            }] },
            options: getChartOptions('<?= $h($ci['title']) ?>')
        });
    })();
    <?php endif; ?>
    <?php endforeach; ?>

    let combinedChart = null;
    function initCombined(months, charts, data) {
        const ctx = document.getElementById('combined-chart');
        if (!ctx || !months.length) return;
        const colors = {'active_connections':'#06b6d4','total_expenditure':'#ef4444','total_revenue':'#10b981','water_production':'#0ea5e9'};
        const labels = months.map(m => (m.name||'').split(' ')[0]);
        const datasets = Object.keys(charts).map(k => {
            const pData = data.filter(d => d.parameter_id == charts[k].id);
            const values = months.map(m => { const dp = pData.find(d => d.month_name === m.name); return dp ? dp.value : 0; });
            return { label: charts[k].title, data: values, borderColor: colors[k]||'#0e7490', borderWidth: 2, fill: false, tension: 0.3, pointRadius: 3 };
        });
        combinedChart = new Chart(ctx, { type: 'line', data: { labels, datasets },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, color: chartTheme.tickColor } } },
                scales: { y: { beginAtZero: true, grid: { color: chartTheme.gridColor }, ticks: { color: chartTheme.tickColor } }, x: { grid: { display: false }, ticks: { color: chartTheme.tickColor } } } } });
    }
    window.setChartTimeframe = function (r) {
        if (!combinedChart) return;
        const mins = {'12m':0,'6m':6,'3m':9}; const n = mins[r] ?? 0;
        const weeks = <?= json_encode($recent_chart_months) ?>; const slice = weeks.slice(n);
        const pids = <?= json_encode(array_column($filtered_charts, 'id')) ?>;
        const total = <?= json_encode($combined_chart_data) ?>;
        const vs = slice.map(m => { const dp = total.find(d => d.parameter_id == ... ? 0 : 0);
        // rebuild datasets for the timeframe
        combinedChart.data.labels = slice.map(m => (m.name||'').split(' ')[0]);
        combinedChart.data.datasets.forEach(ds => {
            const meta = Object.values(<?= json_encode($filtered_charts) ?>).find(c => c.title === ds.label);
            const id = meta ? meta.id : null;
            ds.data = slice.map(m => { const dp2 = total.find(d => d.parameter_id == id && d.month_name === m.name); return dp2 ? dp2.value : 0; });
        });
        combinedChart.update();
    };
    <?php if (isAdmin()): ?>
    (() => {
        const roleP = <?= json_encode($role_progress) ?>;
        const rctx = document.getElementById('role-progress-chart');
        if (rctx && roleP.length) {
            const colors = roleP.map(r => r.status==='completed' ? '#10b981' : (r.status==='in_progress' ? '#f59e0b' : '#ef4444'));
            new Chart(rctx, { type: 'bar', data: { labels: roleP.map(r=>r.role_name), datasets: [{ data: roleP.map(r=>r.percentage), backgroundColor: colors, borderColor: colors, borderWidth: 1, borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, max: 100, grid: { color: chartTheme.gridColor }, ticks: { color: chartTheme.tickColor, callback: v=>v+'%' } }, x: { grid: { display: false }, ticks: { color: chartTheme.tickColor } } } } });
        }
        const pctx = document.getElementById('parameter-progress-chart');
        if (pctx && <?= (int)($overall_stats['total_parameters'] ?? 0) ?> > 0) {
            const saved = <?= (int)($overall_stats['saved_parameters'] ?? 0) ?>;
            new Chart(pctx, { type: 'doughnut', data: { labels: ['Saved','Remaining'], datasets: [{ data: [saved, <?= (int)($overall_stats['total_parameters'] ?? 0) ?> - saved], backgroundColor: ['#10b981','#9ca3af'], borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom' } } } });
        }
    })();
    <?php endif; ?>
    initCombined(<?= json_encode($recent_chart_months) ?>, <?= json_encode($filtered_charts) ?>, <?= json_encode($combined_chart_data) ?>);
    
    // Bind timeframe buttons
    document.querySelectorAll('[data-timeframe]').forEach(btn => {
        btn.addEventListener('click', function() {
            window.setChartTimeframe(this.getAttribute('data-timeframe'));
        });
    });
});
</script>
    </div>