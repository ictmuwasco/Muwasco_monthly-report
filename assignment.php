<?php
// parameter_assignments.php - Manage Parameter to User Assignments
require_once 'db.php';
require_once 'auth_functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle form submissions
$success = null;
$error = null;

// Add new assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_assignment') {
    $user_id = intval($_POST['user_id']);
    $parameter_id = intval($_POST['parameter_id']);
    
    // Check if assignment already exists
    $check_stmt = $conn->prepare("SELECT id FROM user_parameter_assignments WHERE user_id = ? AND parameter_id = ?");
    $check_stmt->bind_param("ii", $user_id, $parameter_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "This parameter is already assigned to this user!";
    } else {
        // Add new assignment
        $stmt = $conn->prepare("INSERT INTO user_parameter_assignments (user_id, parameter_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $parameter_id);
        
        if ($stmt->execute()) {
            $success = "Parameter assigned successfully!";
        } else {
            $error = "Error assigning parameter: " . $conn->error;
        }
    }
}

// Remove assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'remove_assignment') {
    $assignment_id = intval($_POST['assignment_id']);
    
    $stmt = $conn->prepare("DELETE FROM user_parameter_assignments WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    
    if ($stmt->execute()) {
        $success = "Assignment removed successfully!";
    } else {
        $error = "Error removing assignment: " . $conn->error;
    }
}

// Update assignment (reassign parameter to different user)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_assignment') {
    $assignment_id = intval($_POST['assignment_id']);
    $new_user_id = intval($_POST['new_user_id']);
    
    // Check if the new user already has this parameter
    $check_stmt = $conn->prepare("
        SELECT ua.id 
        FROM user_parameter_assignments ua 
        WHERE ua.user_id = ? 
        AND ua.parameter_id = (
            SELECT parameter_id 
            FROM user_parameter_assignments 
            WHERE id = ?
        )
    ");
    $check_stmt->bind_param("ii", $new_user_id, $assignment_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "This parameter is already assigned to the selected user!";
    } else {
        // Update the assignment
        $stmt = $conn->prepare("UPDATE user_parameter_assignments SET user_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_user_id, $assignment_id);
        
        if ($stmt->execute()) {
            $success = "Assignment updated successfully!";
        } else {
            $error = "Error updating assignment: " . $conn->error;
        }
    }
}

// Get all users
$users = [];
$users_result = $conn->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name");
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}

