<?php
// login.php — Auth logic preserved; UI modernized with Tailwind.

require_once __DIR__ . '/bootstrap/init.php';   // secure session, headers, error handling, DB via .env
require_once __DIR__ . '/auth_functions.php';

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
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in · MUWASCO Monthly Report</title>
    <meta name="description" content="Secure login for the MUWASCO Monthly Reporting System">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💧</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/app.css">
</head>
<body class="h-full font-sans antialiased bg-gray-100">

    <!-- Split-screen layout: brand panel + form panel -->
    <div class="flex min-h-full flex-col lg:flex-row">

        <!-- Brand panel (hidden on small screens) -->
        <div class="relative hidden lg:flex lg:w-[45%] xl:w-[50%] flex-col justify-between bg-gradient-to-br from-primary-dark via-primary to-cyan-800 p-10 xl:p-14 text-white overflow-hidden">
            <div class="absolute -top-24 -right-24 h-96 w-96 rounded-full bg-white/5"></div>
            <div class="absolute bottom-0 left-0 h-72 w-72 rounded-full bg-black/10 translate-x-12 translate-y-24"></div>

            <header class="relative z-10 flex items-center gap-4">
                <img src="muwascologo.png" alt="MUWASCO logo" class="h-14 w-14 rounded-xl bg-white/95 object-contain p-1 shadow-lg ring-1 ring-white/30">
                <div>
                    <p class="text-xl font-bold tracking-tight">MUWASCO</p>
                    <p class="text-sm text-cyan-100/90">Murang'a Water &amp; Sanitation Company</p>
                </div>
            </header>

            <main class="relative z-10 max-w-md">
                <h2 class="text-3xl xl:text-4xl font-bold leading-tight">Monthly Performance Reporting, simplified.</h2>
                <p class="mt-4 text-cyan-100/90 leading-relaxed">
                    Capture, review and approve operational indicators — with role-based access, secure approval workflows and one-click PDF reporting.
                </p>
                <ul class="mt-8 space-y-3 text-sm text-cyan-50/90">
                    <li class="flex items-center gap-3"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/15">✓</span> Role-based data entry &amp; approvals</li>
                    <li class="flex items-center gap-3"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/15">✓</span> Complete historical record kept safe</li>
                    <li class="flex items-center gap-3"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-white/15">✓</span> Branded PDF exports for management</li>
                </ul>
            </main>

            <footer class="relative z-10 text-xs text-cyan-100/70">

        <!-- Form panel -->
        <div class="flex flex-1 flex-col items-center justify-center px-5 py-10 sm:px-10">
            <div class="w-full max-w-md">

                <!-- Mobile-only brand -->
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <img src="muwascologo.png" alt="MUWASCO logo" class="h-11 w-11 rounded-lg bg-white object-contain p-1 shadow ring-1 ring-gray-200">
                    <div>
                        <p class="font-bold text-gray-900 leading-tight">MUWASCO</p>
                        <p class="text-xs text-gray-500">Monthly Report System</p>
                    </div>
                </div>

                <div class="card sm:shadow-md">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Welcome back</h1>
                    <p class="mt-1 text-sm text-gray-500">Sign in to continue to your monthly report.</p>

                    <?php if ($error): ?>
                        <div class="alert-error mt-5" role="alert">
                            <strong class="font-semibold">Authentication failed:</strong><br>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="loginForm" autocomplete="on" class="mt-6 space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                        <div>
                            <label class="form-label" for="login_input">Username or Email</label>
                            <input type="text" id="login_input" name="login_input" class="form-input"
                                placeholder="you@muwasco.co.ke" required autofocus
                                autocomplete="username email"
                                value="<?= htmlspecialchars($login_input ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100">
                        </div>

                        <div>
                            <label class="form-label" for="password">Password</label>
                            <div class="relative">
                                <input type="password" id="password" name="password" class="form-input pr-11"
                                    placeholder="Enter your password" required minlength="6"

                        <div class="flex items-center justify-between text-sm">
                            <label class="flex items-center gap-2 text-gray-600 cursor-pointer select-none">
                                <input type="checkbox" id="rememberMe" name="remember" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                                <span>Remember me</span>
                            </label>
                            <a href="forgot_password.php" class="font-medium text-primary hover:text-primary-dark hover:underline">Forgot password?</a>
                        </div>

                        <button type="submit" id="loginButton" class="btn-primary w-full py-2.5 text-base">
                            Sign In
                        </button>
                    </form>

                    <p class="mt-6 border-t border-gray-100 pt-4 text-center text-xs text-gray-400">
                        Access is granted based on your assigned role.<br>Contact the administrator for role changes.
                    </p>
                </div>

                <p class="mt-6 flex items-center justify-center gap-1.5 text-xs text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                    </svg>
                    Secure connection · Role-based access control
                </p>
            </div>
        </div>
    </div>

    <script>
        // Password visibility toggle
        const toggleBtn = document.getElementById('togglePassword');
        const pwd = document.getElementById('password');
        toggleBtn?.addEventListener('click', () => {
            pwd.type = pwd.type === 'password' ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', pwd.type === 'password' ? 'Show password' : 'Hide password');
        });

        // Prevent double submission
        const loginForm = document.getElementById('loginForm');
        const loginButton = document.getElementById('loginButton');
        let submitting = false;
        loginForm?.addEventListener('submit', function (e) {
            if (submitting || !this.checkValidity()) { e.preventDefault(); return; }
            submitting = true;
            loginButton.disabled = true;
            loginButton.textContent = 'Authenticating…';
        });
    </script>
</body>
</html>

                                    autocomplete="current-password" maxlength="255">
                                <button type="button" id="togglePassword" aria-label="Show password"
                                        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                &copy; <?= date('Y') ?> MUWASCO · Internal Management Information System
            </footer>
        </div>

