<?php
// user_management.php
session_start();
require_once 'auth_functions.php';
require_once 'db.php';

// Check if user is admin using updated function
requireLogin();
if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit();
}

// Handle user actions
$message = '';
$message_type = '';

// ── All AJAX POST actions — detected via hidden _ajax=1 field ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json');

    // ── Create user ──────────────────────────────────────────────────────────
    if (isset($_POST['create_user'])) {
        $username         = trim($_POST['username']);
        $password         = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $email            = trim($_POST['email']);
        $first_name       = trim($_POST['first_name']);
        $last_name        = trim($_POST['last_name']);
        $surname          = trim($_POST['surname']);
        $role_id          = intval($_POST['role_id']);
        $is_active        = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        } elseif ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        } elseif (strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Username already exists']);
            } else {
                $stmt->close();
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    INSERT INTO users (username, password, email, first_name, last_name, surname, role_id, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param("ssssssii", $username, $hashed_password, $email, $first_name, $last_name, $surname, $role_id, $is_active);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'User created successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error creating user: ' . $conn->error]);
                }
            }
            $stmt->close();
        }
        exit();
    }

    // ── Update user ──────────────────────────────────────────────────────────
    if (isset($_POST['update_user'])) {
        $user_id    = intval($_POST['user_id']);
        $email      = trim($_POST['email']);
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $surname    = trim($_POST['surname']);
        $role_id    = intval($_POST['role_id']);
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $conn->prepare("
            UPDATE users
            SET email = ?, first_name = ?, last_name = ?, surname = ?, role_id = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssiii", $email, $first_name, $last_name, $surname, $role_id, $is_active, $user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true,  'message' => 'User updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating user: ' . $conn->error]);
        }
        $stmt->close();
        exit();
    }

    // ── Reset password ───────────────────────────────────────────────────────
    if (isset($_POST['reset_password'])) {
        $user_id          = intval($_POST['user_id']);
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($new_password)) {
            echo json_encode(['success' => false, 'message' => 'New password is required']);
        } elseif ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        } elseif (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true,  'message' => 'Password reset successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error resetting password: ' . $conn->error]);
            }
            $stmt->close();
        }
        exit();
    }

    // Unknown AJAX action
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

// Toggle user active status
if (isset($_GET['toggle_active'])) {
    $user_id = intval($_GET['toggle_active']);
    
    $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($current_status);
    $stmt->fetch();
    $stmt->close();
    
    $new_status = $current_status ? 0 : 1;
    
    $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $user_id);
    
    if ($stmt->execute()) {
        $message = 'User status updated successfully';
        $message_type = 'success';
    } else {
        $message = 'Error updating user status';
        $message_type = 'danger';
    }
    $stmt->close();
}

// Delete user
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        $message = 'Cannot delete your own account';
        $message_type = 'danger';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $message = 'User deleted successfully';
            $message_type = 'success';
        } else {
            $message = 'Error deleting user: ' . $conn->error;
            $message_type = 'danger';
        }
        $stmt->close();
    }
}

// Get all users with role information
$users_query = "
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.created_at DESC
";
$users_result = $conn->query($users_query);

