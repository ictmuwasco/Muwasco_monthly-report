<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// parameter_assignments.php - Manage Role to Parameter Assignments
require_once 'db.php';
require_once 'auth_functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Role-Parameter Assignments - AquaTrack Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .assignments-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        
        .assignment-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 992px) {
            .assignment-actions {
                grid-template-columns: 1fr;
            }
        }
        
        .action-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        
        .action-card h3 {
            margin-bottom: 20px;
            color: #00ffff;
            font-size: 1.3rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }
        
        .form-select, .form-control {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #00a8ff, #00ffff);
            color: #0a1628;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 168, 255, 0.4);
        }
        
        .assignments-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .assignments-table th {
            background: rgba(0, 168, 255, 0.2);
            padding: 15px;
            text-align: left;
            color: #00ffff;
            font-weight: 600;
        }
        
        .assignments-table td {
            padding: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .assignments-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .role-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .role-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff6b6b, #ffa726);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-admin {
            background: rgba(0, 255, 157, 0.2);
            color: #4ade80;
            border: 1px solid rgba(0, 255, 157, 0.3);
        }
        
        .badge-role {
            background: rgba(0, 168, 255, 0.2);
            color: #00ffff;
            border: 1px solid rgba(0, 168, 255, 0.3);
        }
        
        .parameter-code {
            background: rgba(255, 255, 255, 0.1);
            color: #00ffff;
            padding: 4px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 13px;
            margin-right: 10px;
        }
        
        .category-section {
            margin-bottom: 30px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 20px;
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(0, 168, 255, 0.3);
        }
        
        .category-header h3 {
            color: #00ffff;
            font-size: 1.2rem;
            margin: 0;
        }
        
        .parameter-count {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }
        
        .dropdown-menu {
            display: none;
            position: absolute;
            background: rgba(0, 20, 40, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 168, 255, 0.3);
            border-radius: 8px;
            padding: 10px;
            min-width: 200px;
            z-index: 1000;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        
        .dropdown-item {
            padding: 8px 12px;
            color: #fff;
            text-decoration: none;
            display: block;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .dropdown-item:hover {
            background: rgba(0, 168, 255, 0.2);
        }
        
        .dropdown-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 8px 0;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.5);
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid;
        }
        
        .alert-success {
            background: rgba(0, 255, 157, 0.1);
            border-color: rgba(0, 255, 157, 0.3);
            color: #4ade80;
        }
        
        .alert-danger {
            background: rgba(255, 77, 77, 0.1);
            border-color: rgba(255, 77, 77, 0.3);
            color: #ff6b6b;
        }
        
        /* Top header for the page */
        .page-top-header {
            margin-bottom: 30px;
            padding: 25px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .page-top-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #fff, #00ffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .page-top-header p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.1rem;
        }
        
        /* Tabs for different assignment types */
        .tab-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .tab-header {
            display: flex;
            background: rgba(0, 20, 40, 0.8);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .tab-button {
            padding: 15px 25px;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .tab-button:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .tab-button.active {
            color: #00ffff;
            background: rgba(0, 168, 255, 0.1);
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #00a8ff, #00ffff);
        }
        
        .tab-content {
            padding: 25px;
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .role-section {
            margin-bottom: 30px;
        }
        
        .role-header {
            background: rgba(0, 168, 255, 0.1);
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
            border-bottom: 2px solid rgba(0, 168, 255, 0.3);
        }
        
        .role-header h3 {
            margin: 0;
            color: #00ffff;
        }
        
        .role-stats {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        
        .stat-item {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .stat-item strong {
            color: #fff;
        }
        
        /* User list styling */
        .user-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            margin-bottom: 5px;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00a8ff, #00ffff);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 13px;
            margin-right: 10px;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: 500;
            font-size: 13px;
        }
        
        .user-email {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.6);
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Include the sidebar navigation -->
        <?php include 'nav_bar.php'; ?>
        
        <!-- Sidebar Toggle Button for Mobile -->
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>
        
        <!-- Mobile Overlay -->
        <div class="mobile-overlay" id="mobileOverlay" aria-hidden="true"></div>
        
        <div class="main-content">
            <div class="assignments-container">
                <div class="page-top-header">
                    <h1><i class="bi bi-diagram-3"></i> Role-Parameter Assignments</h1>
                    <p>Manage which roles can enter specific parameters and categories</p>
                </div>
                
                <!-- Alerts -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3><i class="bi bi-people"></i> Roles & Users</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #00ffff;">
                            <?php echo count($roles); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Total roles in system
                        </p>
                        <div style="margin-top: 15px; font-size: 12px; color: rgba(255, 255, 255, 0.7); max-height: 150px; overflow-y: auto;">
                            <?php foreach ($roles as $role): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px; padding: 5px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                    <span><?php echo htmlspecialchars($role['name']); ?></span>
                                    <span>
                                        <?php echo isset($users_per_role[$role['id']]) ? $users_per_role[$role['id']]['user_count'] : 0; ?> users
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3><i class="bi bi-diagram-3"></i> Parameter Assignments</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #4ade80;">
                            <?php echo count($parameter_assignments); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Parameters assigned to roles
                        </p>
                        <div style="margin-top: 15px; font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php foreach ($roles as $role): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px; padding: 5px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                    <span><?php echo htmlspecialchars($role['name']); ?></span>
                                    <span style="color: #4ade80;">
                                        <?php echo isset($role_param_counts[$role['id']]) ? $role_param_counts[$role['id']] : 0; ?> params
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3><i class="bi bi-graph-up"></i> Category Assignments</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #ffc107;">
                            <?php echo count($category_assignments); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Categories assigned to roles
                        </p>
                        <div style="margin-top: 15px; font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php foreach ($roles as $role): ?>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px; padding: 5px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                    <span><?php echo htmlspecialchars($role['name']); ?></span>
                                    <span style="color: #ffc107;">
                                        <?php echo isset($role_cat_counts[$role['id']]) ? $role_cat_counts[$role['id']] : 0; ?> cats
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs for different assignment types -->
                <div class="tab-container">
                    <div class="tab-header">
                        <button class="tab-button active" onclick="switchTab('assign-parameter')">
                            <i class="bi bi-clipboard-plus"></i> Assign Parameter
                        </button>
                        <button class="tab-button" onclick="switchTab('assign-category')">
                            <i class="bi bi-folder-plus"></i> Assign Category
                        </button>
                        <button class="tab-button" onclick="switchTab('users-list')">
                            <i class="bi bi-people"></i> View Users
                        </button>
                    </div>
                    
                    <!-- Assign Parameter Tab -->
                    <div id="assign-parameter" class="tab-content active">
                        <div class="assignment-actions">
                            <!-- Add New Parameter Assignment -->
                            <div class="action-card">
                                <h3><i class="bi bi-plus-circle"></i> Assign Parameter to Role</h3>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="add_assignment">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Role:</label>
                                        <select name="role_id" class="form-select" required>
                                            <option value="">Choose a role...</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo $role['id']; ?>">
                                                    <?php echo htmlspecialchars($role['name']); ?>
                                                    <?php if (isset($users_per_role[$role['id']])): ?>
                                                        (<?php echo $users_per_role[$role['id']]['user_count']; ?> users)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Parameter:</label>
                                        <select name="parameter_id" class="form-select" required>
                                            <option value="">Choose a parameter...</option>
                                            <?php foreach ($parameters as $param): ?>
                                                <option value="<?php echo $param['id']; ?>">
                                                    [<?php echo htmlspecialchars($param['code']); ?>] 
                                                    <?php echo htmlspecialchars($param['label']); ?>
                                                    <?php if ($param['category_name']): ?>
                                                        (<?php echo htmlspecialchars($param['category_name']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn-primary">
                                        <i class="bi bi-clipboard-plus"></i> Assign Parameter
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Role Statistics -->
                            <div class="action-card">
                                <h3><i class="bi bi-people-fill"></i> Role Statistics</h3>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <table style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left; padding: 8px;">Role</th>
                                                <th style="text-align: center; padding: 8px;">Users</th>
                                                <th style="text-align: center; padding: 8px;">Parameters</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($roles as $role): ?>
                                                <tr>
                                                    <td style="padding: 8px;">
                                                        <div class="role-info">
                                                            <div class="role-avatar">
                                                                <?php echo strtoupper(substr($role['name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($role['name']); ?></div>
                                                                <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                                    <?php echo htmlspecialchars($role['description'] ?? ''); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: center; padding: 8px; font-weight: 600; color: #00ffff;">
                                                        <?php echo isset($users_per_role[$role['id']]) ? $users_per_role[$role['id']]['user_count'] : '0'; ?>
                                                    </td>
                                                    <td style="text-align: center; padding: 8px; font-weight: 600; color: #4ade80;">
                                                        <?php echo isset($role_param_counts[$role['id']]) ? $role_param_counts[$role['id']] : '0'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Assign Category Tab -->
                    <div id="assign-category" class="tab-content">
                        <div class="assignment-actions">
                            <!-- Add New Category Assignment -->
                            <div class="action-card">
                                <h3><i class="bi bi-plus-circle"></i> Assign Category to Role</h3>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="add_category_assignment">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Role:</label>
                                        <select name="role_id" class="form-select" required>
                                            <option value="">Choose a role...</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo $role['id']; ?>">
                                                    <?php echo htmlspecialchars($role['name']); ?>
                                                    <?php if (isset($users_per_role[$role['id']])): ?>
                                                        (<?php echo $users_per_role[$role['id']]['user_count']; ?> users)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Category:</label>
                                        <select name="category_id" class="form-select" required>
                                            <option value="">Choose a category...</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['id']; ?>">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                    <?php if ($category['description']): ?>
                                                        - <?php echo htmlspecialchars($category['description']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" class="btn-primary">
                                        <i class="bi bi-folder-plus"></i> Assign Category
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Category Statistics -->
                            <div class="action-card">
                                <h3><i class="bi bi-folder-fill"></i> Category Statistics</h3>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <table style="width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left; padding: 8px;">Category</th>
                                                <th style="text-align: center; padding: 8px;">Parameters</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $params_by_category = [];
                                            foreach ($parameters as $param) {
                                                $cat_id = $param['category_id'];
                                                if (!isset($params_by_category[$cat_id])) {
                                                    $params_by_category[$cat_id] = 0;
                                                }
                                                $params_by_category[$cat_id]++;
                                            }
                                            
                                            foreach ($categories as $category): 
                                            ?>
                                                <tr>
                                                    <td style="padding: 8px;">
                                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($category['name']); ?></div>
                                                        <?php if ($category['description']): ?>
                                                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                                <?php echo htmlspecialchars($category['description']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: center; padding: 8px; font-weight: 600; color: #ffc107;">
                                                        <?php echo isset($params_by_category[$category['id']]) ? $params_by_category[$category['id']] : '0'; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Users List Tab -->
                    <div id="users-list" class="tab-content">
                        <div class="action-card">
                            <h3><i class="bi bi-people-fill"></i> Active Users by Role</h3>
                            <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 20px;">
                                All active users in the system grouped by their roles
                            </p>
                            
                            <?php if (empty($users)): ?>
                                <div class="empty-state">
                                    <h3><i class="bi bi-inbox"></i> No active users found</h3>
                                    <p>There are currently no active users in the system.</p>
                                </div>
                            <?php else: ?>
                                <?php 
                                // Group users by role
                                $users_by_role = [];
                                foreach ($users as $user) {
                                    $role_name = $user['role_name'] ?: 'No Role';
                                    if (!isset($users_by_role[$role_name])) {
                                        $users_by_role[$role_name] = [];
                                    }
                                    $users_by_role[$role_name][] = $user;
                                }
                                
                                foreach ($users_by_role as $role_name => $role_users): 
                                ?>
                                    <div style="margin-bottom: 25px;">
                                        <h4 style="color: #00ffff; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid rgba(0, 168, 255, 0.3);">
                                            <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($role_name); ?>
                                            <span style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                (<?php echo count($role_users); ?> user<?php echo count($role_users) !== 1 ? 's' : ''; ?>)
                                            </span>
                                        </h4>
                                        
                                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px;">
                                            <?php foreach ($role_users as $user): ?>
                                                <div class="user-item">
                                                    <div class="user-avatar">
                                                        <?php 
                                                        $initials = '';
                                                        if (!empty(trim($user['full_name']))) {
                                                            $name_parts = explode(' ', trim($user['full_name']));
                                                            if (count($name_parts) >= 1) {
                                                                $initials = strtoupper(substr($name_parts[0], 0, 1));
                                                                if (count($name_parts) > 1) {
                                                                    $initials .= strtoupper(substr($name_parts[1], 0, 1));
                                                                }
                                                            }
                                                        }
                                                        if (empty($initials)) {
                                                            $initials = strtoupper(substr($user['username'], 0, 2));
                                                        }
                                                        echo $initials;
                                                        ?>
                                                    </div>
                                                    <div class="user-info">
                                                        <div class="user-name">
                                                            <?php 
                                                            if (!empty(trim($user['full_name']))) {
                                                                echo htmlspecialchars($user['full_name']);
                                                            } else {
                                                                echo htmlspecialchars($user['username']);
                                                            }
                                                            ?>
                                                        </div>
                                                        <div class="user-email">
                                                            <?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : 'No email'; ?>
                                                        </div>
                                                    </div>
                                                    <div style="font-size: 10px; color: #4ade80; background: rgba(0, 255, 157, 0.1); padding: 2px 6px; border-radius: 10px;">
                                                        Active
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Current Parameter Assignments by Role -->
                <div style="margin-top: 40px;">
                    <h2><i class="bi bi-clipboard-data"></i> Current Parameter Assignments</h2>
                    <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 20px;">
                        All parameters currently assigned to roles
                    </p>
                    
                    <?php if (empty($param_assignments_by_role)): ?>
                        <div class="empty-state">
                            <h3><i class="bi bi-inbox"></i> No parameter assignments found</h3>
                            <p>Use the form above to assign parameters to roles.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($param_assignments_by_role as $role_name => $role_assignments): 
                            $role_id = $role_assignments[0]['role_id'];
                        ?>
                            <div class="role-section">
                                <div class="role-header">
                                    <h3><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($role_name); ?></h3>
                                    <div class="role-stats">
                                        <div class="stat-item">
                                            <i class="bi bi-people"></i> 
                                            <strong><?php echo isset($users_per_role[$role_id]) ? $users_per_role[$role_id]['user_count'] : 0; ?></strong> users
                                        </div>
                                        <div class="stat-item">
                                            <i class="bi bi-clipboard-data"></i> 
                                            <strong><?php echo count($role_assignments); ?></strong> parameters
                                        </div>
                                    </div>
                                </div>
                                
                                <table class="assignments-table">
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Category</th>
                                            <th>Assigned On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($role_assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <span class="parameter-code"><?php echo htmlspecialchars($assignment['parameter_code']); ?></span>
                                                        <div>
                                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($assignment['parameter_label']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($assignment['category_name'] ?: 'Uncategorized'); ?>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($assignment['assigned_at'])); ?>
                                                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                        <?php echo date('H:i', strtotime($assignment['assigned_at'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="position: relative;">
                                                        <button class="btn-primary" onclick="toggleDropdown('param-dropdown-<?php echo $assignment['assignment_id']; ?>')">
                                                            <i class="bi bi-gear"></i> Manage
                                                        </button>
                                                        <div id="param-dropdown-<?php echo $assignment['assignment_id']; ?>" class="dropdown-menu">
                                                            <!-- Remove form -->
                                                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove this parameter assignment?');">
                                                                <input type="hidden" name="action" value="remove_assignment">
                                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                                                
                                                                <button type="submit" class="dropdown-item" style="color: #ff6b6b;">
                                                                    <i class="bi bi-trash"></i> Remove Assignment
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Current Category Assignments by Role -->
                <div style="margin-top: 40px;">
                    <h2><i class="bi bi-folder-data"></i> Current Category Assignments</h2>
                    <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 20px;">
                        All categories currently assigned to roles
                    </p>
                    
                    <?php if (empty($cat_assignments_by_role)): ?>
                        <div class="empty-state">
                            <h3><i class="bi bi-inbox"></i> No category assignments found</h3>
                            <p>Use the form above to assign categories to roles.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($cat_assignments_by_role as $role_name => $role_assignments): 
                            $role_id = $role_assignments[0]['role_id'];
                        ?>
                            <div class="role-section">
                                <div class="role-header">
                                    <h3><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($role_name); ?></h3>
                                    <div class="role-stats">
                                        <div class="stat-item">
                                            <i class="bi bi-people"></i> 
                                            <strong><?php echo isset($users_per_role[$role_id]) ? $users_per_role[$role_id]['user_count'] : 0; ?></strong> users
                                        </div>
                                        <div class="stat-item">
                                            <i class="bi bi-folder"></i> 
                                            <strong><?php echo count($role_assignments); ?></strong> categories
                                        </div>
                                    </div>
                                </div>
                                
                                <table class="assignments-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Assigned On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($role_assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($assignment['category_name']); ?></div>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($assignment['assigned_at'])); ?>
                                                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                        <?php echo date('H:i', strtotime($assignment['assigned_at'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="position: relative;">
                                                        <button class="btn-primary" onclick="toggleDropdown('cat-dropdown-<?php echo $assignment['assignment_id']; ?>')">
                                                            <i class="bi bi-gear"></i> Manage
                                                        </button>
                                                        <div id="cat-dropdown-<?php echo $assignment['assignment_id']; ?>" class="dropdown-menu">
                                                            <!-- Remove form -->
                                                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove this category assignment?');">
                                                                <input type="hidden" name="action" value="remove_category_assignment">
                                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                                                
                                                                <button type="submit" class="dropdown-item" style="color: #ff6b6b;">
                                                                    <i class="bi bi-trash"></i> Remove Assignment
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Unassigned Parameters -->
                <?php
                // Find unassigned parameters
                $assigned_parameter_ids = array_column($parameter_assignments, 'parameter_id');
                $unassigned_params = array_filter($parameters, function($param) use ($assigned_parameter_ids) {
                    return !in_array($param['id'], $assigned_parameter_ids);
                });
                
                if (!empty($unassigned_params)): 
                ?>
                    <div class="role-section" style="background: rgba(255, 193, 7, 0.05); border: 1px solid rgba(255, 193, 7, 0.2);">
                        <div class="role-header">
                            <h3 style="color: #ffc107;"><i class="bi bi-exclamation-triangle"></i> Unassigned Parameters</h3>
                            <div class="role-stats">
                                <div class="stat-item">
                                    <i class="bi bi-clipboard-data"></i> 
                                    <strong><?php echo count($unassigned_params); ?></strong> parameters need assignment
                                </div>
                            </div>
                        </div>
                        
                        <div style="padding: 20px;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                                <?php foreach ($unassigned_params as $param): ?>
                                    <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                            <span class="parameter-code"><?php echo htmlspecialchars($param['code']); ?></span>
                                            <?php if ($param['category_name']): ?>
                                                <span style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                    <?php echo htmlspecialchars($param['category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-weight: 500; margin-bottom: 5px;"><?php echo htmlspecialchars($param['label']); ?></div>
                                        <?php if ($param['unit']): ?>
                                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                Unit: <?php echo htmlspecialchars($param['unit']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Quick assign form -->
                                        <form method="POST" action="" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="add_assignment">
                                            <input type="hidden" name="parameter_id" value="<?php echo $param['id']; ?>">
                                            
                                            <div style="display: flex; gap: 10px;">
                                                <select name="role_id" class="form-select" style="flex: 1; font-size: 13px; padding: 6px;" required>
                                                    <option value="">Assign to role...</option>
                                                    <?php foreach ($roles as $role): ?>
                                                        <option value="<?php echo $role['id']; ?>">
                                                            <?php echo htmlspecialchars($role['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                                    <i class="bi bi-link"></i> Assign
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Unassigned Categories -->
                <?php
                // Find unassigned categories
                $assigned_category_ids = array_column($category_assignments, 'category_id');
                $unassigned_categories = array_filter($categories, function($category) use ($assigned_category_ids) {
                    return !in_array($category['id'], $assigned_category_ids);
                });
                
                if (!empty($unassigned_categories)): 
                ?>
                    <div class="role-section" style="background: rgba(255, 107, 107, 0.05); border: 1px solid rgba(255, 107, 107, 0.2); margin-top: 20px;">
                        <div class="role-header">
                            <h3 style="color: #ff6b6b;"><i class="bi bi-exclamation-triangle"></i> Unassigned Categories</h3>
                            <div class="role-stats">
                                <div class="stat-item">
                                    <i class="bi bi-folder"></i> 
                                    <strong><?php echo count($unassigned_categories); ?></strong> categories need assignment
                                </div>
                            </div>
                        </div>
                        
                        <div style="padding: 20px;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                                <?php foreach ($unassigned_categories as $category): ?>
                                    <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);">
                                        <div style="font-weight: 500; margin-bottom: 10px;"><?php echo htmlspecialchars($category['name']); ?></div>
                                        <?php if ($category['description']): ?>
                                            <div style="font-size: 13px; color: rgba(255, 255, 255, 0.7); margin-bottom: 10px;">
                                                <?php echo htmlspecialchars($category['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Count parameters in this category -->
                                        <?php
                                        $param_count = 0;
                                        foreach ($parameters as $param) {
                                            if ($param['category_id'] == $category['id']) {
                                                $param_count++;
                                            }
                                        }
                                        ?>
                                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.5); margin-bottom: 10px;">
                                            <i class="bi bi-clipboard-data"></i> Contains <?php echo $param_count; ?> parameters
                                        </div>
                                        
                                        <!-- Quick assign form -->
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="add_category_assignment">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            
                                            <div style="display: flex; gap: 10px;">
                                                <select name="role_id" class="form-select" style="flex: 1; font-size: 13px; padding: 6px;" required>
                                                    <option value="">Assign to role...</option>
                                                    <?php foreach ($roles as $role): ?>
                                                        <option value="<?php echo $role['id']; ?>">
                                                            <?php echo htmlspecialchars($role['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                                    <i class="bi bi-link"></i> Assign
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle dropdown menus
        function toggleDropdown(dropdownId) {
            const dropdown = document.getElementById(dropdownId);
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.dropdown-menu') && !event.target.closest('button[onclick*="toggleDropdown"]')) {
                const dropdowns = document.querySelectorAll('.dropdown-menu');
                dropdowns.forEach(dropdown => {
                    dropdown.style.display = 'none';
                });
            }
        });
        
        // Switch between tabs
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab button
            event.target.classList.add('active');
        }
        
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mobileOverlay = document.getElementById('mobileOverlay');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mainContent = document.querySelector('.main-content');

            // Toggle sidebar (desktop collapse / mobile open)
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.toggle('collapsed');
                        // Update main content margin
                        if (mainContent) {
                            if (sidebar.classList.contains('collapsed')) {
                                mainContent.style.marginLeft = '80px';
                                mainContent.style.width = 'calc(100vw - 80px)';
                            } else {
                                mainContent.style.marginLeft = '280px';
                                mainContent.style.width = 'calc(100vw - 280px)';
                            }
                        }
                    } else {
                        sidebar.classList.toggle('mobile-open');
                        mobileOverlay.classList.toggle('active');
                    }
                });
            }

            // Close mobile sidebar on overlay click
            if (mobileOverlay) {
                mobileOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('mobile-open');
                    mobileOverlay.classList.remove('active');
                });
            }

            // Close mobile sidebar on resize to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('mobile-open');
                    if (mobileOverlay) {
                        mobileOverlay.classList.remove('active');
                    }
                }
            });

            // Initialize main content margin
            if (mainContent && window.innerWidth > 992) {
                if (sidebar.classList.contains('collapsed')) {
                    mainContent.style.marginLeft = '80px';
                    mainContent.style.width = 'calc(100vw - 80px)';
                } else {
                    mainContent.style.marginLeft = '280px';
                    mainContent.style.width = 'calc(100vw - 280px)';
                }
            }
        });
    </script>
</body>
</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>