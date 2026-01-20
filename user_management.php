<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'auth_functions.php';

// Require admin access
requireAdmin();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_user':
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $role = $_POST['role'];
            
            if (!empty($username) && !empty($password) && !empty($full_name)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $role);
                
                if ($stmt->execute()) {
                    $success = "User created successfully!";
                } else {
                    $error = "Error creating user: " . $conn->error;
                }
            } else {
                $error = "Please fill in all required fields.";
            }
            break;
            
        case 'update_user':
            $user_id = intval($_POST['user_id']);
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $role = $_POST['role'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("sssii", $full_name, $email, $role, $is_active, $user_id);
            
            if ($stmt->execute()) {
                $success = "User updated successfully!";
            } else {
                $error = "Error updating user: " . $conn->error;
            }
            break;
            
        case 'reset_password':
            $user_id = intval($_POST['user_id']);
            $new_password = $_POST['new_password'];
            
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($stmt->execute()) {
                    $success = "Password reset successfully!";
                } else {
                    $error = "Error resetting password: " . $conn->error;
                }
            }
            break;
            
        case 'assign_sections':
            $user_id = intval($_POST['user_id']);
            $sections = $_POST['sections'] ?? [];
            
            // Delete existing assignments
            $stmt = $conn->prepare("DELETE FROM user_section_assignments WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            
            // Insert new assignments
            if (!empty($sections)) {
                $stmt = $conn->prepare("INSERT INTO user_section_assignments (user_id, category_id) VALUES (?, ?)");
                foreach ($sections as $section_id) {
                    $stmt->bind_param("ii", $user_id, $section_id);
                    $stmt->execute();
                }
            }
            
            $success = "Section assignments updated successfully!";
            break;
    }
}

// Get all users
$users_query = "SELECT * FROM users ORDER BY created_at DESC";
$users_result = $conn->query($users_query);

