<?php
// user_management.php — single file, no AJAX, no get_user.php needed
session_start();
require_once 'auth_functions.php';
require_once 'db.php';

requireLogin();
if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit();
}

$message      = '';
$message_type = '';

// ═══════════════════════════════════════════════
// CREATE USER
// ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {

    $username         = trim($_POST['username']       ?? '');
    $password         = $_POST['password']             ?? '';
    $confirm_password = $_POST['confirm_password']     ?? '';
    $email            = trim($_POST['email']           ?? '');
    $first_name       = trim($_POST['first_name']      ?? '');
    $last_name        = trim($_POST['last_name']       ?? '');
    $surname          = trim($_POST['surname']         ?? '');
    $role_id          = intval($_POST['role_id']       ?? 0);
    $is_active        = isset($_POST['is_active'])     ? 1 : 0;

    if ($username === '' || $password === '') {
        $message = 'Username and password are required.';
        $message_type = 'danger';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $message_type = 'danger';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $message_type = 'danger';
    } elseif ($role_id <= 0) {
        $message = 'Please select a role.';
        $message_type = 'danger';
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$chk) {
            $message = 'DB error: ' . $conn->error;
            $message_type = 'danger';
        } else {
            $chk->bind_param("s", $username);
            $chk->execute();
            $chk->store_result();
            $exists = $chk->num_rows > 0;
            $chk->close();

            if ($exists) {
                $message = 'Username already exists. Choose a different one.';
                $message_type = 'danger';
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                // 8 params: s s s s s s i i
                $ins = $conn->prepare(
                    "INSERT INTO users (username, password, email, first_name, last_name, surname, role_id, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                if (!$ins) {
                    $message = 'DB error: ' . $conn->error;
                    $message_type = 'danger';
                } else {
                    $ins->bind_param("ssssssii", $username, $hashed, $email, $first_name, $last_name, $surname, $role_id, $is_active);
                    if ($ins->execute()) {
                        $message = 'User "' . htmlspecialchars($username) . '" created successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'DB error: ' . $ins->error;
                        $message_type = 'danger';
                    }
                    $ins->close();
                }
            }
        }
    }
}

// ═══════════════════════════════════════════════
// UPDATE USER
// ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {

    $user_id    = intval($_POST['user_id']   ?? 0);
    $email      = trim($_POST['email']       ?? '');
    $first_name = trim($_POST['first_name']  ?? '');
    $last_name  = trim($_POST['last_name']   ?? '');
    $surname    = trim($_POST['surname']     ?? '');
    $role_id    = intval($_POST['role_id']   ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($user_id <= 0) {
        $message = 'Invalid user ID.';
        $message_type = 'danger';
    } elseif ($role_id <= 0) {
        $message = 'Please select a role.';
        $message_type = 'danger';
    } else {
        // 7 params: s s s s i i i
        $upd = $conn->prepare(
            "UPDATE users SET email=?, first_name=?, last_name=?, surname=?, role_id=?, is_active=? WHERE id=?"
        );
        if (!$upd) {
            $message = 'DB error: ' . $conn->error;
            $message_type = 'danger';
        } else {
            $upd->bind_param("ssssiii", $email, $first_name, $last_name, $surname, $role_id, $is_active, $user_id);
            if ($upd->execute()) {
                $message = 'User updated successfully.';
                $message_type = 'success';
            } else {
                $message = 'DB error: ' . $upd->error;
                $message_type = 'danger';
            }
            $upd->close();
        }
    }
}

// ═══════════════════════════════════════════════
// RESET PASSWORD
// ═══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {

    $user_id          = intval($_POST['user_id']      ?? 0);
    $new_password     = $_POST['new_password']         ?? '';
    $confirm_password = $_POST['confirm_password']     ?? '';

    if ($new_password === '') {
        $message = 'New password is required.';
        $message_type = 'danger';
    } elseif ($new_password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $message_type = 'danger';
    } elseif (strlen($new_password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $message_type = 'danger';
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $rst = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        if (!$rst) {
            $message = 'DB error: ' . $conn->error;
            $message_type = 'danger';
        } else {
            $rst->bind_param("si", $hashed, $user_id);
            if ($rst->execute()) {
                $message = 'Password reset successfully.';
                $message_type = 'success';
            } else {
                $message = 'DB error: ' . $rst->error;
                $message_type = 'danger';
            }
            $rst->close();
        }
    }
}

