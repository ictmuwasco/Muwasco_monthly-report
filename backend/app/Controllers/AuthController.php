<?php
// login.php — Auth logic preserved; UI modernized with Tailwind.

require_once __DIR__ . '/../../bootstrap/app.php';   // secure session, headers, error handling, DB via .env
require_once __DIR__ . '/../Helpers/AuthFunctions.php';

// CSRF Token for form protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$login_input = '';

// Rate limiting - simple implementation
if (isset($_SESSION['login_attempts'])) {
    if ($_SESSION['login_attempts'] >= 5 && 
        time() - $_SESSION['last_attempt_time'] < 300) {
        $error = "Too many login attempts. Please try again in 5 minutes.";
        $_SERVER['REQUEST_METHOD'] = 'GET'; // Prevent form processing
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security token mismatch. Please try again.";
        session_regenerate_id(true);
    } else {
        $login_input = isset($_POST['login_input']) ? trim($_POST['login_input']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        // Track login attempts
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = time();
        }
        
        if (!empty($login_input) && !empty($password)) {
            // Check if input looks like an email
            $is_email = filter_var($login_input, FILTER_VALIDATE_EMAIL);
            
            if ($is_email) {
                // Input is an email - validate email format
                if (!preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $login_input)) {
                    $error = "Invalid email format.";
                } elseif (!checkDatabaseConnection()) {
                    $error = "Database connection failed. Please try again later.";
                } else {
                    // Sanitize email
                    $sanitized_input = filter_var($login_input, FILTER_SANITIZE_EMAIL);
                    
                    // Build query to check by email with role information
                    $query = "SELECT 
                                u.id, 
                                u.username, 
                                u.password, 
                                u.first_name, 
                                u.last_name, 
                                u.surname,
                                u.email, 
                                u.is_active,
                                u.role_id,
                                r.name as role_name,
                                r.description as role_description
                              FROM users u
                              JOIN roles r ON u.role_id = r.id
                              WHERE u.email = ? AND u.is_active = 1";
                    
                    $stmt = $conn->prepare($query);
                    
                    if (!$stmt) {
                        $error = "System error. Please try again later.";
                        error_log("Prepare failed: " . $conn->error);
                    } else {
                        $stmt->bind_param("s", $sanitized_input);
                        
                        if (!$stmt->execute()) {
                            $error = "System error. Please try again.";
                            error_log("Execute failed: " . $stmt->error);
                        } else {
                            $result = $stmt->get_result();
                            processLoginResult($result, $password, $login_input, $conn, $error);
                        }
                        $stmt->close();
                    }
                }
            } else {
                // Input is a username - validate username format
                if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $login_input)) {
                    $error = "Invalid username format. Username must be 3-50 characters (letters, numbers, dots, underscores)";
                } elseif (!checkDatabaseConnection()) {
                    $error = "Database connection failed. Please try again later.";
                } else {
                    // Sanitize username
                    $sanitized_input = filter_var($login_input, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                    
                    // Build query to check by username with role information
                    $query = "SELECT 
                                u.id, 
                                u.username, 
                                u.password, 
                                u.first_name, 
                                u.last_name, 
                                u.surname,
                                u.email, 
                                u.is_active,
                                u.role_id,
                                r.name as role_name,
                                r.description as role_description
                              FROM users u
                              JOIN roles r ON u.role_id = r.id
                              WHERE u.username = ? AND u.is_active = 1";
                    
                    $stmt = $conn->prepare($query);
                    
                    if (!$stmt) {
                        $error = "System error. Please try again later.";
                        error_log("Prepare failed: " . $conn->error);
                    } else {
                        $stmt->bind_param("s", $sanitized_input);
                        
                        if (!$stmt->execute()) {
                            $error = "System error. Please try again.";
                            error_log("Execute failed: " . $stmt->error);
                        } else {
                            $result = $stmt->get_result();
                            processLoginResult($result, $password, $login_input, $conn, $error);
                        }
                        $stmt->close();
                    }
                }
            }
        } else {
            $error = "Please enter both username/email and password.";
        }
    }
    
    // Clear password from memory
    unset($password);
}

/**
 * Process login result - updated for role-based system
 */
function processLoginResult($result, $password, $login_input, $conn, &$error) {
    global $_SESSION;
    
    if ($user = $result->fetch_assoc()) {
        if (!$user['is_active']) {
            $error = "Your account has been deactivated. Please contact the administrator.";
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
        } elseif (password_verify($password, $user['password'])) {
            // Successful login - reset attempt counter
            unset($_SESSION['login_attempts']);
            unset($_SESSION['last_attempt_time']);
            
            // Update last login time if column exists
            $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
            $has_last_login = $check_column && $check_column->num_rows > 0;
            
            if ($has_last_login) {
                $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param("i", $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            // Update last activity if column exists
            $check_activity = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
            $has_last_activity = $check_activity && $check_activity->num_rows > 0;
            
            if ($has_last_activity) {
                $update_stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                if ($update_stmt) {
                    $update_stmt->bind_param("i", $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            // Regenerate session ID and set session variables
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['surname'] = $user['surname'];
            $_SESSION['email'] = $user['email'] ?? '';
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['role_description'] = $user['role_description'];
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            
            // Generate new CSRF token for the session
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            // Store user permissions in session (optional, can be fetched as needed)
            storeUserPermissions($conn, $user['role_id']);
            
            // Redirect
            $redirect_url = isset($_SESSION['redirect_url']) ? 
                          $_SESSION['redirect_url'] : 'index.php';
            unset($_SESSION['redirect_url']);
            
            header('Location: ' . $redirect_url);
            exit();
        } else {
            // Failed password attempt
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt_time'] = time();
            $error = "Invalid credentials. Please check your username/email and password.";
        }
    } else {
        // No user found - still increment counter but don't reveal which was wrong
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
        $error = "Invalid credentials. Please check your username/email and password.";
    }
}

/**
 * Store user permissions in session (optional optimization)
 */
function storeUserPermissions($conn, $role_id) {
    // Store parameter IDs the user has access to
    $stmt = $conn->prepare("SELECT parameter_id FROM role_parameter_assignments WHERE role_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $parameter_ids = [];
        while ($row = $result->fetch_assoc()) {
            $parameter_ids[] = $row['parameter_id'];
        }
        $_SESSION['user_parameter_ids'] = $parameter_ids;
        $stmt->close();
    }
    
    // Store category IDs the user has access to
    $stmt = $conn->prepare("SELECT category_id FROM role_category_assignments WHERE role_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $category_ids = [];
        while ($row = $result->fetch_assoc()) {
            $category_ids[] = $row['category_id'];
        }
        $_SESSION['user_category_ids'] = $category_ids;
        $stmt->close();
    }
}

// Database connection check function
function checkDatabaseConnection() {
    global $conn;
    if (!$conn) return false;
    
    // Test connection
    if (method_exists($conn, 'ping')) {
        return $conn->ping();
    }
    return true;
}

/* Render the login view (standalone HTML). */
require __DIR__ . '/../../../frontend/src/pages/login.php';
