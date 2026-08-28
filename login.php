<?php
// login.php — Auth logic preserved; UI modernized with Tailwind.

require_once __DIR__ . '/backend/bootstrap/app.php';   // secure session, headers, error handling, DB via .env
require_once __DIR__ . '/backend/app/Helpers/AuthFunctions.php';

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
    <style>
        /* ── LEFT: brand / information panel ─────────────────────── */
        .brand-panel {
            position: relative;
            display: none;
            overflow: hidden;
            width: 50%;
            min-height: 100vh;
            padding: 4rem;
            flex-direction: column;
            justify-content: space-between;
            gap: 3rem;
            color: #fff;
            background: linear-gradient(135deg, #234F66 0%, #2F647C 55%, #1E4D63 100%);
        }
        @media (min-width: 1024px) { .brand-panel { display: flex; } }
        @media (max-width: 1280px) { .brand-panel { padding: 3rem; } }
        .brand-panel .shape { position: absolute; border-radius: 9999px; pointer-events: none; }
        .shape-1 { top: -9rem; right: -9rem; width: 26rem; height: 26rem; background: rgba(255,255,255,.06); }
        .shape-2 { top: 8rem; right: -14rem; width: 32rem; height: 32rem; background: rgba(255,255,255,.04); }
        .shape-3 { bottom: -10rem; left: -6rem; width: 30rem; height: 30rem; background: rgba(0,0,0,.12); }
        .shape-4 { bottom: 10rem; left: 12rem; width: 10rem; height: 10rem; background: rgba(255,255,255,.05); }

        .brand-header { position: relative; z-index: 10; display: flex; align-items: center; gap: 1rem; }
        .brand-header img {
            height: 3.5rem; width: 3.5rem; flex: none;
            border-radius: 0.75rem; background: rgba(255,255,255,.95);
            object-fit: contain; padding: 0.25rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,.3); border: 1px solid rgba(255,255,255,.3);
        }
        .brand-title { font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em; }
        .brand-subtitle { font-size: 0.875rem; color: rgba(207,250,254,.9); margin-top: 2px; }

        .brand-main { position: relative; z-index: 10; max-width: 28rem; }
        .brand-heading { font-size: 2rem; font-weight: 700; line-height: 1.15; letter-spacing: -0.02em; }
        @media (min-width: 1280px) { .brand-heading { font-size: 2.25rem; } }
        .brand-heading span { color: #A5F3FC; }
        .brand-text { margin-top: 1.25rem; line-height: 1.65; color: rgba(236,254,255,.9); }
        .brand-features { margin-top: 2.25rem; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 0.875rem; font-size: 0.875rem; color: rgba(236,254,255,.9); }
        .brand-features li { display: flex; align-items: center; gap: 0.75rem; }
        .brand-features li span {
            display: flex; height: 1.5rem; width: 1.5rem; flex: none;
            align-items: center; justify-content: center;
            border-radius: 9999px; background: rgba(255,255,255,.15); font-size: 0.75rem;
        }
        .brand-footer { position: relative; z-index: 10; font-size: 0.75rem; color: rgba(207,250,254,.7); }

        /* ── RIGHT: authentication panel ─────────────────────────── */
        .auth-panel {
            position: relative;
            flex: 1 1 0%;
            background: #F8FAFC;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2.5rem 1.25rem;
        }
        @media (min-width: 640px) { .auth-panel { padding: 2.5rem 2.5rem; } }
        .login-card {
            width: 100%;
            max-width: 440px;
            padding: 42px;
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.10);
        }
        @media (max-width: 480px) { .login-card { padding: 28px 22px; border-radius: 14px; } }

        .auth-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: #334155;
            margin-bottom: 6px;
        }
        .auth-input {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            font-size: 0.9375rem;
            color: #0F172A;
            background: #F8FAFC;
            border: 1px solid #CBD5E1;
            border-radius: 10px;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .auth-input::placeholder { color: #94A3B8; }
        .auth-input:focus {
            background: #fff;
            border-color: #256F8F;
            box-shadow: 0 0 0 3px rgba(37, 111, 143, 0.15);
        }
        .signin-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            width: 100%;
            height: 50px;
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: .04em;
            color: #fff;
            background: linear-gradient(135deg, #256F8F, #164E63);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(22, 78, 99, .25);
            transition: transform .15s ease, box-shadow .2s ease, opacity .2s ease;
        }
        .signin-btn:hover { box-shadow: 0 12px 24px rgba(22, 78, 99, .32); transform: translateY(-1px); }
        .signin-btn:focus-visible { outline: 2px solid #164E63; outline-offset: 2px; }
        .signin-btn:disabled { opacity: .7; cursor: not-allowed; transform: none; }

        .forgot-link { font-size: .875rem; font-weight: 500; color: #256F8F; }
        .forgot-link:hover { color: #164E63; text-decoration: underline; }

        @media (prefers-reduced-motion: reduce) {
            .signin-btn, .auth-input { transition: none; }
            .signin-btn:hover { transform: none; }
        }
    </style>
</head>
<body class="h-full font-sans antialiased" style="background:#F8FAFC;">

    <div class="flex min-h-full flex-col lg:flex-row">

        <!-- ══════════ LEFT: MUWASCO branding & information ══════════ -->
        <section class="brand-panel" aria-label="About MUWASCO Monthly Reporting">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
            <div class="shape shape-4"></div>

            <!-- Brand header -->
            <header class="brand-header">
                <img src="public/assets/images/muwascologo.png" alt="MUWASCO logo">
                <div>
                    <p class="brand-title">MUWASCO</p>
                    <p class="brand-subtitle">Murang'a Water &amp; Sanitation Company</p>
                </div>
            </header>

            <!-- Product introduction -->
            <main class="brand-main">
                <h1 class="brand-heading">
                    Monthly Performance Reporting, <span>Simplified.</span>
                </h1>
                <p class="brand-text">
                    Capture, review and approve operational indicators — with role-based access,
                    secure approval workflows and one-click branded reporting for management.
                </p>

                <ul class="brand-features">
                    <li><span>✓</span> Role-based data entry &amp; approvals</li>
                    <li><span>✓</span> Complete historical records preserved</li>
                    <li><span>✓</span> Branded PDF exports for management</li>
                </ul>
            </main>

            <footer class="brand-footer">
                &copy; <?= date('Y') ?> MUWASCO · Internal Management Information System
            </footer>
        </section>

        <!-- ══════════ RIGHT: authentication ══════════ -->
        <section class="auth-panel" aria-label="Sign in">

            <!-- Mobile brand strip (brand panel is hidden on small screens) -->
            <h2 class="absolute h-px w-px overflow-hidden whitespace-nowrap" style="clip: rect(0 0 0 0);" aria-hidden="true">MUWASCO · Murang'a Water &amp; Sanitation Company</h2>

            <div class="login-card">
                <div class="mb-7 flex items-center gap-3 lg:hidden">
                    <img src="public/assets/images/muwascologo.png" alt="MUWASCO logo"
                         class="h-11 w-11 rounded-lg bg-white object-contain p-1 shadow ring-1 ring-gray-200">
                    <div>
                        <p class="font-bold leading-tight text-gray-900">MUWASCO</p>
                        <p class="text-xs text-gray-500">Monthly Report System</p>
                    </div>
                </div>

                <h2 class="text-2xl font-bold tracking-tight text-gray-900">Welcome Back</h2>
                <p class="mt-1.5 text-sm leading-relaxed text-gray-500">
                    Sign in to access your Monthly Performance Reporting workspace.
                </p>

                <?php if ($error): ?>
                    <div class="alert-error mt-6" role="alert">
                        <strong class="font-semibold">Authentication failed:</strong><br>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" autocomplete="on" class="mt-7 space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Username / Email -->
                    <div>
                        <label class="auth-label" for="login_input">Username or Email</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 0115 0"/>
                                </svg>
                            </span>
                            <input type="text" id="login_input" name="login_input" class="auth-input pl-11"
                                placeholder="you@muwasco.co.ke" required autofocus
                                autocomplete="username email"
                                value="<?= htmlspecialchars($login_input ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="auth-label" for="password">Password</label>
                            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                        </div>
                        <div class="relative">
                            <input type="password" id="password" name="password" class="auth-input pr-12"
                                placeholder="Enter your password" required minlength="6"
                                autocomplete="current-password" maxlength="255">
                            <button type="button" id="togglePassword" aria-label="Show password"
                                    class="absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400 transition-colors hover:text-gray-600 focus:outline-none">
                                <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center">
                        <label class="flex cursor-pointer select-none items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" id="rememberMe" name="remember"
                                   class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                            <span>Remember me on this device</span>
                        </label>
                    </div>

                    <!-- Sign in -->
                    <button type="submit" id="loginButton" class="signin-btn">SIGN IN</button>

                    <p class="text-center text-xs text-gray-400">
                        Access is granted based on your assigned role. Contact the administrator for role changes.
                    </p>
                </form>

                <!-- Security note -->
                <div class="mt-7 flex items-center justify-center gap-1.5 border-t border-gray-100 pt-5 text-xs text-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                    </svg>
                    Secure connection · Role-based access control
                </div>
            </div>
        </section>
    </div>

    <script>
        // Password visibility toggle
        (function () {
            const toggleBtn = document.getElementById('togglePassword');
            const pwd = document.getElementById('password');
            const eyeOpen = document.getElementById('eyeOpen');
            const eyeClosed = document.getElementById('eyeClosed');
            toggleBtn?.addEventListener('click', () => {
                const showing = pwd.type === 'text';
                pwd.type = showing ? 'password' : 'text';
                eyeOpen?.classList.toggle('hidden', !showing);
                eyeClosed?.classList.toggle('hidden', showing);
                toggleBtn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            });
        })();

        // Prevent double submission + loading state
        (function () {
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            let submitting = false;
            loginForm?.addEventListener('submit', function (e) {
                if (submitting) { e.preventDefault(); return; }
                if (!this.checkValidity()) { e.preventDefault(); this.reportValidity?.(); return; }
                submitting = true;
                loginButton.disabled = true;
                loginButton.textContent = 'Authenticating…';
            });
        })();
    </script>
</body>
</html>
