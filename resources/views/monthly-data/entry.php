<?php
/**
 * resources/views/monthly-data/entry.php — Data Entry UI (Tailwind, on app layout).
 */
?>
<div class="space-y-6">

    <?php if ($success): ?><div class="alert-success" role="status"><?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-error" role="alert"><?= $error ?></div><?php endif; ?>
    <?php if ($email_note): ?>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-800">
            <strong>Email Configuration Note:</strong> <?= $email_note ?>
        </div>
    <?php endif; ?>

    <?php /* MANAGER TOKEN REVIEW VIEW */ ?>
    <?php if ($appr_token && ($is_tech_mgr || $is_comm_mgr) && $is_POST === false): ?>
        <?php
            $mc   = $appr_role === 'technical_manager' ? TECH_CATS : COMM_CATS;
            $ph   = implode(',', array_fill(0, count($mc), '?'));
            $rows = db_rows("SELECT pc.name AS cn, p.code, p.label, p.unit, md.value FROM parameters p JOIN parameter_categories pc ON p.category_id=pc.id LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=? WHERE p.category_id IN($ph) ORDER BY pc.display_order, p.code", "i" . str_repeat('i', count($mc)), ...array_merge([$month_id], $mc));
            $cd2  = [];
            foreach ($rows as $r) $cd2[$r['cn']][] = $r;
            $rt   = $appr_role === 'technical_manager' ? 'Technical Manager' : 'Commercial Manager';
        ?>
        <div class="card">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-gray-900">Report Review &amp; Approval</h1>
                <span class="inline-flex items-center rounded-full bg-primary px-3 py-1 text-xs font-semibold text-white"><?= he($month['name']) ?> · <?= $rt ?></span>
            </div>
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"><strong>Note:</strong> This review link expires 7 days from issue. Please act promptly.</div>
            <p class="mt-4 text-sm text-gray-500">Review the entries in your assigned sections below. If all figures are correct click <strong class="text-gray-700">Approve</strong>; otherwise use <strong class="text-gray-700">Request Corrections</strong> with details.</p>
            <?php foreach ($cd2 as $cname => $rs): ?>
                <div class="mt-6">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500"><?= he($cname) ?></h2>
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="table-base bg-white">
                            <thead><tr><th class="table-th">Code</th><th class="table-th">Parameter</th><th class="table-th">Unit</th><th class="table-th">Value</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($rs as $r): ?>
                                    <tr class="hover:bg-gray-50/60">
                                        <td class="table-td"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-primary-dark"><?= he($r['code']) ?></code></td>
                                        <td class="table-td"><?= he($r['label']) ?></td>
                                        <td class="table-td text-gray-400"><?= he($r['unit'] ?? '—') ?></td>
                                        <td class="table-td font-semibold text-gray-900"><?= he($r['value'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <button type="button" id="btnApproveTop" class="btn bg-emerald-600 text-white hover:bg-emerald-700">✔ Approve Report Data</button>
                <button type="button" id="btnShowReject" class="btn-danger">✕ Request Corrections</button>
            </div>
            <textarea id="rejta" rows="4" style="display:none" class="form-input mt-4 resize-y" placeholder="Please describe the corrections required in detail…"></textarea>
            <div id="rejSubmitWrap" style="display:none" class="mt-3 text-center"><button type="button" id="btnDoReject" class="btn-danger">➤ Submit Correction Request</button></div>
            <form id="fA" method="POST"><input type="hidden" name="response" value="approve"></form>
            <form id="fR" method="POST"><input type="hidden" name="response" value="reject"><input type="hidden" name="rejection_reason" id="rh"></form>
        </div>
        <script>
            document.getElementById('btnApproveTop')?.addEventListener('click', () => {
                if (confirm('Confirm Approval\n\nBy clicking OK you confirm that all data entries in your assigned sections are accurate and correct.')) document.getElementById('fA').submit();
            });
            document.getElementById('btnShowReject')?.addEventListener('click', () => {
                const ta = document.getElementById('rejta'); ta.style.display='block'; document.getElementById('rejSubmitWrap').style.display='block'; ta.focus();
            });
            document.getElementById('btnDoReject')?.addEventListener('click', () => {
                const r = document.getElementById('rejta').value.trim();
                if (!r) { alert('Please provide details of the corrections required before submitting.'); return; }
                document.getElementById('rh').value = r; document.getElementById('fR').submit();
            });
        </script>
        <?php return; ?>
    <?php endif; ?>


    <?php /* MONTH HEADER + PROGRESS */ ?>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Monthly Data Entry</h1>
            <p class="mt-1 text-sm text-gray-500">
                <?= he($month['name']) ?>
                <?php if ($month['start_date']): ?> · <?= date('d M Y', strtotime($month['start_date'])) ?> – <?= date('d M Y', strtotime($month['end_date'])) ?><?php endif; ?>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <span class="<?= $is_submitted ? 'badge-approved' : 'badge-pending' ?> uppercase"><?= he($month['status']) ?></span>
            <span class="text-sm text-gray-500">Progress: <strong class="text-gray-800"><?= $saved_cats ?>/<?= $total_cats ?></strong> sections (<?= $pct ?>%)</span>
        </div>
    </div>

    <?php if ($is_submitted): ?>
        <div class="rounded-lg border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-900">🔒 <strong>Read-Only Mode</strong> — this report has been submitted and is locked for editing.</div>
    <?php endif; ?>

    <?php if (empty($cats_params)): ?>
        <div class="card py-14 text-center">
            <p class="text-4xl">📂</p>
            <h2 class="mt-3 text-lg font-semibold text-gray-900">No Parameters Assigned</h2>
            <p class="mt-1 text-sm text-gray-500">Your role does not have any data entry parameters assigned. Please contact your administrator.</p>
        </div>
    <?php else: ?>

    <?php if ($is_admin && $is_submitted === false): ?>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card border-l-4 !py-4 <?= $saved_cats === $total_cats ? '!border-l-emerald-500' : '!border-l-amber-400' ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Saved Sections</p>
            <p class="mt-1 text-2xl font-bold text-gray-900"><?= $saved_cats ?> / <?= $total_cats ?></p>
        </div>
        <div class="card border-l-4 !py-4 <?= $complete_cats === $total_cats ? '!border-l-emerald-500' : '!border-l-amber-400' ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Fully Completed</p>
            <p class="mt-1 text-2xl font-bold text-gray-900"><?= $complete_cats ?> / <?= $total_cats ?></p>
        </div>
        <div class="card border-l-4 !py-4 <?= $total_cats - $complete_cats === 0 ? '!border-l-emerald-500' : '!border-l-red-400' ?>">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Need Attention</p>
            <p class="mt-1 text-2xl font-bold text-gray-900"><?= $total_cats - $complete_cats ?></p>
        </div>
    </div>

    <?php foreach ([
        ['Technical Manager', 'Production · Infrastructure · Water Quality · NRW · Operations', $tech_appr['status'] ?? 'pending', $tech_filled, $tech_appr, 'technical_manager'],
        ['Commercial Manager','Revenue · Customer Care · Accounts · GIS · HR · Related Sections', $comm_appr['status'] ?? 'pending', $comm_filled, $comm_appr, 'commercial_manager'],
    ] as [$ptitle, $psects, $pst, $pfilled, $pappr, $prole]): ?>
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-gray-900"><?= he($ptitle) ?> Review</h3>
                <p class="text-xs text-gray-400"><?= $psects ?></p>
            </div>
            <?= aprBadgeTailwind($pst) ?>
        </div>
        <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm leading-relaxed text-gray-600">
            <?php if ($pfilled === false && $pst === 'pending'): ?>⚠️ This manager's sections are <strong>not yet fully completed</strong>. All data must be entered before sending a review request.
            <?php elseif ($pst === 'pending'): ?>✅ All sections complete — you may now send the review request.
            <?php elseif ($pst === 'notified'): ?>➤ Review request sent on <?= date('d M Y H:i', strtotime($pappr['notified_at'])) ?>. Awaiting response.
            <?php elseif ($pst === 'approved'): ?>✔ Data approved<?= empty($pappr['approved_at']) ? '' : ' on ' . date('d M Y H:i', strtotime($pappr['approved_at'])) ?>.
            <?php else: ?>✕ Corrections requested. Update the data and resend the review request.
                <?php if (empty($pappr['rejection_reason']) === false): ?><div class="mt-2 rounded border-l-4 border-red-400 bg-red-50 p-3 text-red-700"><strong>Feedback:</strong> <?= he($pappr['rejection_reason']) ?></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <form method="POST" class="mt-4">
            <input type="hidden" name="action" value="notify_manager">
            <input type="hidden" name="manager_role" value="<?= $prole ?>">
            <button type="submit" class="btn-primary" <?= $pfilled ? '' : 'disabled title="All sections must be completed before sending."' ?>>➤ <?= $pst === 'pending' ? 'Send Review Request' : ($pst === 'rejected' ? 'Resend Review Request' : 'Request Re-Review') ?></button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; /* admin panels */ ?>

    <?php /* MANAGER SELF-APPROVAL PANEL */ ?>
    <?php if (($is_tech_mgr || $is_comm_mgr) && $is_submitted === false):
        $my_appr = $is_tech_mgr ? $tech_appr : $comm_appr;
        $my_fill = $is_tech_mgr ? $tech_filled : $comm_filled;
        $mst     = $my_appr['status'] ?? 'pending';
        $psects  = $is_tech_mgr ? 'Production · Infrastructure · Water Quality · NRW · Operations' : 'Revenue · Customer Care · Accounts · GIS · HR · Related Sections';
    ?>
    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-gray-900">Your Approval — <?= $is_tech_mgr ? 'Technical' : 'Commercial' ?> Manager</h3>
                <p class="text-xs text-gray-400"><?= $psects ?></p>
            </div>
            <?= aprBadgeTailwind($mst) ?>
        </div>
        <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-600">
            <?php if ($mst === 'approved'): ?>
                ✔ <strong>You have approved this report.</strong> The administrator has been notified.
            <?php elseif ($my_fill === false): ?>
                ⚠️ <strong>Your sections are not yet fully completed.</strong> Approval is locked until all fields are filled.
            <?php else: ?>
                ✅ All your assigned sections are complete. Review the data and submit your approval when satisfied.
            <?php endif; ?>
        </div>
        <?php if ($my_fill && $mst !== 'approved'): ?>
        <form method="POST" class="mt-4">
            <input type="hidden" name="action" value="manager_direct_approve">
            <button type="submit" class="btn-primary"
                onclick="return confirm('Submit Approval\n\nBy clicking OK you confirm that all data entries in your assigned sections are accurate and complete. This will notify the administrator immediately.')">✔ Submit My Approval</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* DATA SECTIONS */ ?>
    <?php foreach ($cats_params as $cd):
        $cat    = $cd['category'];
        $params = $cd['parameters'];
        $sv     = isSaved($month_id, (int)$cat['id'], $role_id, $is_admin);
        $val    = validateSection($month_id, (int)$cat['id']);
        $fld    = ($val === true);
        $inT    = in_array((int)$cat['id'], TECH_CATS);
        $inC    = in_array((int)$cat['id'], COMM_CATS);
    ?>
    <div class="card border-l-4 <?= $sv === false ? '!border-l-gray-300' : ($fld ? '!border-l-primary' : '!border-l-emerald-500') ?>"
         data-section-id="<?= (int)$cat['id'] ?>">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4">
            <h3 class="flex items-center gap-2 font-semibold text-gray-900">
                <?= he($cat['name']) ?>
                <?php if ($inT): ?><span class="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase text-blue-700">Technical</span><?php endif; ?>
                <?php if ($inC): ?><span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700">Commercial</span><?php endif; ?>
            </h3>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400"><?= count($params) ?> fields</span>
                <span id="sb-<?= (int)$cat['id'] ?>" class="<?= $sv && $fld ? 'badge-approved' : 'badge-pending' ?>">
                    <?= $sv === false ? 'Pending' : ($fld ? '✔ Complete' : '! Needs Attention') ?>
                </span>
            </div>
        </div>

        <?php if ($sv && $fld === false): ?>
            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">This section contains empty fields. Fill all fields or enter a dash (-) where data is not applicable.</div>
        <?php endif; ?>


        <div class="grid grid-cols-1 gap-4 pt-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($params as $param):
                $is_ml = in_array($param['id'], ML_PARAMS);
                $cv    = isset($existing_data[$param['code']]) ? he($existing_data[$param['code']]) : '';
                $ie    = (trim($cv) === '');
                $dtMap = ['number'=>'Number','currency'=>'Currency (KSh)','percentage'=>'Percentage (0–100)','text'=>'Text'];
                $hint  = $dtMap[$param['data_type']] ?? 'Text';
            ?>
            <div class="relative rounded-lg border p-4 transition-colors <?= ($param['required'] && $ie) ? 'border-red-300 bg-red-50/40' : ($ie ? 'border-amber-200' : 'border-gray-200 hover:border-primary/40') ?>">
                <span class="absolute right-2.5 top-2.5 h-2 w-2 rounded-full <?= $ie ? ($param['required'] ? 'animate-pulse bg-red-500' : 'bg-amber-400') : 'bg-emerald-500' ?>"></span>
                <div class="mb-2 flex items-start gap-2 pr-4">
                    <code class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-bold text-primary-dark"><?= he($param['code']) ?></code>
                    <div class="min-w-0">
                        <p class="break-words text-sm font-semibold text-gray-800"><?= he($param['label']) ?><?= $param['required'] ? '<span class="text-red-500">*</span>' : '' ?></p>
                        <?php if (empty($param['unit']) === false): ?><p class="text-xs text-gray-400"><?= he($param['unit']) ?></p><?php endif; ?>
                    </div>
                </div>

                <?php if ($is_ml): ?>
                <textarea name="data[<?= he($param['code']) ?>]" rows="3" data-code="<?= he($param['code']) ?>" data-orig="<?= $cv ?>"
                          class="form-input resize-y param-input" <?= $is_submitted ? 'readonly' : '' ?>><?= $cv ?></textarea>
                <?php else: ?>
                <input type="text" name="data[<?= he($param['code']) ?>]" value="<?= $cv ?>" data-code="<?= he($param['code']) ?>" data-orig="<?= $cv ?>"
                       class="form-input param-input" <?= $is_submitted ? 'readonly' : '' ?>>
                <?php endif; ?>
                <p class="mt-1 text-[11px] text-gray-400">Expected: <?= $hint ?><?= $param['required'] ? ' · Required' : ' · Optional' ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($is_submitted === false): ?>
        <form method="POST" class="save-form mt-5 border-t border-gray-100 pt-4 text-center" id="form-<?= (int)$cat['id'] ?>">
            <input type="hidden" name="action" value="save_section">
            <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
            <?php foreach ($params as $p): ?>
                <input type="hidden" name="data[<?= he($p['code']) ?>]" id="hid_<?= he($p['code']) ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn-primary" onclick="return prepareSubmit(this, <?= (int)$cat['id'] ?>)">💾 Save Section</button>
            <p class="mt-2 text-xs text-gray-400">Data is saved to the database — you may return to edit at any time.</p>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>


    <?php /* FINAL SUBMISSION (admin) */ ?>
    <?php if ($is_admin && $is_submitted === false): ?>
    <div class="card border-2 border-emerald-200 bg-emerald-50/40">
        <h3 class="text-center text-lg font-bold text-gray-900">Final Report Submission</h3>
        <p class="mt-1 text-center text-sm text-gray-500">All sections must be fully completed before the report can be submitted.</p>
        <div class="mx-auto mt-5 max-w-xl">
            <div class="h-8 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="flex h-full items-center justify-center rounded-full bg-gradient-to-r from-emerald-500 to-teal-400 text-xs font-bold text-white transition-all"
                     style="width: <?= max($cpct, 8) ?>%"><?= $complete_cats ?> of <?= $total_cats ?> complete</div>
            </div>
            <p class="mt-1.5 text-right text-xs text-gray-400"><?= $cpct ?>%</p>
        </div>
        <?php if ($complete_cats === $total_cats): ?>
        <form method="POST" class="mt-5 text-center">
            <input type="hidden" name="action" value="submit_final">
            <button type="submit" class="btn w-full max-w-md bg-emerald-600 py-2.5 text-base text-white hover:bg-emerald-700"
                onclick="return confirm('Submit Final Report\n\nThis will lock the report and prevent any further editing.\n\nAre you sure you wish to proceed?')">➤ Submit Final Report</button>
            <p class="mt-2 text-xs text-gray-400">The report will be permanently locked after submission.</p>
        </form>
        <?php else: ?>
        <button disabled class="btn mx-auto mt-5 block w-full max-w-md cursor-not-allowed bg-gray-300 text-gray-500">🔒 <?= $total_cats - $complete_cats ?> section(s) still require attention</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; /* end empty check */ ?>

    <!-- Action row -->
    <div class="flex flex-wrap justify-center gap-3">
        <a href="months.php" class="btn-secondary min-w-[150px]">← Back to Months</a>
        <a href="report.php?month_id=<?= $month_id ?>" class="btn-primary min-w-[150px]">📊 View Report</a>
        <?php if ($is_admin && $is_submitted === false): ?>
        <button type="button" id="btnValidateAll" class="btn min-w-[150px] bg-cyan-50 !text-primary-dark ring-1 ring-primary/30 hover:bg-cyan-100">✔ Validate All Fields</button>
        <?php endif; ?>

<script>
function prepareSubmit(btn, catId) {
    const sec = btn.closest('.card');
    sec.querySelectorAll('.param-input').forEach(inp => {
        const code = inp.getAttribute('name').replace('data[', '').replace(']', '');
        const hid  = document.getElementById('hid_' + code);
        if (hid) hid.value = inp.value;
    });
    return true;
}
function showValidateToast(msg, ok) {
    const t = document.createElement('div');
    t.className = 'fixed right-5 top-5 z-50 flex max-w-sm items-start gap-2 rounded-lg border-l-4 bg-white p-4 text-sm shadow-lg ' + (ok ? 'border-emerald-500' : 'border-amber-500');
    t.innerHTML = '<span class="flex-1">' + msg + '</span><button class="text-gray-400 hover:text-gray-600">&times;</button>';
    t.querySelector('button').onclick = () => t.remove();
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 6000);
}
document.getElementById('btnValidateAll')?.addEventListener('click', () => {
    let n = 0;
    document.querySelectorAll('.param-input').forEach(inp => {
        const empty = inp.value.trim() === '';
        const isReq = inp.closest('div.relative') !== null && inp.closest('div.relative').querySelector('.text-red-500') !== null;
        inp.classList.toggle('!border-red-400', empty && isReq);
        if (isReq && empty) n++;
    });
    showValidateToast(
        n ? n + ' required field(s) are empty. Please complete them before submitting.' : 'All fields look good — ready for submission.',
        n === 0
    );
});
let dirty = false;
document.querySelectorAll('.param-input').forEach(i => i.addEventListener('input', () => { dirty = true; }));
document.querySelectorAll('.save-form').forEach(f => f.addEventListener('submit', () => { dirty = false; }));
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
</script>

    </div>
</div>