// ═══════════════════════════════════════════════
// TOGGLE ACTIVE
// ═══════════════════════════════════════════════
if (isset($_GET['toggle_active'])) {
    $user_id = intval($_GET['toggle_active']);
    $sel = $conn->prepare("SELECT is_active FROM users WHERE id=? LIMIT 1");
    if ($sel) {
        $sel->bind_param("i", $user_id);
        $sel->execute();
        $sel->bind_result($cur);
        $sel->fetch();
        $sel->close();
        $new_status = $cur ? 0 : 1;
        $tog = $conn->prepare("UPDATE users SET is_active=? WHERE id=?");
        if ($tog) {
            $tog->bind_param("ii", $new_status, $user_id);
            if ($tog->execute()) {
                $message = 'User status updated.';
                $message_type = 'success';
            } else {
                $message = 'Error: ' . $tog->error;
                $message_type = 'danger';
            }
            $tog->close();
        }
    }
}

// ═══════════════════════════════════════════════
// DELETE USER
// ═══════════════════════════════════════════════
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    if ($user_id == $_SESSION['user_id']) {
        $message = 'You cannot delete your own account.';
        $message_type = 'danger';
    } else {
        $del = $conn->prepare("DELETE FROM users WHERE id=?");
        if ($del) {
            $del->bind_param("i", $user_id);
            if ($del->execute()) {
                $message = 'User deleted successfully.';
                $message_type = 'success';
            } else {
                $message = 'Error: ' . $del->error;
                $message_type = 'danger';
            }
            $del->close();
        }
    }
}

// ═══════════════════════════════════════════════
// LOAD EDIT USER DATA via PHP (no AJAX)
// ?edit=ID → query user → pre-fill modal server-side
// ═══════════════════════════════════════════════
$edit_user  = null;
$open_modal = '';

if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $esel = $conn->prepare(
        "SELECT id, username, email, first_name, last_name, surname, role_id, is_active,
                DATE_FORMAT(created_at, '%b %d, %Y') AS created_fmt
         FROM users WHERE id=? LIMIT 1"
    );
    if ($esel) {
        $esel->bind_param("i", $edit_id);
        $esel->execute();
        $edit_user = $esel->get_result()->fetch_assoc();
        $esel->close();
    }
    if ($edit_user) $open_modal = 'edit';
}

// Re-open create modal on failed create (keeps user's input)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user']) && $message_type === 'danger') {
    $open_modal = 'create';
}

// Re-open edit modal on failed update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user']) && $message_type === 'danger') {
    $edit_id2 = intval($_POST['user_id'] ?? 0);
    if ($edit_id2 > 0) {
        $esel2 = $conn->prepare(
            "SELECT id, username, email, first_name, last_name, surname, role_id, is_active,
                    DATE_FORMAT(created_at, '%b %d, %Y') AS created_fmt
             FROM users WHERE id=? LIMIT 1"
        );
        if ($esel2) {
            $esel2->bind_param("i", $edit_id2);
            $esel2->execute();
            $edit_user = $esel2->get_result()->fetch_assoc();
            $esel2->close();
        }
    }
    $open_modal = 'edit';
}

// ═══════════════════════════════════════════════
// FETCH ALL USERS + ROLES for page render
// ═══════════════════════════════════════════════
$users_result = $conn->query(
    "SELECT u.*, r.name AS role_name
     FROM users u
     LEFT JOIN roles r ON u.role_id = r.id
     ORDER BY u.created_at DESC"
);