// Get all parameters with categories
$parameters = [];
$params_result = $conn->query("
    SELECT 
        p.id, 
        p.code, 
        p.label, 
        p.unit,
        pc.name as category_name
    FROM parameters p
    LEFT JOIN parameter_categories pc ON p.category_id = pc.id
    ORDER BY pc.display_order, p.code
");
while ($row = $params_result->fetch_assoc()) {
    $parameters[] = $row;
}

// Get all current assignments with user and parameter details
$assignments = [];
$assignments_result = $conn->query("
    SELECT 
        ua.id as assignment_id,
        ua.user_id,
        ua.parameter_id,
        u.username as user_username,
        u.full_name as user_full_name,
        u.role as user_role,
        p.code as parameter_code,
        p.label as parameter_label,
        pc.name as category_name,
        ua.assigned_at
    FROM user_parameter_assignments ua
    JOIN users u ON ua.user_id = u.id
    JOIN parameters p ON ua.parameter_id = p.id
    LEFT JOIN parameter_categories pc ON p.category_id = pc.id
    WHERE u.is_active = 1
    ORDER BY pc.name, p.code, u.full_name
");
while ($row = $assignments_result->fetch_assoc()) {
    $assignments[] = $row;
}

// Group assignments by category for display
$assignments_by_category = [];
foreach ($assignments as $assignment) {
    $category = $assignment['category_name'] ?: 'Uncategorized';
    if (!isset($assignments_by_category[$category])) {
        $assignments_by_category[$category] = [];
    }
    $assignments_by_category[$category][] = $assignment;
}

// Count assignments per user
$user_assignment_counts = [];
foreach ($assignments as $assignment) {
    $user_id = $assignment['user_id'];
    if (!isset($user_assignment_counts[$user_id])) {
        $user_assignment_counts[$user_id] = 0;
    }
    $user_assignment_counts[$user_id]++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Parameter Assignments - AquaTrack Pro</title>
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
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00a8ff, #00ffff);
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
        
        .badge-user {
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
        
        /* Main content adjustments for sidebar */
        .main-content {
            margin-left: 280px;
            width: calc(100vw - 280px);
            transition: all 0.3s ease;
        }
        
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100vw;
            }
        }
        
        /* Mobile overlay */
        .mobile-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }
        
        .mobile-overlay.active {
            display: block;
        }
        
        /* Sidebar toggle button */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(0, 168, 255, 0.2);
            border: 1px solid rgba(0, 168, 255, 0.4);
            color: #00ffff;
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
            border-radius: 10px;
            transition: all 0.3s ease;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            display: none;
        }
        
        @media (max-width: 992px) {
            .sidebar-toggle {
                display: flex;
            }
        }
        
        .sidebar-toggle:hover {
            background: rgba(0, 168, 255, 0.3);
            transform: rotate(90deg);
            box-shadow: 0 4px 15px rgba(0, 168, 255, 0.4);
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
                    <h1><i class="bi bi-diagram-3"></i> Parameter Assignments Management</h1>
                    <p>Manage which users are responsible for entering specific parameters</p>
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
                        <h3><i class="bi bi-diagram-3"></i> Total Assignments</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #00ffff;">
                            <?php echo count($assignments); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Parameters assigned to users
                        </p>
                    </div>
                    
                    <div class="stat-card">
                        <h3><i class="bi bi-people"></i> Active Users</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #4ade80;">
                            <?php echo count($users); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Users available for assignments
                        </p>
                    </div>
                    
                    <div class="stat-card">
                        <h3><i class="bi bi-graph-up"></i> Parameters</h3>
                        <div style="font-size: 2.5rem; font-weight: 700; color: #ffc107;">
                            <?php echo count($parameters); ?>
                        </div>
                        <p style="color: rgba(255, 255, 255, 0.7); margin-top: 10px;">
                            Total parameters in system
                        </p>
                    </div>
                </div>
                
                <!-- Assignment Actions -->
                <div class="assignment-actions">
                    <!-- Add New Assignment -->
                    <div class="action-card">
                        <h3><i class="bi bi-plus-circle"></i> Assign New Parameter</h3>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_assignment">
                            
                            <div class="form-group">
                                <label class="form-label">Select User:</label>
                                <select name="user_id" class="form-select" required>
                                    <option value="">Choose a user...</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['full_name']); ?> 
                                            (<?php echo htmlspecialchars($user['username']); ?>)
                                            - <?php echo $user['role']; ?>
                                            <?php if (isset($user_assignment_counts[$user['id']])): ?>
                                                - <?php echo $user_assignment_counts[$user['id']]; ?> parameters
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
                    
                    <!-- User Statistics -->
                    <div class="action-card">
                        <h3><i class="bi bi-people-fill"></i> User Assignment Summary</h3>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <table style="width: 100%;">
                                <thead>
                                    <tr>
                                        <th style="text-align: left; padding: 8px;">User</th>
                                        <th style="text-align: center; padding: 8px;">Role</th>
                                        <th style="text-align: center; padding: 8px;">Assigned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td style="padding: 8px;">
                                                <div class="user-info">
                                                    <div class="user-avatar">
                                                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                            <?php echo htmlspecialchars($user['username']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="text-align: center; padding: 8px;">
                                                <span class="badge <?php echo $user['role'] == 'admin' ? 'badge-admin' : 'badge-user'; ?>">
                                                    <?php echo $user['role']; ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center; padding: 8px; font-weight: 600; color: #00ffff;">
                                                <?php echo isset($user_assignment_counts[$user['id']]) ? $user_assignment_counts[$user['id']] : '0'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Current Assignments by Category -->
                <div style="margin-top: 40px;">
                    <h2><i class="bi bi-clipboard-data"></i> Current Assignments</h2>
                    <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 20px;">
                        All parameters currently assigned to users, grouped by category
                    </p>
                    
                    <?php if (empty($assignments_by_category)): ?>
                        <div class="empty-state">
                            <h3><i class="bi bi-inbox"></i> No assignments found</h3>
                            <p>Use the form above to assign parameters to users.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($assignments_by_category as $category => $category_assignments): ?>
                            <div class="category-section">
                                <div class="category-header">
                                    <h3><i class="bi bi-folder"></i> <?php echo htmlspecialchars($category); ?></h3>
                                    <span class="parameter-count">
                                        <?php echo count($category_assignments); ?> parameters assigned
                                    </span>
                                </div>
                                
                                <table class="assignments-table">
                                    <thead>
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Assigned To</th>
                                            <th>Assigned On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($category_assignments as $assignment): ?>
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
                                                    <div class="user-info">
                                                        <div class="user-avatar">
                                                            <?php echo strtoupper(substr($assignment['user_full_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($assignment['user_full_name']); ?></div>
                                                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                                @<?php echo htmlspecialchars($assignment['user_username']); ?>
                                                            </div>
                                                            <span class="badge <?php echo $assignment['user_role'] == 'admin' ? 'badge-admin' : 'badge-user'; ?>">
                                                                <?php echo $assignment['user_role']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($assignment['assigned_at'])); ?>
                                                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                                                        <?php echo date('H:i', strtotime($assignment['assigned_at'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="position: relative;">
                                                        <button class="btn-primary" onclick="toggleDropdown('dropdown-<?php echo $assignment['assignment_id']; ?>')">
                                                            <i class="bi bi-gear"></i> Manage
                                                        </button>
                                                        <div id="dropdown-<?php echo $assignment['assignment_id']; ?>" class="dropdown-menu">
                                                            <!-- Reassign form -->
                                                            <form method="POST" action="" style="margin: 0;">
                                                                <input type="hidden" name="action" value="update_assignment">
                                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['assignment_id']; ?>">
                                                                
                                                                <div class="form-group" style="margin-bottom: 10px;">
                                                                    <label style="display: block; margin-bottom: 5px; font-size: 12px; color: rgba(255, 255, 255, 0.8);">
                                                                        Reassign to:
                                                                    </label>
                                                                    <select name="new_user_id" class="form-select" style="width: 100%; font-size: 13px; padding: 6px;">
                                                                        <?php foreach ($users as $user): ?>
                                                                            <option value="<?php echo $user['id']; ?>" 
                                                                                <?php echo $user['id'] == $assignment['user_id'] ? 'selected' : ''; ?>>
                                                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                
                                                                <button type="submit" class="dropdown-item" style="width: 100%; text-align: left;">
                                                                    <i class="bi bi-arrow-left-right"></i> Reassign
                                                                </button>
                                                            </form>
                                                            
                                                            <div class="dropdown-divider"></div>
                                                            
                                                            <!-- Remove form -->
                                                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove this assignment?');">
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
                
                <!-- Unassigned Parameters -->
                <?php
                // Find unassigned parameters
                $assigned_parameter_ids = array_column($assignments, 'parameter_id');
                $unassigned_params = array_filter($parameters, function($param) use ($assigned_parameter_ids) {
                    return !in_array($param['id'], $assigned_parameter_ids);
                });
                
                if (!empty($unassigned_params)): 
                ?>
                    <div class="category-section" style="background: rgba(255, 193, 7, 0.05); border: 1px solid rgba(255, 193, 7, 0.2);">
                        <div class="category-header">
                            <h3 style="color: #ffc107;"><i class="bi bi-exclamation-triangle"></i> Unassigned Parameters</h3>
                            <span class="parameter-count" style="color: #ffc107;">
                                <?php echo count($unassigned_params); ?> parameters need assignment
                            </span>
                        </div>
                        
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
                                            <select name="user_id" class="form-select" style="flex: 1; font-size: 13px; padding: 6px;" required>
                                                <option value="">Assign to user...</option>
                                                <?php foreach ($users as $user): ?>
                                                    <option value="<?php echo $user['id']; ?>">
                                                        <?php echo htmlspecialchars($user['full_name']); ?>
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