// Get all sections
$sections_query = "SELECT * FROM parameter_categories ORDER BY display_order";
$sections_result = $conn->query($sections_query);
$all_sections = [];
while ($row = $sections_result->fetch_assoc()) {
    $all_sections[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - MUWASCO</title>
    
    <!-- External Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body class="user-management-page">
    <div class="main-container">
        <?php include 'nav_bar.php'; ?>
        
        <div class="main-content">
            <div class="page-content">
                <div class="user-management-wrapper">
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show animate-fade-in-up" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-check-circle-fill me-3"></i>
                                <div>
                                    <h5 class="alert-heading mb-1">Success!</h5>
                                    <p class="mb-0"><?php echo $success; ?></p>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show animate-fade-in-up" role="alert">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill me-3"></i>
                                <div>
                                    <h5 class="alert-heading mb-1">Error!</h5>
                                    <p class="mb-0"><?php echo $error; ?></p>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Page Header -->
                    <div class="page-header animate-fade-in-up">
                        <h2><i class="bi bi-people-fill me-3"></i>User Management</h2>
                        <p class="mb-0">Manage users and section assignments for the water reporting system</p>
                    </div>

                    <!-- Create New User -->
                    <div class="user-management-card animate-fade-in-up" style="animation-delay: 0.1s;">
                        <div class="card-header">
                            <h5><i class="bi bi-person-plus me-2"></i>Create New User</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="user-form" id="createUserForm">
                                <input type="hidden" name="action" value="create_user">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Username *</label>
                                        <input type="text" name="username" class="form-control" required 
                                               placeholder="Enter username" minlength="3" maxlength="50">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Password *</label>
                                        <input type="password" name="password" class="form-control" required 
                                               placeholder="Enter password" minlength="6">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" name="full_name" class="form-control" required 
                                               placeholder="Enter full name">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" 
                                               placeholder="user@example.com">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Role *</label>
                                        <select name="role" class="form-select" required>
                                            <option value="user">User</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-person-add me-2"></i>Create User
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Users List -->
                    <div class="user-management-card animate-fade-in-up" style="animation-delay: 0.2s;">
                        <div class="card-header">
                            <h5><i class="bi bi-people me-2"></i>Existing Users</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="user-management-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Username</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Sections</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($user = $users_result->fetch_assoc()): 
                                            $user_sections = getUserSections($conn, $user['id']);
                                        ?>
                                        <tr>
                                            <td><strong><?php echo $user['id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge-role status-<?php echo $user['role']; ?>">
                                                    <i class="bi bi-<?php echo $user['role'] === 'admin' ? 'shield' : 'person'; ?> me-1"></i>
                                                    <?php echo strtoupper($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-role status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <i class="bi bi-<?php echo $user['is_active'] ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge user-section-badge">
                                                    <i class="bi bi-folder me-1"></i>
                                                    <?php echo count($user_sections); ?> assigned
                                                </span>
                                            </td>
                                            <td>
                                                <div class="user-action-buttons">
                                                    <button type="button" class="btn-user-action btn-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#assignModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-diagram-3 me-1"></i>Assign
                                                    </button>
                                                    <button type="button" class="btn-user-action btn-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-pencil me-1"></i>Edit
                                                    </button>
                                                    <button type="button" class="btn-user-action btn-secondary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#passwordModal<?php echo $user['id']; ?>">
                                                        <i class="bi bi-key me-1"></i>Password
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Assign Sections Modal -->
                                        <div class="modal fade" id="assignModal<?php echo $user['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                <div class="modal-content">
                                                    <form method="POST" class="assign-form">
                                                        <input type="hidden" name="action" value="assign_sections">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-diagram-3 me-2"></i>
                                                                Assign Sections - <?php echo htmlspecialchars($user['full_name']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p class="text-muted mb-4">Select the sections this user can access:</p>
                                                            <div class="checkbox-grid">
                                                                <?php 
                                                                $assigned_section_ids = array_column($user_sections, 'id');
                                                                foreach ($all_sections as $section): 
                                                                ?>
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" 
                                                                           name="sections[]" 
                                                                           value="<?php echo $section['id']; ?>"
                                                                           id="section<?php echo $user['id']; ?>_<?php echo $section['id']; ?>"
                                                                           <?php echo in_array($section['id'], $assigned_section_ids) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" 
                                                                           for="section<?php echo $user['id']; ?>_<?php echo $section['id']; ?>">
                                                                        <?php echo htmlspecialchars($section['name']); ?>
                                                                        <small class="text-muted d-block">ID: <?php echo $section['id']; ?></small>
                                                                    </label>
                                                                </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                <i class="bi bi-x-circle me-2"></i>Cancel
                                                            </button>
                                                            <button type="submit" class="btn btn-primary">
                                                                <i class="bi bi-save me-2"></i>Save Assignments
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Edit User Modal -->
                                        <div class="modal fade" id="editModal<?php echo $user['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <form method="POST" class="edit-form">
                                                        <input type="hidden" name="action" value="update_user">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-pencil me-2"></i>
                                                                Edit User - <?php echo htmlspecialchars($user['username']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Full Name *</label>
                                                                <input type="text" name="full_name" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Email</label>
                                                                <input type="email" name="email" class="form-control" 
                                                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Role *</label>
                                                                <select name="role" class="form-select" required>
                                                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                                                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <div class="form-check form-switch">
                                                                    <input class="form-check-input" type="checkbox" 
                                                                           name="is_active" 
                                                                           id="active<?php echo $user['id']; ?>"
                                                                           <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="active<?php echo $user['id']; ?>">
                                                                        Account Active
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                <i class="bi bi-x-circle me-2"></i>Cancel
                                                            </button>
                                                            <button type="submit" class="btn btn-primary">
                                                                <i class="bi bi-save me-2"></i>Update User
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Reset Password Modal -->
                                        <div class="modal fade" id="passwordModal<?php echo $user['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <form method="POST" class="password-form">
                                                        <input type="hidden" name="action" value="reset_password">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">
                                                                <i class="bi bi-key me-2"></i>
                                                                Reset Password - <?php echo htmlspecialchars($user['username']); ?>
                                                            </h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">New Password *</label>
                                                                <input type="password" name="new_password" class="form-control" required 
                                                                       placeholder="Enter new password" minlength="6">
                                                                <div class="form-text">
                                                                    Password must be at least 6 characters long
                                                                </div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Confirm Password *</label>
                                                                <input type="password" name="confirm_password" class="form-control" required 
                                                                       placeholder="Confirm new password">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                <i class="bi bi-x-circle me-2"></i>Cancel
                                                            </button>
                                                            <button type="submit" class="btn btn-warning">
                                                                <i class="bi bi-key me-2"></i>Reset Password
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password validation
            document.querySelectorAll('.password-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const password = this.querySelector('[name="new_password"]').value;
                    const confirmPassword = this.querySelector('[name="confirm_password"]').value;
                    
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        return false;
                    }
                });
            });

            // Add smooth hover effects to table rows
            const tableRows = document.querySelectorAll('.user-management-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', () => {
                    row.style.transform = 'translateX(4px)';
                });
                
                row.addEventListener('mouseleave', () => {
                    row.style.transform = 'translateX(0)';
                });
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>