// Get all roles for dropdown - store them in an array for reuse
$roles_query = "SELECT id, name FROM roles ORDER BY name";
$roles_result = $conn->query($roles_query);
$roles_array = [];
while ($role = $roles_result->fetch_assoc()) {
    $roles_array[] = $role;
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
        /* Page-specific overrides to remove top spacing */
        .user-management-page .page-content {
            padding-top: 0 !important;
        }
        
        .user-management-page .content-wrapper {
            padding-top: 20px !important;
        }
        
        /* Ensure page header has no top margin */
        .user-management-page .page-header {
            margin-top: 0 !important;
        }
    </style>
</head>
<body>
    <!-- Modal Backdrop -->
    <div class="modal-overlay" id="modalBackdrop" style="display: none;"></div>
    
    <!-- Main Container with user-management-page class -->
    <div class="main-container user-management-page">
        <!-- Include Navbar -->
        <?php include 'nav_bar.php'; ?>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <div class="page-title">
                        <h1>User Management</h1>
                    </div>
                </div>
                <div class="header-right">
                    <span class="header-badge badge-success">
                        <i class="bi bi-person-check"></i> Admin
                    </span>
                </div>
            </header>

            <!-- Page Content -->
            <div class="page-content">
                <div class="content-wrapper">
                    
                    <!-- Page Header -->
                    <div class="page-header">
                        <h2><i class="bi bi-people-fill"></i> User Management</h2>
                        <p>Manage system users, roles, and permissions. You can create new users, edit details, reset passwords, and manage user status.</p>
                    </div>

                    <!-- Message Alert -->
                    <?php if ($message): ?>
                        <div class="alert alert-<?= $message_type ?>">
                            <div class="alert-icon">
                                <i class="bi <?= $message_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                            </div>
                            <div class="alert-content">
                                <strong><?= $message_type === 'success' ? 'Success!' : 'Error!' ?></strong>
                                <?= htmlspecialchars($message) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Users Card -->
                    <div class="user-management-card">
                        <div class="card-header">
                            <h2><i class="bi bi-people"></i> System Users</h2>
                            <button class="btn btn-primary" onclick="openCreateModal()">
                                <i class="bi bi-person-plus"></i> Add New User
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if ($users_result->num_rows > 0): ?>
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
                                                <tr>
                                                    <td><?= $user['id'] ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($user['username']) ?></strong>
                                                        <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                                            <span class="badge badge-info" style="margin-left: 5px;">You</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])) ?>
                                                        <?php if (!empty($user['surname'])): ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($user['surname']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($user['email'])): ?>
                                                            <?= htmlspecialchars($user['email']) ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">No email</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $role_class = 'badge-admin';
                                                        if (isset($user['role_name'])) {
                                                            $role_lower = strtolower($user['role_name']);
                                                            if (strpos($role_lower, 'revenue') !== false) $role_class = 'badge-revenue';
                                                            elseif (strpos($role_lower, 'customer') !== false) $role_class = 'badge-customer-care';
                                                            elseif (strpos($role_lower, 'treatment') !== false) $role_class = 'badge-treatment';
                                                            elseif (strpos($role_lower, 'accounts') !== false) $role_class = 'badge-accounts';
                                                            elseif (strpos($role_lower, 'gis') !== false) $role_class = 'badge-gis';
                                                            elseif (strpos($role_lower, 'nrw') !== false) $role_class = 'badge-nrw';
                                                        }
                                                        ?>
                                                        <span class="badge-role <?= $role_class ?>">
                                                            <?= isset($user['role_name']) ? htmlspecialchars($user['role_name']) : 'No Role' ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($user['is_active']): ?>
                                                            <span class="status-active">
                                                                <i class="bi bi-check-circle"></i> Active
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="status-inactive">
                                                                <i class="bi bi-x-circle"></i> Inactive
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                                    <td>
                                                        <div class="user-action-buttons">
                                                            <!-- 
                                                                FIX: All user data is passed directly as JS arguments.
                                                                This avoids a page reload (?edit=ID) to fetch user data.
                                                            -->
                                                            <button class="btn-user-action btn-info" 
                                                                    onclick="openEditModal(
                                                                        <?= $user['id'] ?>,
                                                                        '<?= htmlspecialchars(addslashes($user['email'] ?? ''), ENT_QUOTES) ?>',
                                                                        '<?= htmlspecialchars(addslashes($user['first_name'] ?? ''), ENT_QUOTES) ?>',
                                                                        '<?= htmlspecialchars(addslashes($user['last_name'] ?? ''), ENT_QUOTES) ?>',
                                                                        '<?= htmlspecialchars(addslashes($user['surname'] ?? ''), ENT_QUOTES) ?>',
                                                                        <?= intval($user['role_id']) ?>,
                                                                        <?= $user['is_active'] ? 'true' : 'false' ?>,
                                                                        '<?= htmlspecialchars(addslashes($user['username']), ENT_QUOTES) ?>',
                                                                        '<?= date('M d, Y', strtotime($user['created_at'])) ?>'
                                                                    )">
                                                                <i class="bi bi-pencil"></i> Edit
                                                            </button>
                                                            <button class="btn-user-action btn-warning" 
                                                                    onclick="openResetModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')"
                                                                    <?= $user['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                                                                <i class="bi bi-key"></i> Reset
                                                            </button>
                                                            <a href="?toggle_active=<?= $user['id'] ?>" 
                                                               class="btn-user-action btn-primary"
                                                               onclick="return confirm('Are you sure you want to <?= $user['is_active'] ? 'deactivate' : 'activate' ?> this user?')"
                                                               <?= $user['id'] == $_SESSION['user_id'] ? 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                                                                <i class="bi bi-power"></i> <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                            </a>
                                                            <a href="?delete=<?= $user['id'] ?>" 
                                                               class="btn-user-action btn-danger"
                                                               onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')"
                                                               <?= $user['id'] == $_SESSION['user_id'] ? 'onclick="return false;" style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
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
                                    <div class="empty-state-icon">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <h3>No Users Found</h3>
                                    <p>No users have been created yet. Click the "Add New User" button to create the first user.</p>
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

    <!-- Create User Modal -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-person-plus"></i> Create New User</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" action="" class="user-form" id="createUserForm">
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="create_user" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" required 
                               placeholder="Enter username">
                        <small class="form-text">Username must be unique and contain only letters, numbers, and underscores.</small>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="password" name="password" required 
                               placeholder="Enter password" minlength="6">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                               placeholder="Confirm password">
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="user@example.com">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       placeholder="First name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       placeholder="Last name">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="surname" class="form-label">Surname</label>
                        <input type="text" class="form-control" id="surname" name="surname" 
                               placeholder="Surname (optional)">
                    </div>

                    <div class="form-group">
                        <label for="role_id" class="form-label">Role *</label>
                        <select class="form-select form-control" id="role_id" name="role_id" required>
                            <option value="">Select a role</option>
                            <?php foreach ($roles_array as $role): ?>
                                <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">
                            Active User
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-pencil"></i> Edit User</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="" class="user-form" id="editUserForm">
                <input type="hidden" id="edit_user_id" name="user_id">
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="update_user" value="1">
                <div class="modal-body">
                    <!-- User Details Display -->
                    <div id="userDetailsDisplay" class="user-details-grid" style="display: none;">
                        <div class="user-detail-item">
                            <div class="detail-label">Username</div>
                            <div class="detail-value" id="detail_username"></div>
                        </div>
                        <div class="user-detail-item">
                            <div class="detail-label">Created</div>
                            <div class="detail-value" id="detail_created"></div>
                        </div>
                        <div class="user-detail-item">
                            <div class="detail-label">Last Login</div>
                            <div class="detail-value" id="detail_last_login">Not available</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="edit_email" name="email" 
                               placeholder="user@example.com">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="edit_first_name" name="first_name" 
                                       placeholder="First name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="edit_last_name" name="last_name" 
                                       placeholder="Last name">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_surname" class="form-label">Surname</label>
                        <input type="text" class="form-control" id="edit_surname" name="surname" 
                               placeholder="Surname (optional)">
                    </div>

                    <div class="form-group">
                        <label for="edit_role_id" class="form-label">Role *</label>
                        <select class="form-select form-control" id="edit_role_id" name="role_id" required>
                            <option value="">Select a role</option>
                            <?php foreach ($roles_array as $role): ?>
                                <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">
                            Active User
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Update User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="bi bi-key"></i> Reset Password</h3>
                <button class="modal-close" onclick="closeResetModal()">&times;</button>
            </div>
            <form method="POST" action="" class="user-form" id="resetPasswordForm">
                <input type="hidden" id="reset_user_id" name="user_id">
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="reset_password" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <p>Reset password for user: <strong id="reset_username"></strong></p>
                    </div>
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password *</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required 
                               placeholder="Enter new password" minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="confirm_new_password" class="form-label">Confirm New Password *</label>
                        <input type="password" class="form-control" id="confirm_new_password" name="confirm_password" required 
                               placeholder="Confirm new password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-key"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ─────────────────────────────────────────────
        // CREATE MODAL
        // ─────────────────────────────────────────────
        function openCreateModal() {
            document.getElementById('createModal').classList.add('active');
            document.getElementById('modalBackdrop').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('active');
            document.getElementById('modalBackdrop').style.display = 'none';
            document.getElementById('createUserForm').reset();
            document.body.style.overflow = 'auto';
        }

        // ─────────────────────────────────────────────
        // EDIT MODAL — FIX: data passed directly, NO page reload
        // ─────────────────────────────────────────────
        function openEditModal(userId, email, firstName, lastName, surname, roleId, isActive, username, created) {
            // Populate hidden + form fields directly from passed arguments
            document.getElementById('edit_user_id').value    = userId;
            document.getElementById('edit_email').value      = email;
            document.getElementById('edit_first_name').value = firstName;
            document.getElementById('edit_last_name').value  = lastName;
            document.getElementById('edit_surname').value    = surname;
            document.getElementById('edit_role_id').value    = roleId;
            document.getElementById('edit_is_active').checked = isActive;

            // Populate info display section
            document.getElementById('detail_username').textContent = username;
            document.getElementById('detail_created').textContent  = created;
            document.getElementById('userDetailsDisplay').style.display = 'grid';

            // Show modal
            document.getElementById('editModal').classList.add('active');
            document.getElementById('modalBackdrop').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            document.getElementById('modalBackdrop').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // ─────────────────────────────────────────────
        // RESET PASSWORD MODAL
        // ─────────────────────────────────────────────
        function openResetModal(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').textContent = username;
            document.getElementById('resetModal').classList.add('active');
            document.getElementById('modalBackdrop').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('active');
            document.getElementById('modalBackdrop').style.display = 'none';
            document.getElementById('resetPasswordForm').reset();
            document.body.style.overflow = 'auto';
        }

        // ─────────────────────────────────────────────
        // CLOSE ALL MODALS on backdrop or Escape key
        // ─────────────────────────────────────────────
        document.getElementById('modalBackdrop')?.addEventListener('click', function() {
            closeCreateModal();
            closeEditModal();
            closeResetModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCreateModal();
                closeEditModal();
                closeResetModal();
            }
        });

        // Close modals when clicking the overlay itself (not content)
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.getElementById('modalBackdrop').style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // ─────────────────────────────────────────────
        // SHARED AJAX SUBMIT HELPER
        // ─────────────────────────────────────────────
        async function ajaxSubmit(formEl, modalId, onSuccess) {
            const submitBtn    = formEl.querySelector('button[type="submit"]');
            const originalHTML = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Please wait...';
            submitBtn.disabled  = true;

            // Clear previous inline errors
            document.getElementById(modalId).querySelectorAll('.modal-inline-error').forEach(el => el.remove());

            let data;
            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: new FormData(formEl)
                });
                const text = await response.text();
                try {
                    data = JSON.parse(text);
                } catch (_) {
                    // PHP returned HTML (error page) — show first meaningful line
                    const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                    showModalError(modalId, 'Server error: ' + (plain || 'check PHP error log'));
                    submitBtn.innerHTML = originalHTML;
                    submitBtn.disabled  = false;
                    return;
                }
            } catch (networkErr) {
                showModalError(modalId, 'Network error — please check your connection.');
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled  = false;
                return;
            }

            submitBtn.innerHTML = originalHTML;
            submitBtn.disabled  = false;

            if (data.success) {
                onSuccess(data.message);
            } else {
                showModalError(modalId, data.message);
            }
        }

        // ─────────────────────────────────────────────
        // CREATE FORM — AJAX submit
        // ─────────────────────────────────────────────
        document.getElementById('createUserForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const password        = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            if (password !== confirmPassword) { showModalError('createModal', 'Passwords do not match!'); return; }
            if (password.length < 6)          { showModalError('createModal', 'Password must be at least 6 characters!'); return; }

            await ajaxSubmit(this, 'createModal', (msg) => {
                closeCreateModal();
                showToast(msg, 'success');
                setTimeout(() => location.reload(), 1500);
            });
        });

        // ─────────────────────────────────────────────
        // EDIT FORM — AJAX submit
        // ─────────────────────────────────────────────
        document.getElementById('editUserForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            await ajaxSubmit(this, 'editModal', (msg) => {
                closeEditModal();
                showToast(msg, 'success');
                setTimeout(() => location.reload(), 1500);
            });
        });

        // ─────────────────────────────────────────────
        // RESET PASSWORD FORM — AJAX submit
        // ─────────────────────────────────────────────
        document.getElementById('resetPasswordForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const newPassword     = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_new_password').value;
            if (newPassword !== confirmPassword) { showModalError('resetModal', 'Passwords do not match!'); return; }
            if (newPassword.length < 6)          { showModalError('resetModal', 'Password must be at least 6 characters!'); return; }

            await ajaxSubmit(this, 'resetModal', (msg) => {
                closeResetModal();
                showToast(msg, 'success');
            });
        });
        // ─────────────────────────────────────────────
        function showToast(message, type) {
            // Remove any existing toast
            document.querySelectorAll('.ajax-toast').forEach(t => t.remove());

            const toast = document.createElement('div');
            toast.className = 'ajax-toast alert alert-' + type;
            toast.style.cssText = [
                'position:fixed', 'top:20px', 'right:20px', 'z-index:99999',
                'min-width:280px', 'max-width:400px', 'box-shadow:0 4px 20px rgba(0,0,0,.2)',
                'display:flex', 'align-items:center', 'gap:10px',
                'transition:opacity .3s, transform .3s',
                'opacity:0', 'transform:translateY(-10px)'
            ].join(';');
            toast.innerHTML = `
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}"></i>
                <span>${message}</span>`;
            document.body.appendChild(toast);

            // Animate in
            requestAnimationFrame(() => {
                toast.style.opacity   = '1';
                toast.style.transform = 'translateY(0)';
            });

            // Animate out after 4 s
            setTimeout(() => {
                toast.style.opacity   = '0';
                toast.style.transform = 'translateY(-10px)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // ─────────────────────────────────────────────
        // INLINE MODAL MESSAGE (error only)
        // ─────────────────────────────────────────────
        function showModalError(modalId, message) {
            const modal = document.getElementById(modalId);
            modal.querySelectorAll('.modal-inline-error').forEach(e => e.remove());
            const el = document.createElement('div');
            el.className = 'modal-inline-error alert alert-danger';
            el.style.cssText = 'margin:0 0 12px 0; display:flex; align-items:center; gap:8px;';
            el.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i><span>${message}</span>`;
            modal.querySelector('.modal-body').prepend(el);
        }

        // ─────────────────────────────────────────────
        // SIDEBAR TOGGLE
        // ─────────────────────────────────────────────
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('collapsed');
            }
        });

        // ─────────────────────────────────────────────
        // AUTO-DISMISS ALERTS after 5 seconds
        // ─────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity   = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>