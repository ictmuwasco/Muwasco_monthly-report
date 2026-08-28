<?php
// auth_functions.php

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Check if user has a specific role
 */
function hasRole($role_name) {
    if (!isLoggedIn()) return false;
    return isset($_SESSION['role_name']) && $_SESSION['role_name'] === $role_name;
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if user has access to a specific parameter
 */
function hasParameterAccess($parameter_id) {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return false;
    }
    
    // Admin has access to everything
    if (isAdmin()) {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as has_access 
        FROM role_parameter_assignments rpa
        WHERE rpa.role_id = ? AND rpa.parameter_id = ?
    ");
    
    $stmt->bind_param("ii", $_SESSION['role_id'], $parameter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row && $row['has_access'] > 0;
}

/**
 * Check if user has access to a specific category
 */
function hasCategoryAccess($category_id) {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return false;
    }
    
    // Admin has access to everything
    if (isAdmin()) {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as has_access 
        FROM role_category_assignments rca
        WHERE rca.role_id = ? AND rca.category_id = ?
    ");
    
    $stmt->bind_param("ii", $_SESSION['role_id'], $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row && $row['has_access'] > 0;
}

/**
 * Get user's assigned parameters
 */
function getUserParameters() {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return [];
    }
    
    // Admin gets all parameters
    if (isAdmin()) {
        $query = "SELECT * FROM parameters ORDER BY category_id, code";
        $result = $conn->query($query);
    } else {
        $stmt = $conn->prepare("
            SELECT p.*, pc.name as category_name, pc.display_order 
            FROM parameters p
            JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
            JOIN parameter_categories pc ON p.category_id = pc.id
            WHERE rpa.role_id = ?
            ORDER BY pc.display_order, p.code
        ");
        $stmt->bind_param("i", $_SESSION['role_id']);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $parameters = [];
    while ($row = $result->fetch_assoc()) {
        $parameters[] = $row;
    }
    
    if (isset($stmt)) $stmt->close();
    
    return $parameters;
}

/**
 * Get user's assigned categories
 */
function getUserCategories() {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return [];
    }
    
    // Admin gets all categories
    if (isAdmin()) {
        $query = "SELECT * FROM parameter_categories ORDER BY display_order";
        $result = $conn->query($query);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT pc.* 
            FROM parameter_categories pc
            JOIN parameters p ON pc.id = p.category_id
            JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
            WHERE rpa.role_id = ?
            ORDER BY pc.display_order
        ");
        $stmt->bind_param("i", $_SESSION['role_id']);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    if (isset($stmt)) $stmt->close();
    
    return $categories;
}

/**
 * Get parameters grouped by category for user
 */
function getUserParametersByCategory() {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return [];
    }
    
    $categories = getUserCategories();
    $parameters_by_category = [];
    
    foreach ($categories as $category) {
        if (isAdmin()) {
            $stmt = $conn->prepare("
                SELECT p.* 
                FROM parameters p
                WHERE p.category_id = ?
                ORDER BY p.code
            ");
            $stmt->bind_param("i", $category['id']);
        } else {
            $stmt = $conn->prepare("
                SELECT p.* 
                FROM parameters p
                JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
                WHERE rpa.role_id = ? AND p.category_id = ?
                ORDER BY p.code
            ");
            $stmt->bind_param("ii", $_SESSION['role_id'], $category['id']);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $parameters = [];
        while ($row = $result->fetch_assoc()) {
            $parameters[] = $row;
        }
        
        if (!empty($parameters)) {
            $parameters_by_category[] = [
                'category' => $category,
                'parameters' => $parameters
            ];
        }
        
        $stmt->close();
    }
    
    return $parameters_by_category;
}

/**
 * Get all categories (admin only or for display)
 */
function getAllCategories() {
    global $conn;
    
    $query = "SELECT * FROM parameter_categories ORDER BY display_order";
    $result = $conn->query($query);
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    return $categories;
}

/**
 * Get parameters for a specific category
 */
function getParametersForCategory($category_id) {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return [];
    }
    
    if (isAdmin()) {
        $stmt = $conn->prepare("SELECT * FROM parameters WHERE category_id = ? ORDER BY code");
        $stmt->bind_param("i", $category_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.* 
            FROM parameters p
            JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
            WHERE rpa.role_id = ? AND p.category_id = ?
            ORDER BY p.code
        ");
        $stmt->bind_param("ii", $_SESSION['role_id'], $category_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $parameters = [];
    while ($row = $result->fetch_assoc()) {
        $parameters[] = $row;
    }
    
    $stmt->close();
    return $parameters;
}

/**
 * Get user's full name
 */
function getUserFullName() {
    if (!isLoggedIn()) return '';
    
    if (isset($_SESSION['first_name']) && isset($_SESSION['last_name'])) {
        $name = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
        if (isset($_SESSION['surname']) && !empty($_SESSION['surname'])) {
            $name .= ' ' . $_SESSION['surname'];
        }
        return $name;
    }
    
    return $_SESSION['username'] ?? '';
}


function getUserInfo($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT u.*, r.name as role 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user) {
        // Create full name
        $user['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($user['full_name'])) {
            $user['full_name'] = $user['username'];
        }
    }
    
    return $user;
}


function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit();
    }
}

/**
 * Redirect if doesn't have required role
 */
function requireRole($role_name) {
    requireLogin();
    
    if (!hasRole($role_name)) {
        header('HTTP/1.0 403 Forbidden');
        echo "Access denied. You don't have the required role.";
        exit();
    }
}

/**
 * Redirect if doesn't have parameter access
 */
function requireParameterAccess($parameter_id) {
    requireLogin();
    
    if (!hasParameterAccess($parameter_id) && !isAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        echo "Access denied. You don't have permission to access this parameter.";
        exit();
    }
}

/**
 * Get saved sections count for a month
 */
function getSavedSectionsCount($month_id) {
    global $conn;
    
    $categories = getUserCategories();
    $saved_count = 0;
    
    foreach ($categories as $category) {
        if (isSectionSaved($month_id, $category['id'])) {
            $saved_count++;
        }
    }
    
    return $saved_count;
}

/**
 * Get user role information
 */
function getUserRoleInfo() {
    global $conn;
    
    if (!isLoggedIn() || !isset($_SESSION['role_id'])) {
        return null;
    }
    
    $stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['role_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $role = $result->fetch_assoc();
    $stmt->close();
    
    return $role;
}
?>