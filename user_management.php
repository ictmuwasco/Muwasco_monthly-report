<?php
/**
 * user_management.php — Admin user administration (logic).
 * UI rendered via resources/views/users/index.php on shared Tailwind layout.
 * All mutation handlers (AJAX + row actions) are POST-only & CSRF-protected.
 */
require_once __DIR__ . '/backend/bootstrap/app.php';
require_once __DIR__ . '/backend/app/Helpers/AuthFunctions.php';
require_once __DIR__ . '/db.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo 'Access denied. Admin privileges required.';
    exit();
}

// CSRF protection for all POST endpoints

$message = '';
$message_type = '';


// ── All AJAX POST actions — detected via hidden _ajax=1 field ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    CsrfMiddleware::verifyOrDie();
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

// Toggle user active status (POST only — CSRF-protected)
if (isset($_POST['toggle_active'])) {
    $user_id = intval($_POST['toggle_active']);
    CsrfMiddleware::verifyOrDie();
    
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

// Delete user (POST only — CSRF-protected)
if (isset($_POST['delete'])) {
    CsrfMiddleware::verifyOrDie();
    $user_id = intval($_POST['delete']);
    
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
/* Render: Tailwind view inside shared app layout */
ob_start();
require __DIR__ . '/resources/views/users/index.php';
$content = ob_get_clean();

if (isset($conn)) $conn->close();

$pageTitle   = 'User Management · MUWASCO Monthly Report';
$currentPage = 'user_management.php';
require __DIR__ . '/resources/views/layouts/app.php';
