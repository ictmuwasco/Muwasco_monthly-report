<?php
// auth_functions.php - Simplified version without auto-starting session

function isLoggedIn() {
    // Check session status before accessing
    if (session_status() === PHP_SESSION_NONE) {
        return false;
    }
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

function isAdmin() {
    if (!isLoggedIn()) return false;
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isLoggedIn()) {
        // Store current page for redirect after login
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
    
    // Check session timeout (30 minutes)
    if (isset($_SESSION['last_activity'])) {
        $timeout = 1800; // 30 minutes in seconds
        if (time() - $_SESSION['last_activity'] > $timeout) {
            // Session expired
            session_unset();
            session_destroy();
            header('Location: login.php?timeout=1');
            exit;
        }
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: index.php');
        exit;
    }
}

// Get sections for user based on their assigned parameters
function getUserSections($conn, $user_id) {
    if (isAdmin()) {
        return getAllSections($conn);
    }
    
    $stmt = $conn->prepare("
        SELECT DISTINCT pc.* 
        FROM parameter_categories pc
        JOIN parameters p ON pc.id = p.category_id
        JOIN user_parameter_assignments upa ON p.id = upa.parameter_id
        WHERE upa.user_id = ?
        ORDER BY pc.display_order
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sections = [];
    while($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
    return $sections;
}

function getAllSections($conn) {
    $query = "SELECT * FROM parameter_categories ORDER BY display_order";
    $result = $conn->query($query);
    
    $sections = [];
    while($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
    return $sections;
}

// Check if user can access a specific parameter
function canAccessParameter($conn, $user_id, $parameter_id) {
    if (isAdmin()) {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM user_parameter_assignments 
        WHERE user_id = ? AND parameter_id = ?
    ");
    $stmt->bind_param("ii", $user_id, $parameter_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['count'] > 0;
}

// Get user's assigned parameters for a section
function getUserParametersForSection($conn, $user_id, $category_id) {
    if (isAdmin()) {
        $stmt = $conn->prepare("SELECT * FROM parameters WHERE category_id = ? ORDER BY code");
        $stmt->bind_param("i", $category_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.* 
            FROM parameters p
            JOIN user_parameter_assignments upa ON p.id = upa.parameter_id
            WHERE upa.user_id = ? AND p.category_id = ?
            ORDER BY p.code
        ");
        $stmt->bind_param("ii", $user_id, $category_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $parameters = [];
    while($row = $result->fetch_assoc()) {
        $parameters[] = $row;
    }
    return $parameters;
}

function getUserInfo($conn, $user_id) {
    $stmt = $conn->prepare("SELECT id, username, full_name, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Check if user can access a section (has at least one parameter in that section)
function canAccessSection($conn, $user_id, $category_id) {
    if (isAdmin()) {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM user_parameter_assignments upa
        JOIN parameters p ON upa.parameter_id = p.id
        WHERE upa.user_id = ? AND p.category_id = ?
    ");
    $stmt->bind_param("ii", $user_id, $category_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['count'] > 0;
}

// Helper function to get current user ID
function getCurrentUserId() {
    if (!isLoggedIn()) {
        return null;
    }
    return $_SESSION['user_id'];
}

// Logout function
function logout() {
    // Clear all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    header('Location: login.php');
    exit;
}