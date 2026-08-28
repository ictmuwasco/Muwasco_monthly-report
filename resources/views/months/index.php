<?php
/**
 * resources/views/months/index.php — Month management UI (Tailwind, app layout).
 * Expects: $months_result (mysqli_result), $stats, $success, $error, $emailStatus,
 *          $phpmailer_available, site_base().
 */
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="space-y-6">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Month Management</h1>
            <p class="mt-1 text-sm text-gray-500">Create and manage reporting periods for water system data collection.</p>
        </div>
        <span class="badge-approved self-start sm:self-center">🛡 Administrator Access</span>
    </div>

    <?php if ($phpmailer_available === false): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-800">
            <strong>Email not configured:</strong> PHPMailer could not be loaded. Months can still be created,
            but employees will not receive email notifications. Run <code class="rounded bg-amber-100 px-1">composer require phpmailer/phpmailer</code>.
        </div>
    <?php endif; ?>

    <?php if ($success): ?><div class="alert-success" role="status"><strong>Success!</strong> <?= $h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-error" role="alert"><strong>Error!</strong> <?= $h($error) ?></div><?php endif; ?>

    <?php if (!empty($emailStatus)): ?>
        <?php if ($emailStatus['sent'] > 0 && $emailStatus['failed'] === 0): ?>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                📧 <strong>Email Notifications Sent</strong> — notified <?= $emailStatus['sent'] ?> employee<?= $emailStatus['sent'] > 1 ? 's' : '' ?> about the new reporting month.
            </div>
        <?php else: ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                ⚠️ <strong><?= $emailStatus['sent'] > 0 ? 'Partial Email Delivery' : 'Email Notifications Failed' ?></strong> —
                sent <?= (int)$emailStatus['sent'] ?>, failed <?= (int)$emailStatus['failed'] ?>.
                <?php if (!empty($emailStatus['errors'])): ?>
                    <ul class="mt-2 list-inside list-disc space-y-0.5"><?php foreach ($emailStatus['errors'] as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <!-- Create month -->
        <div class="card lg:col-span-1">
            <h2 class="card-title">➕ Create New Month</h2>
            <form method="POST" id="createMonthForm" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_month">
                <div>
                    <label class="form-label" for="m-name">Month Name *</label>
                    <input type="text" id="m-name" name="name" class="form-input" placeholder="e.g., December 2024" required>
                    <p class="mt-1 text-xs text-gray-400">Display name for the reporting period</p>
                </div>
                <div>
                    <label class="form-label" for="m-start">Start Date *</label>
                    <input type="date" id="m-start" name="start_date" class="form-input" required onchange="updateEndDate()">
                    <p class="mt-1 text-xs text-gray-400">First day of the reporting period</p>
                </div>
                <div>
                    <label class="form-label" for="m-end">End Date *</label>
                    <input type="date" id="m-end" name="end_date" class="form-input" required>
                    <p class="mt-1 text-xs text-gray-400">Last day (auto-set from start date)</p>
                </div>
                <button type="submit" id="submitBtn" class="btn-primary w-full">Create Month<?= $phpmailer_available ? ' &amp; Notify Employees' : '' ?></button>
                <?php if ($phpmailer_available): ?>
                    <p class="text-center text-xs text-gray-400">📧 All active employees will receive an email notification upon creation.</p>
                <?php endif; ?>
            </form>
        </div>

        <!-- Months list -->
        <div class="lg:col-span-2">
            <div class="card overflow-hidden p-0">
                <div class="border-b border-gray-100 px-5 py-4"><h2 class="card-title mb-0">📅 Existing Months</h2></div>

                <?php if ($months_result->num_rows === 0): ?>
                    <div class="px-5 py-12 text-center">
                        <p class="text-4xl">🗓️</p>
                        <h3 class="mt-3 font-semibold text-gray-900">No Months Created Yet</h3>
                        <p class="mt-1 text-sm text-gray-500">Start by creating your first reporting month using the form.</p>
                    </div>
                <?php else: ?>

                <div class="overflow-x-auto">
                    <table class="table-base w-full bg-white">
                        <thead><tr>
                            <th class="table-th">Month</th>
                            <th class="table-th">Reporting Period</th>
                            <th class="table-th">Status</th>
                            <th class="table-th">Created</th>
                            <th class="table-th">Actions</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php while ($month = $months_result->fetch_assoc()):
                            $isNew = isset($_SESSION['last_created_month']) && $_SESSION['last_created_month'] == $month['id'];
                            $name  = $month['name'] ?? date('F Y', strtotime($month['month_year']));
                        ?>
                            <tr class="<?= $isNew ? 'bg-emerald-50/50' : '' ?> hover:bg-gray-50/60">
                                <td class="table-td">
                                    <span class="font-semibold text-gray-900"><?= $h($name) ?></span><br>
                                    <span class="text-xs text-gray-400">#<?= $month['id'] ?> · <?= $h(date('F Y', strtotime($month['month_year']))) ?></span>
                                </td>
                                <td class="table-td text-sm"><?= $h(date('M d, Y', strtotime($month['start_date']))) ?> → <?= $h(date('M d, Y', strtotime($month['end_date']))) ?></td>
                                <td class="table-td"><span class="<?= $month['status'] === 'submitted' ? 'badge-approved' : 'badge-pending' ?>"><?= $h(ucfirst($month['status'])) ?></span></td>
                                <td class="table-td text-xs text-gray-500"><?= $h(date('M d, Y', strtotime($month['created_at']))) ?><br>by <?= $h($month['created_by']) ?></td>
                                <td class="table-td">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <?php if ($month['status'] === 'draft'): ?>
                                            <a href="<?= site_base() ?>/add_data.php?month_id=<?= $month['id'] ?>" class="btn-secondary !py-1 text-xs">✏️ Enter Data</a>
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this month? This action cannot be undone.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_month">
                                                <input type="hidden" name="month_id" value="<?= $month['id'] ?>">
                                                <button type="submit" class="btn-danger !py-1 text-xs">🗑 Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <a href="<?= site_base() ?>/add_data.php?month_id=<?= $month['id'] ?>" class="btn-secondary !py-1 text-xs">👁 View Data</a>
                                            <span class="block w-full text-[11px] text-gray-400">🔒 Submitted (read-only)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile;
                        unset($_SESSION['last_created_month'], $_SESSION['last_deleted_month']);
                        ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>


    <!-- Statistics -->
    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <div class="card !py-4 text-center">
            <p class="text-3xl font-bold text-gray-900"><?= (int)$stats['total_months'] ?></p>
            <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Total Months</p>
        </div>
        <div class="card !py-4 text-center">
            <p class="text-3xl font-bold text-amber-500"><?= (int)$stats['draft_months'] ?></p>
            <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Draft Months</p>
        </div>
        <div class="card !py-4 text-center">
            <p class="text-3xl font-bold text-emerald-600"><?= (int)$stats['submitted_months'] ?></p>
            <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Submitted Months</p>
        </div>
        <div class="card !py-4 text-center">
            <p class="text-3xl font-bold text-primary"><?= $stats['latest_month'] ? $h(date('M Y', strtotime($stats['latest_month']))) : '—' ?></p>
            <p class="mt-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Latest Month</p>
        </div>
    </div>
</div>

<script>
function updateEndDate() {
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput   = document.querySelector('input[name="end_date"]');
    const nameInput  = document.querySelector('input[name="name"]');
    if (!startInput.value) return;
    const d = new Date(startInput.value);
    endInput.value = new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0];
    if (!nameInput.value.trim()) nameInput.value = d.toLocaleString('default', { month: 'long', year: 'numeric' });
}
document.addEventListener('DOMContentLoaded', () => {
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput   = document.querySelector('input[name="end_date"]');
    const nameInput  = document.querySelector('input[name="name"]');
    if (!startInput || startInput.value) return;
    const today = new Date();
    const pm = today.getMonth() === 0 ? 11 : today.getMonth() - 1;
    const py = today.getMonth() === 0 ? today.getFullYear() - 1 : today.getFullYear();
    startInput.value = new Date(py, pm, 1).toISOString().split('T')[0];
    endInput.value   = new Date(py, pm + 1, 0).toISOString().split('T')[0];
    if (!nameInput.value.trim()) nameInput.value = new Date(py, pm, 1).toLocaleString('default', { month: 'long', year: 'numeric' });
    nameInput.focus();
});
document.getElementById('createMonthForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.textContent = 'Creating…';
});
</script>
