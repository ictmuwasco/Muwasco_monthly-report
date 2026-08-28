<?php
/**
 * resources/views/users/index.php — User administration UI (Tailwind, app layout).
 * Expects: $users_result (mysqli_result), $roles_array, $message, $message_type.
 */
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="space-y-6" x-data="userAdmin()">

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">User Management</h1>
            <p class="mt-1 text-sm text-gray-500">Create and manage system accounts and their roles.</p>
        </div>
        <button type="button" @click="openCreate()" class="btn-primary self-start">➕ Create User</button>
    </div>

    <?php if ($message): ?>
        <div class="<?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>" role="status"><?= $h($message) ?></div>
    <?php endif; ?>

    <!-- Users table -->
    <div class="card overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="table-base w-full bg-white">
                <thead><tr>
                    <th class="table-th">User</th>
                    <th class="table-th">Email</th>
                    <th class="table-th">Role</th>
                    <th class="table-th">Status</th>
                    <th class="table-th">Created</th>
                    <th class="table-th">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php while ($u = $users_result->fetch_assoc()):
                    $isSelf = ((int)$u['id'] === (int)$_SESSION['user_id']);
                    $ud = $h(json_encode([
                        'id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'] ?? '',
                        'first_name' => $u['first_name'] ?? '', 'last_name' => $u['last_name'] ?? '',
                        'surname' => $u['surname'] ?? '', 'role_id' => (int)$u['role_id'],
                        'is_active' => (int)$u['is_active'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT));
                ?>
                    <tr class="hover:bg-gray-50/60" data-user-row="<?= (int)$u['id'] ?>">
                        <td class="table-td">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary-dark"><?= $h(strtoupper(substr($u['username'], 0, 1))) ?></span>
                                <div>
                                    <p class="font-semibold text-gray-900"><?= $h(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username']) ?></p>
                                    <p class="text-xs text-gray-400">@<?= $h($u['username']) ?><?= $isSelf ? ' · you' : '' ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="table-td"><?= $h($u['email'] ?: '—') ?></td>
                        <td class="table-td"><span class="badge-pending"><?= $h(ucwords(str_replace('_', ' ', $u['role_name'] ?? 'user'))) ?></span></td>
                        <td class="table-td"><span class="<?= $u['is_active'] ? 'badge-approved' : 'badge-rejected' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td class="table-td text-xs text-gray-500"><?= $h(date('M d, Y', strtotime($u['created_at']))) ?></td>
                        <td class="table-td">
                            <?php if (!$isSelf): ?>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" @click='openEdit(<?= $ud ?>)'>✏️ Edit</button>
                                <form method="POST" onsubmit="return confirm('Toggle active status for this user?');">
                                    <?= csrf_field() ?><input type="hidden" name="toggle_active" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn-secondary !px-2 !py-1 text-xs"><?= $u['is_active'] ? '⏸ Disable' : '▶ Enable' ?></button>
                                </form>
                                <button type="button" class="btn-secondary !px-2 !py-1 text-xs" @click='openReset(<?= $ud ?>)'>🔑 Password</button>
                                <form method="POST" onsubmit="return confirm('Permanently delete this user? This cannot be undone.');">
                                    <?= csrf_field() ?><input type="hidden" name="delete" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn-danger !px-2 !py-1 text-xs">🗑</button>
                                </form>
                            </div>
                            <?php else: ?>
                                <span class="text-xs italic text-gray-400">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit modal -->
    <div x-show="modal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="close()"></div>
        <div class="card relative z-10 w-full max-w-lg">
            <h2 class="card-title" x-text="mode === 'create' ? '➕ Create User' : '✏️ Edit User'"></h2>
            <form id="userForm" method="POST" class="mt-4 space-y-4" @submit.prevent="submitForm($event)">
                <?= csrf_field() ?>
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="create_user" value="1" x-show="mode === 'create'">
                <input type="hidden" name="update_user" value="1" x-show="mode === 'edit'">
                <input type="hidden" name="user_id" :value="form.id">

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <template x-if="mode === 'create'">
                        <div>
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.]+" class="form-input" x-model="form.username">
                        </div>
                    </template>
                    <div>
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input" x-model="form.first_name">
                    </div>
                    <div>
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" x-model="form.last_name">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" x-model="form.email">
                    </div>
                    <div>
                        <label class="form-label">Role *</label>
                        <select name="role_id" required class="form-input" x-model="form.role_id">
                            <?php foreach ($roles_array as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= $h(ucwords(str_replace('_', ' ', $r['name']))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <template x-if="mode === 'create'">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Password *</label>
                             <input type="password" name="password" required minlength="6" class="form-input"></div>
                        <div><label class="form-label">Confirm Password *</label>
                             <input type="password" name="confirm_password" required minlength="6" class="form-input"></div>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="is_active" value="1" checked class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                            Account active
                        </label>
                    </div>
                </template>
                <template x-if="mode === 'edit'">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="is_active" value="1" :checked="form.is_active === 1" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                        Account active
                    </label>
                </template>

                <p x-text="feedback" class="text-center text-sm font-medium" :class="ok ? 'text-emerald-600' : 'text-red-600'"></p>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn-secondary" @click="close()">Cancel</button>
                    <button type="submit" class="btn-primary" x-text="mode === 'create' ? 'Create User' : 'Save Changes'"></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset password modal -->
    <div x-show="resetModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="resetModal = false"></div>
        <div class="card relative z-10 w-full max-w-md">
            <h2 class="card-title">🔑 Reset Password — <span x-text="form.username"></span></h2>
            <form id="resetForm" class="mt-4 space-y-4" @submit.prevent="submitReset($event)">
                <?= csrf_field() ?>
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="user_id" :value="form.id">
                <div><label class="form-label">New Password *</label>
                     <input type="password" name="new_password" required minlength="6" class="form-input"></div>
                <div><label class="form-label">Confirm New Password *</label>
                     <input type="password" name="confirm_password" required minlength="6" class="form-input"></div>
                <p x-text="feedback" class="text-center text-sm font-medium" :class="ok ? 'text-emerald-600' : 'text-red-600'"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="resetModal = false">Cancel</button>
                    <button type="submit" class="btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
function userAdmin() {
    return {
        modal: false, resetModal: false, mode: 'create',
        form: {}, feedback: '', ok: true,
        blank() { return { id: '', username: '', first_name: '', last_name: '', email: '', role_id: 2, is_active: 1 }; },
        openCreate() { this.mode = 'create'; this.form = this.blank(); this.feedback = ''; this.modal = true; },
        openEdit(u)  { this.mode = 'edit';  this.form = { ...this.blank(), ...u }; this.feedback = ''; this.modal = true; },
        openReset(u) { this.form = { ...this.blank(), ...u }; this.resetModal = true; this.feedback = ''; },
        close()      { if (this.ok) setTimeout(() => location.reload(), 250); this.modal = false; },
        async postForm(formEl) {
            const res = await fetch(location.pathname, { method: 'POST', body: new FormData(formEl) });
            try { return await res.json(); } catch { return { success: false, message: 'Unexpected server response.' }; }
        },
        async submitForm(e) {
            const j = await this.postForm(e.target);
            this.ok = !!j.success; this.feedback = j.message || '';
            if (j.success) setTimeout(() => location.reload(), 700);
        },
        async submitReset(e) {
            const j = await this.postForm(e.target);
            this.ok = !!j.success; this.feedback = j.message || '';
            if (j.success) setTimeout(() => { this.resetModal = false; }, 900);
        },
    };
}
</script>