$roles_result = $conn->query("SELECT id, name FROM roles ORDER BY name");
$roles_array  = [];
if ($roles_result) {
    while ($r = $roles_result->fetch_assoc()) {
        $roles_array[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - MUWASCO Monthly Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="style.css" rel="stylesheet">
    <style>
        .user-management-page .page-content    { padding-top: 0 !important; }
        .user-management-page .content-wrapper { padding-top: 20px !important; }
        .user-management-page .page-header     { margin-top: 0 !important; }
    </style>
</head>
<body>
    <div class="modal-overlay" id="modalBackdrop" style="display:none;"></div>

    <div class="main-container user-management-page">
        <?php include 'nav_bar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title"><h1>User Management</h1></div>
                </div>
                <div class="header-right">
                    <span class="header-badge badge-success">
                        <i class="bi bi-person-check"></i> Admin
                    </span>
                </div>
            </header>

            <div class="page-content">
                <div class="content-wrapper">

                    <div class="page-header">
                        <h2><i class="bi bi-people-fill"></i> User Management</h2>
                        <p>Manage system users, roles, and permissions.</p>
                    </div>

                    <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= $message_type ?>" id="pageAlert">
                        <div class="alert-icon">
                            <i class="bi <?= $message_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                        </div>
                        <div class="alert-content">
                            <strong><?= $message_type === 'success' ? 'Success!' : 'Error!' ?></strong>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="user-management-card">
                        <div class="card-header">
                            <h2><i class="bi bi-people"></i> System Users</h2>
                            <button class="btn btn-primary" onclick="openCreateModal()">
                                <i class="bi bi-person-plus"></i> Add New User
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if ($users_result && $users_result->num_rows > 0): ?>
                            <div class="table-container">
                                <table class="user-management-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php while ($user = $users_result->fetch_assoc()): ?>
                                        <?php $isSelf = ($user['id'] == $_SESSION['user_id']); ?>
                                        <tr>
                                            <td><?= (int)$user['id'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($user['username']) ?></strong>
                                                <?php if ($isSelf): ?>
                                                    <span class="badge badge-info" style="margin-left:5px;">You</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?>
                                                <?php if (!empty($user['surname'])): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($user['surname']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= !empty($user['email'])
                                                    ? htmlspecialchars($user['email'])
                                                    : '<span class="text-muted">No email</span>' ?>
                                            </td>
                                            <td>
                                                <?php
                                                $rc = 'badge-admin';
                                                if (!empty($user['role_name'])) {
                                                    $rl = strtolower($user['role_name']);
                                                    if      (strpos($rl,'revenue')   !== false) $rc = 'badge-revenue';
                                                    elseif  (strpos($rl,'customer')  !== false) $rc = 'badge-customer-care';
                                                    elseif  (strpos($rl,'treatment') !== false) $rc = 'badge-treatment';
                                                    elseif  (strpos($rl,'accounts')  !== false) $rc = 'badge-accounts';
                                                    elseif  (strpos($rl,'gis')       !== false) $rc = 'badge-gis';
                                                    elseif  (strpos($rl,'nrw')       !== false) $rc = 'badge-nrw';
                                                }
                                                ?>
                                                <span class="badge-role <?= $rc ?>">
                                                    <?= htmlspecialchars($user['role_name'] ?? 'No Role') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="status-active"><i class="bi bi-check-circle"></i> Active</span>
                                                <?php else: ?>
                                                    <span class="status-inactive"><i class="bi bi-x-circle"></i> Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <div class="user-action-buttons">
                                                    <!-- Edit links to ?edit=ID — PHP loads user data and opens modal -->
                                                    <a href="?edit=<?= (int)$user['id'] ?>"
                                                       class="btn-user-action btn-info">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <button class="btn-user-action btn-warning"
                                                            onclick="openResetModal(<?= (int)$user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')"
                                                            <?= $isSelf ? 'disabled' : '' ?>>
                                                        <i class="bi bi-key"></i> Reset
                                                    </button>
                                                    <a href="?toggle_active=<?= (int)$user['id'] ?>"
                                                       class="btn-user-action btn-primary"
                                                       <?= $isSelf
                                                            ? 'onclick="return false;" style="opacity:.5;cursor:not-allowed;"'
                                                            : 'onclick="return confirm(\'Toggle active status for this user?\')"' ?>>
                                                        <i class="bi bi-power"></i>
                                                        <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                    </a>
                                                    <a href="?delete=<?= (int)$user['id'] ?>"
                                                       class="btn-user-action btn-danger"
                                                       <?= $isSelf
                                                            ? 'onclick="return false;" style="opacity:.5;cursor:not-allowed;"'
                                                            : 'onclick="return confirm(\'Delete this user? This cannot be undone.\')"' ?>>
                                                        <i class="bi bi-trash"></i> Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="bi bi-people"></i></div>
                                <h3>No Users Found</h3>
                                <p>No users have been created yet.</p>
                                <button class="btn btn-primary" onclick="openCreateModal()">
                                    <i class="bi bi-person-plus"></i> Create First User
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <!-- ══════════════════════════════════════
         CREATE USER MODAL
    ══════════════════════════════════════ -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-person-plus"></i> Create New User</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" action="user_management.php" class="user-form" id="createUserForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" name="username" required placeholder="Enter username"
                               value="<?= ($open_modal === 'create') ? htmlspecialchars($_POST['username'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password *</label>
                        <input type="password" class="form-control" id="c_password" name="password"
                               required placeholder="Min 6 characters" minlength="6">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password *</label>
                        <input type="password" class="form-control" id="c_confirm" name="confirm_password"
                               required placeholder="Repeat password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" placeholder="user@example.com"
                               value="<?= ($open_modal === 'create') ? htmlspecialchars($_POST['email'] ?? '') : '' ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" placeholder="First name"
                                       value="<?= ($open_modal === 'create') ? htmlspecialchars($_POST['first_name'] ?? '') : '' ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" placeholder="Last name"
                                       value="<?= ($open_modal === 'create') ? htmlspecialchars($_POST['last_name'] ?? '') : '' ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Surname</label>
                        <input type="text" class="form-control" name="surname" placeholder="Surname (optional)"
                               value="<?= ($open_modal === 'create') ? htmlspecialchars($_POST['surname'] ?? '') : '' ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select class="form-control" name="role_id" required>
                            <option value="">-- Select a role --</option>
                            <?php foreach ($roles_array as $role):
                                $sel = ($open_modal === 'create' && (int)($_POST['role_id'] ?? 0) === (int)$role['id']) ? 'selected' : '';
                            ?>
                            <option value="<?= (int)$role['id'] ?>" <?= $sel ?>><?= htmlspecialchars($role['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="c_is_active" name="is_active" value="1"
                               <?= ($open_modal !== 'create' || isset($_POST['is_active'])) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="c_is_active">Active User</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" name="create_user" value="1" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         EDIT USER MODAL
         PHP pre-fills all fields — zero JavaScript needed
    ══════════════════════════════════════ -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-pencil"></i> Edit User</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="user_management.php" class="user-form" id="editUserForm">
                <input type="hidden" name="user_id" value="<?= $edit_user ? (int)$edit_user['id'] : '' ?>">
                <div class="modal-body">

                    <?php if ($edit_user): ?>
                    <div class="user-details-grid" style="margin-bottom:16px;">
                        <div class="user-detail-item">
                            <div class="detail-label">Username</div>
                            <div class="detail-value"><strong><?= htmlspecialchars($edit_user['username']) ?></strong></div>
                        </div>
                        <div class="user-detail-item">
                            <div class="detail-label">Member Since</div>
                            <div class="detail-value"><?= htmlspecialchars($edit_user['created_fmt']) ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted" style="padding:12px 0;">
                        <i class="bi bi-info-circle"></i>
                        Click the <strong>Edit</strong> button next to a user to load their details here.
                    </p>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" placeholder="user@example.com"
                               value="<?= htmlspecialchars($edit_user['email'] ?? '') ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" placeholder="First name"
                                       value="<?= htmlspecialchars($edit_user['first_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" placeholder="Last name"
                                       value="<?= htmlspecialchars($edit_user['last_name'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Surname</label>
                        <input type="text" class="form-control" name="surname" placeholder="Surname (optional)"
                               value="<?= htmlspecialchars($edit_user['surname'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select class="form-control" name="role_id" <?= $edit_user ? 'required' : '' ?>>
                            <option value="">-- Select a role --</option>
                            <?php foreach ($roles_array as $role):
                                $sel = ($edit_user && (int)$edit_user['role_id'] === (int)$role['id']) ? 'selected' : '';
                            ?>
                            <option value="<?= (int)$role['id'] ?>" <?= $sel ?>><?= htmlspecialchars($role['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="e_is_active" name="is_active" value="1"
                               <?= ($edit_user && $edit_user['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="e_is_active">Active User</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" name="update_user" value="1" class="btn btn-primary"
                            <?= $edit_user ? '' : 'disabled' ?>>
                        <i class="bi bi-save"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         RESET PASSWORD MODAL
    ══════════════════════════════════════ -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-key"></i> Reset Password</h3>
                <button class="modal-close" onclick="closeResetModal()">&times;</button>
            </div>
            <form method="POST" action="user_management.php" class="user-form" id="resetPasswordForm">
                <input type="hidden" id="reset_user_id" name="user_id">
                <div class="modal-body">
                    <p>Resetting password for: <strong id="reset_username"></strong></p>
                    <div class="form-group">
                        <label class="form-label">New Password *</label>
                        <input type="password" class="form-control" id="r_password" name="new_password"
                               required placeholder="Min 6 characters" minlength="6">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password *</label>
                        <input type="password" class="form-control" id="r_confirm" name="confirm_password"
                               required placeholder="Repeat new password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" name="reset_password" value="1" class="btn btn-primary">
                        <i class="bi bi-key"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // ── Backdrop helpers ──────────────────────────────────────
    function backdropOn()  { document.getElementById('modalBackdrop').style.display='block'; document.body.style.overflow='hidden'; }
    function backdropOff() { document.getElementById('modalBackdrop').style.display='none';  document.body.style.overflow=''; }

    // ── Create modal ──────────────────────────────────────────
    function openCreateModal() {
        document.getElementById('createModal').classList.add('active');
        backdropOn();
    }
    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('active');
        backdropOff();
    }

    // ── Edit modal (opened by PHP via $open_modal, not JS) ────
    function openEditModal() {
        document.getElementById('editModal').classList.add('active');
        backdropOn();
    }
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('active');
        backdropOff();
        // Clean ?edit= from URL without reloading
        history.replaceState(null, '', window.location.pathname);
    }

    // ── Reset modal ───────────────────────────────────────────
    function openResetModal(userId, username) {
        document.getElementById('reset_user_id').value        = userId;
        document.getElementById('reset_username').textContent  = username;
        document.getElementById('resetModal').classList.add('active');
        backdropOn();
    }
    function closeResetModal() {
        document.getElementById('resetModal').classList.remove('active');
        document.getElementById('resetPasswordForm').reset();
        backdropOff();
    }

    // ── Auto-open correct modal on page load ──────────────────
    document.addEventListener('DOMContentLoaded', function() {
        var which = <?= json_encode($open_modal) ?>;
        if (which === 'edit')   openEditModal();
        if (which === 'create') openCreateModal();
    });

    // ── Close on backdrop / Escape ────────────────────────────
    document.getElementById('modalBackdrop').addEventListener('click', function() {
        closeCreateModal(); closeEditModal(); closeResetModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { closeCreateModal(); closeEditModal(); closeResetModal(); }
    });
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) { overlay.classList.remove('active'); backdropOff(); }
        });
    });

    // ── Form validation ───────────────────────────────────────
    document.getElementById('createUserForm').addEventListener('submit', function(e) {
        var pw  = document.getElementById('c_password').value;
        var cpw = document.getElementById('c_confirm').value;
        if (pw !== cpw)    { e.preventDefault(); alert('Passwords do not match!'); return; }
        if (pw.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters!'); return; }
    });

    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        var pw  = document.getElementById('r_password').value;
        var cpw = document.getElementById('r_confirm').value;
        if (pw !== cpw)    { e.preventDefault(); alert('Passwords do not match!'); return; }
        if (pw.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters!'); return; }
    });

    // ── Sidebar ───────────────────────────────────────────────
    var st = document.getElementById('sidebarToggle');
    if (st) st.addEventListener('click', function() {
        var sb = document.querySelector('.sidebar');
        if (sb) sb.classList.toggle('collapsed');
    });

    // ── Auto-dismiss alert ────────────────────────────────────
    var alertEl = document.getElementById('pageAlert');
    if (alertEl) {
        setTimeout(function() {
            alertEl.style.transition = 'opacity .4s, transform .4s';
            alertEl.style.opacity    = '0';
            alertEl.style.transform  = 'translateY(-16px)';
            setTimeout(function() { alertEl.remove(); }, 400);
        }, 5000);
    }
    </script>
</body>
</html>