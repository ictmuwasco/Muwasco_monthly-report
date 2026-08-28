<?php
/**
 * resources/views/assignments/index.php — Role ↔ Parameter/Category assignment UI.
 * Expects: $roles, $categories, $parameters, $parameter_assignments, $category_assignments,
 *          $param_assignments_by_role, $cat_assignments_by_role, $role_param_counts,
 *          $role_cat_counts, $users_per_role, $users, $success, $error.
 */
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="space-y-6">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Parameter &amp; Category Assignments</h1>
            <p class="mt-1 text-sm text-gray-500">Control which roles can access which data entry parameters and categories.</p>
        </div>
        <span class="badge-approved self-start sm:self-center">🛡 Administrator Access</span>
    </div>

    <?php if ($success): ?><div class="alert-success" role="status"><?= $h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-error" role="alert"><?= $h($error) ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Total Roles</p>
            <p class="mt-1 text-3xl font-bold text-primary"><?= count($roles) ?></p>
            <p class="mt-3 text-xs text-gray-400">[<?= count($users) ?> active users]</p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Parameter Assignments</p>
            <p class="mt-1 text-3xl font-bold text-emerald-600"><?= count($parameter_assignments) ?></p>
            <p class="mt-3 text-xs text-gray-400">Parameters assigned to roles</p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Category Assignments</p>
            <p class="mt-1 text-3xl font-bold text-amber-500"><?= count($category_assignments) ?></p>
            <p class="mt-3 text-xs text-gray-400">Categories assigned to roles</p>
        </div>
        <div class="card !py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Available Parameters</p>
            <p class="mt-1 text-3xl font-bold text-gray-900"><?= count($parameters) ?></p>
            <p class="mt-3 text-xs text-gray-400">Across <?= count($categories) ?> categories</p>
        </div>
    </div>

    <!-- Assign forms -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        <!-- Assign Parameter -->
        <div class="card">
            <h2 class="card-title">🔗 Assign Parameter to Role</h2>
            <form method="POST" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_assignment">
                <div>
                    <label class="form-label" for="ap-role">Select Role</label>
                    <select name="role_id" id="ap-role" class="form-input" required>
                        <option value="">Choose a role...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int)$role['id'] ?>"><?= $h($role['name']) ?> (<?= (int)($users_per_role[$role['id']]['user_count'] ?? 0) ?> users)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="ap-param">Select Parameter</label>
                    <select name="parameter_id" id="ap-param" class="form-input" required>
                        <option value="">Choose a parameter...</option>
                        <?php foreach ($parameters as $param): ?>
                            <option value="<?= (int)$param['id'] ?>">[<?= $h($param['code']) ?>] <?= $h($param['label']) ?><?= $param['category_name'] ? ' (' . $h($param['category_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary">＋ Assign Parameter</button>
            </form>
        </div>

        <!-- Assign Category -->
        <div class="card">
            <h2 class="card-title">📁 Assign Category to Role</h2>
            <form method="POST" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_category_assignment">
                <div>
                    <label class="form-label" for="ac-role">Select Role</label>
                    <select name="role_id" id="ac-role" class="form-input" required>
                        <option value="">Choose a role...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int)$role['id'] ?>"><?= $h($role['name']) ?> (<?= (int)($users_per_role[$role['id']]['user_count'] ?? 0) ?> users)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="ac-cat">Select Category</label>
                    <select name="category_id" id="ac-cat" class="form-input" required>
                        <option value="">Choose a category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= $h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary">＋ Assign Category</button>
            </form>
<!-- Current assignments -->
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">

        <!-- Parameter assignments by role -->
        <div>
            <h2 class="card-title mb-3">🔗 Current Parameter Assignments</h2>
            <?php if (empty($param_assignments_by_role)): ?>
                <div class="card py-10 text-center text-sm text-gray-400">No parameter assignments found.</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($param_assignments_by_role as $roleName => $roleAssignments): ?>
                        <div class="card p-0 overflow-hidden">
                            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                                <h3 class="font-semibold text-gray-900"><?= $h($roleName) ?></h3>
                                <span class="badge-pending"><?= count($roleAssignments) ?> params</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="table-base w-full bg-white">
                                    <thead><tr><th class="table-th">Parameter</th><th class="table-th">Category</th><th class="table-th">Actions</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($roleAssignments as $a): ?>
                                            <tr class="hover:bg-gray-50/60">
                                                <td class="table-td">
                                                    <span class="font-semibold text-gray-800"><?= $h($a['parameter_code']) ?></span>
                                                    <span class="block text-xs text-gray-500"><?= $h($a['parameter_label']) ?></span>
                                                </td>
                                                <td class="table-td text-sm text-gray-500"><?= $h($a['category_name'] ?: 'Uncategorized') ?></td>
                                                <td class="table-td">
                                                    <form method="POST" onsubmit="return confirm('Remove this parameter assignment?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="remove_assignment">
                                                        <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                                                        <button type="submit" class="btn-danger !px-2 !py-1 text-xs">🗑 Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Category assignments by role -->
        <div>
            <h2 class="card-title mb-3">📁 Current Category Assignments</h2>
            <?php if (empty($cat_assignments_by_role)): ?>
                <div class="card py-10 text-center text-sm text-gray-400">No category assignments found.</div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($cat_assignments_by_role as $roleName => $roleAssignments): ?>
<!-- Users by role -->
    <?php
    $users_by_role = [];
    foreach ($users as $user) {
        $rn = $user['role_name'] ?: 'No Role';
        $users_by_role[$rn][] = $user;
    }
    ?>
    <div>
        <h2 class="card-title mb-3">👥 Users by Role</h2>
        <?php if (!$users_by_role): ?>
            <div class="card py-10 text-center text-sm text-gray-400">No active users found.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($users_by_role as $roleName => $roleUsers): ?>
                    <div class="card">
                        <h3 class="font-semibold text-gray-900">
                            <?= $h($roleName) ?>
                            <span class="ml-1 text-xs font-normal text-gray-400">(<?= count($roleUsers) ?> user<?= count($roleUsers) !== 1 ? 's' : '' ?>)</span>
                        </h3>
                        <ul class="mt-3 space-y-2">
                            <?php foreach ($roleUsers as $user):
                                $display = trim(($user['full_name'] ?? '')) ?: $user['username'];
                                $parts   = preg_split('/\s+/', trim($display));
                                $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                                $initials = $initials ?: strtoupper(substr($user['username'], 0, 2));
                            ?>
                                <li class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary-dark"><?= $h($initials) ?></span>
                                    <span class="truncate text-sm font-medium text-gray-700"><?= $h($display) ?></span>
                                    <span class="ml-auto truncate text-xs text-gray-400"><?= $h($user['email'] ?? '—') ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
                        <div class="card overflow-hidden p-0">
                            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                                <h3 class="font-semibold text-gray-900"><?= $h($roleName) ?></h3>
                                <span class="badge-pending"><?= count($roleAssignments) ?> cats</span>
                            </div>
                            <div class="divide-y divide-gray-100">
                                <?php foreach ($roleAssignments as $a): ?>
                                    <div class="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50/60">
                                        <span class="text-sm font-medium text-gray-700"><?= $h($a['category_name']) ?></span>
                                        <form method="POST" onsubmit="return confirm('Remove this category assignment?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_category_assignment">
                                            <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                                            <button type="submit" class="btn-danger !px-2 !py-1 text-xs">🗑 Remove</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>