<?php
/**
 * assignment.php — Role ↔ Parameter/Category assignment management (logic).
 * UI rendered via resources/views/assignments/index.php on shared Tailwind layout.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/bootstrap/init.php';
require_once __DIR__ . '/auth_functions.php';
require_once 'db.php';

// Auth + admin gate
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo 'Access denied. Admin privileges required.';
    exit();
}

// One-time CSRF check covering every POST action below.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::verifyOrDie();
}

// Handle form submissions
$success = null;
$error = null;

// Add new role-parameter assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_assignment') {
    $role_id = intval($_POST['role_id']);
    $parameter_id = intval($_POST['parameter_id']);
    
    // Check if assignment already exists
    $check_stmt = $conn->prepare("SELECT id FROM role_parameter_assignments WHERE role_id = ? AND parameter_id = ?");
    $check_stmt->bind_param("ii", $role_id, $parameter_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "This parameter is already assigned to this role!";
    } else {
        // Add new assignment
        $stmt = $conn->prepare("INSERT INTO role_parameter_assignments (role_id, parameter_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $role_id, $parameter_id);
        
        if ($stmt->execute()) {
            $success = "Parameter assigned to role successfully!";
        } else {
            $error = "Error assigning parameter: " . $conn->error;
        }
    }
}

// Remove role-parameter assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove_assignment') {
    $assignment_id = intval($_POST['assignment_id']);
    
    $stmt = $conn->prepare("DELETE FROM role_parameter_assignments WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    
    if ($stmt->execute()) {
        $success = "Assignment removed successfully!";
    } else {
        $error = "Error removing assignment: " . $conn->error;
    }
}

// Add role-category assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_category_assignment') {
    $role_id = intval($_POST['role_id']);
    $category_id = intval($_POST['category_id']);
    
    // Check if assignment already exists
    $check_stmt = $conn->prepare("SELECT id FROM role_category_assignments WHERE role_id = ? AND category_id = ?");
    $check_stmt->bind_param("ii", $role_id, $category_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "This category is already assigned to this role!";
    } else {
        // Add new assignment
        $stmt = $conn->prepare("INSERT INTO role_category_assignments (role_id, category_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $role_id, $category_id);
        
        if ($stmt->execute()) {
            $success = "Category assigned to role successfully!";
        } else {
            $error = "Error assigning category: " . $conn->error;
        }
    }
}

// Remove role-category assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove_category_assignment') {
    $assignment_id = intval($_POST['assignment_id']);
    
    $stmt = $conn->prepare("DELETE FROM role_category_assignments WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    
    if ($stmt->execute()) {
        $success = "Category assignment removed successfully!";
    } else {
        $error = "Error removing category assignment: " . $conn->error;
    }
}

// Get all roles
$roles = [];
$roles_result = $conn->query("SELECT id, name, description FROM roles ORDER BY name");
while ($row = $roles_result->fetch_assoc()) {
    $roles[] = $row;
}

// Get all categories
$categories = [];
$categories_result = $conn->query("SELECT id, name, description FROM parameter_categories ORDER BY display_order");
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get all parameters with categories
$parameters = [];
$params_result = $conn->query("
    SELECT 
        p.id, 
        p.code, 
        p.label, 
        p.unit,
        p.category_id,
        pc.name as category_name
    FROM parameters p
    LEFT JOIN parameter_categories pc ON p.category_id = pc.id
    ORDER BY pc.display_order, p.code
");
while ($row = $params_result->fetch_assoc()) {
    $parameters[] = $row;
}

// Get all current role-parameter assignments with details
$parameter_assignments = [];
$param_assignments_result = $conn->query("
    SELECT 
        rpa.id as assignment_id,
        rpa.role_id,
        rpa.parameter_id,
        r.name as role_name,
        p.code as parameter_code,
        p.label as parameter_label,
        pc.name as category_name,
        rpa.assigned_at
    FROM role_parameter_assignments rpa
    JOIN roles r ON rpa.role_id = r.id
    JOIN parameters p ON rpa.parameter_id = p.id
    LEFT JOIN parameter_categories pc ON p.category_id = pc.id
    ORDER BY r.name, pc.name, p.code
");
while ($row = $param_assignments_result->fetch_assoc()) {
    $parameter_assignments[] = $row;
}

// Get all current role-category assignments with details
$category_assignments = [];
$cat_assignments_result = $conn->query("
    SELECT 
        rca.id as assignment_id,
        rca.role_id,
        rca.category_id,
        r.name as role_name,
        pc.name as category_name,
        rca.assigned_at
    FROM role_category_assignments rca
    JOIN roles r ON rca.role_id = r.id
    JOIN parameter_categories pc ON rca.category_id = pc.id
    ORDER BY r.name, pc.name
");
while ($row = $cat_assignments_result->fetch_assoc()) {
    $category_assignments[] = $row;
}

// Get all users with their roles - FIXED QUERY
$users = [];
$users_result = $conn->query("
    SELECT 
        u.id,
        u.username,
        CONCAT(
            COALESCE(u.first_name, ''), 
            ' ', 
            COALESCE(u.last_name, '')
        ) as full_name,
        u.email,
        u.role_id,
        r.name as role_name,
        u.is_active
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.is_active = 1
    ORDER BY u.username
");
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}

// Group parameter assignments by role for display
$param_assignments_by_role = [];
foreach ($parameter_assignments as $assignment) {
    $role_name = $assignment['role_name'];
    if (!isset($param_assignments_by_role[$role_name])) {
        $param_assignments_by_role[$role_name] = [];
    }
    $param_assignments_by_role[$role_name][] = $assignment;
}

// Group category assignments by role for display
$cat_assignments_by_role = [];
foreach ($category_assignments as $assignment) {
    $role_name = $assignment['role_name'];
    if (!isset($cat_assignments_by_role[$role_name])) {
        $cat_assignments_by_role[$role_name] = [];
    }
    $cat_assignments_by_role[$role_name][] = $assignment;
}

// Count assignments per role
$role_param_counts = [];
foreach ($parameter_assignments as $assignment) {
    $role_id = $assignment['role_id'];
    if (!isset($role_param_counts[$role_id])) {
        $role_param_counts[$role_id] = 0;
    }
    $role_param_counts[$role_id]++;
}

// Count category assignments per role
$role_cat_counts = [];
foreach ($category_assignments as $assignment) {
    $role_id = $assignment['role_id'];
    if (!isset($role_cat_counts[$role_id])) {
        $role_cat_counts[$role_id] = 0;
    }
    $role_cat_counts[$role_id]++;
}

// Get users count per role
$users_per_role = [];
$users_count_result = $conn->query("
    SELECT r.id, r.name, COUNT(u.id) as user_count 
    FROM roles r 
    LEFT JOIN users u ON r.id = u.role_id AND u.is_active = 1 
    GROUP BY r.id, r.name
    ORDER BY r.name
");
while ($row = $users_count_result->fetch_assoc()) {
    $users_per_role[$row['id']] = $row;
}

/* Render: Tailwind view on shared layout */
ob_start();
require __DIR__ . '/resources/views/assignments/index.php';
$content = ob_get_clean();

if (isset($conn)) $conn->close();

$pageTitle   = 'Assignments · MUWASCO Monthly Report';
$currentPage = 'assignment.php';
require __DIR__ . '/resources/views/layouts/app.php';
