<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If no user is logged in, don't show the navbar
if (!isset($_SESSION['user_id'])) {
    return;
}

// Get database connection
require_once 'db.php';

// Include functions if not already included
if (!function_exists('getUserInfo')) {
    // Try to include auth_functions.php or functions.php
    $function_files = ['auth_functions.php', 'functions.php'];
    $function_included = false;
    
    foreach ($function_files as $file) {
        if (file_exists($file)) {
            require_once $file;
            $function_included = true;
            break;
        }
    }
    
    // If functions file not found, define the function here
    if (!$function_included) {
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
    }
}

// Get user info
$user_info = getUserInfo($conn, $_SESSION['user_id']);

// Set session role if not set
if (!isset($_SESSION['role']) && isset($user_info['role'])) {
    $_SESSION['role'] = $user_info['role'];
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <!-- Brand Header -->
    <div class="sidebar-brand">
        <div class="logo-container">
            <img src="muwascologo.png" alt="MUWASCO Logo" class="logo-img">
            <span class="brand-text">Muwasco</span>
        </div>
        <div class="org-name">Monthly Report</div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav" aria-label="Main navigation">
        <ul class="nav-menu">
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                    <span class="nav-icon" aria-hidden="true">🏠</span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'add_data.php' ? 'active' : ''; ?>" href="add_data.php">
                    <span class="nav-icon" aria-hidden="true">✏️</span>
                    <span class="nav-text">Data Entry</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'months.php' ? 'active' : ''; ?>" href="months.php">
                    <span class="nav-icon" aria-hidden="true">📅</span>
                    <span class="nav-text">Months</span>
                </a>
            </li>
              <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'assignment.php' ? 'active' : ''; ?>" href="assignment.php">
                    <span class="nav-icon" aria-hidden="true">👥</span>
                    <span class="nav-text">Parameter Assignment</span>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                    <span class="nav-icon" aria-hidden="true">📊</span>
                    <span class="nav-text">Reports</span>
                </a>
            </li>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?= $current_page == 'user_management.php' ? 'active' : ''; ?>" href="user_management.php">
                    <span class="nav-icon" aria-hidden="true">👥</span>
                    <span class="nav-text">Users</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- User Info Section -->
    <div class="sidebar-user-info">
        <div class="user-avatar">
            <i class="bi bi-person-circle"></i>
        </div>
        <div class="user-details">
            <span class="user-name"><?php echo htmlspecialchars($user_info['full_name'] ?? 'User'); ?></span>
            <span class="user-role"><?php echo htmlspecialchars($user_info['role'] ?? 'User'); ?></span>
        </div>
    </div>

    <!-- Logout Footer -->
    <div class="sidebar-footer">
        <form method="POST" action="logout.php" style="display: inline;">
            <button type="submit" class="logout-btn" aria-label="Logout">
                <span aria-hidden="true">🚪</span>
                <span class="logout-text">Logout</span>
            </button>
        </form>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay" aria-hidden="true"></div>

<!-- Add this to your HTML where you want the toggle button (usually in a header) -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list"></i>
</button>

<!-- Sidebar Toggle Script -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mobileOverlay = document.getElementById('mobileOverlay');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mainContent = document.querySelector('.main-content');

        // Create toggle button if it doesn't exist
        if (!sidebarToggle) {
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'sidebar-toggle';
            toggleBtn.id = 'sidebarToggle';
            toggleBtn.innerHTML = '<i class="bi bi-list"></i>';
            document.querySelector('.main-container').prepend(toggleBtn);
        }

        // Toggle sidebar (desktop collapse / mobile open)
        document.getElementById('sidebarToggle').addEventListener('click', function() {
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
                mobileOverlay.classList.remove('active');
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