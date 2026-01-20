<?php
// months.php - Month Management Page
require_once 'db.php';
require_once 'auth_functions.php';

// Require login
requireLogin();

// Initialize variables
$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_month':
                $name = trim($_POST['name']);
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                
                // Validate dates
                if (empty($name) || empty($start_date) || empty($end_date)) {
                    $error = "Please fill in all required fields.";
                    break;
                }
                
                // Validate date format and logic
                $start_timestamp = strtotime($start_date);
                $end_timestamp = strtotime($end_date);
                
                if (!$start_timestamp || !$end_timestamp) {
                    $error = "Invalid date format.";
                    break;
                }
                
                if ($end_timestamp < $start_timestamp) {
                    $error = "End date must be after start date.";
                    break;
                }
                
                // NEW VALIDATION: Check if dates are within same month
                $start_year_month = date('Y-m', $start_timestamp);
                $end_year_month = date('Y-m', $end_timestamp);
                
                if ($start_year_month !== $end_year_month) {
                    $error = "Start and end dates must be within the same calendar month.";
                    break;
                }
                
                // Extract year-month from start_date for month_year field
                $month_year = date('Y-m', $start_timestamp);
                
                try {
                    // Check if month already exists
                    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM months WHERE month_year = ?");
                    $check_stmt->bind_param("s", $month_year);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($result['count'] > 0) {
                        $error = "A month for " . date('F Y', $start_timestamp) . " already exists.";
                        break;
                    }
                    
                    // Also check for overlapping date ranges
                    $overlap_check = $conn->prepare("
                        SELECT COUNT(*) as count FROM months 
                        WHERE (
                            (start_date <= ? AND end_date >= ?) OR
                            (start_date <= ? AND end_date >= ?) OR
                            (? <= start_date AND ? >= end_date)
                        )
                    ");
                    $overlap_check->bind_param("ssssss", $start_date, $start_date, $end_date, $end_date, $start_date, $end_date);
                    $overlap_check->execute();
                    $overlap_result = $overlap_check->get_result()->fetch_assoc();
                    $overlap_check->close();
                    
                    if ($overlap_result['count'] > 0) {
                        $error = "This date range overlaps with an existing month.";
                        break;
                    }
                    
                    // Get user info for created_by field
                    $user_info = getUserInfo($conn, $_SESSION['user_id'] ?? null);
                    $created_by = isset($user_info['username']) ? $user_info['username'] : 'System';
                    
                    // Insert the new month
                    $stmt = $conn->prepare("INSERT INTO months (name, month_year, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, 'draft', ?)");
                    $stmt->bind_param("sssss", $name, $month_year, $start_date, $end_date, $created_by);
                    
                    if ($stmt->execute()) {
                        $new_month_id = $conn->insert_id;
                        
                        // Store the new month ID to highlight it later
                        $_SESSION['last_created_month'] = $new_month_id;
                        
                        // Redirect to prevent form resubmission
                        header("Location: months.php?success=1");
                        exit();
                    } else {
                        // More detailed error handling
                        if ($conn->errno == 1062) { // Duplicate entry error code
                            $error = "Duplicate entry. A month with this name or period already exists.";
                        } else {
                            $error = "Database error: " . $conn->error . " (Error code: " . $conn->errno . ")";
                        }
                    }
                    
                    $stmt->close();
                } catch (Exception $e) {
                    $error = "Error creating month: " . $e->getMessage();
                }
                break;
                
            case 'delete_month':
                $month_id = intval($_POST['month_id']);
                
                // Check if month exists
                $check = $conn->prepare("SELECT COUNT(*) as count FROM months WHERE id = ?");
                $check->bind_param("i", $month_id);
                $check->execute();
                $result = $check->get_result()->fetch_assoc();
                $check->close();
                
                if ($result['count'] == 0) {
                    $error = "Month not found.";
                    break;
                }
                
                // Check if month has data
                $check_data = $conn->prepare("SELECT COUNT(*) as count FROM monthly_data WHERE month_id = ?");
                $check_data->bind_param("i", $month_id);
                $check_data->execute();
                $data_result = $check_data->get_result()->fetch_assoc();
                $check_data->close();
                
                if ($data_result['count'] > 0) {
                    $error = "Cannot delete month with existing data. Please delete the data first.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM months WHERE id = ?");
                    $stmt->bind_param("i", $month_id);
                    if ($stmt->execute()) {
                        $_SESSION['last_deleted_month'] = $month_id;
                        header("Location: months.php?deleted=1");
                        exit();
                    } else {
                        $error = "Error deleting month: " . $conn->error;
                    }
                    $stmt->close();
                }
                break;
        }
    }
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    $success = "Month created successfully!";
}

if (isset($_GET['deleted'])) {
    $success = "Month deleted successfully!";
}

// Get all months
$months_query = "SELECT * FROM months ORDER BY start_date DESC";
$months_result = $conn->query($months_query);

// Check for database errors
if (!$months_result) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Month Management - AquaTrack Pro</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="main-container">
        <!-- Navigation Bar -->
        <?php include 'nav_bar.php'; ?>
        
        <!-- Main Content Area -->
        <div class="main-content">
            <div class="page-content">
                <div class="content-wrapper">
                        <div class="container">
                            <!-- Page Header -->
                            <div class="page-header">
                                <h1>Month Management</h1>
                                <p>Create and manage reporting periods for water system data collection</p>
                            </div>

                            <!-- Success Alert -->
                            <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <span class="alert-icon">✅</span>
                                    <div>
                                        <strong>Success!</strong><br>
                                        <?php echo htmlspecialchars($success); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Error Alert -->
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <span class="alert-icon">⚠️</span>
                                    <div>
                                        <strong>Error!</strong><br>
                                        <?php echo htmlspecialchars($error); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Create Month Card -->
                            <div class="card">
                                <div class="card-header">
                                    <h2>➕ Create New Month</h2>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="createMonthForm">
                                        <input type="hidden" name="action" value="create_month">
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label">Month Name *</label>
                                                <input type="text" 
                                                       name="name" 
                                                       class="form-control" 
                                                       placeholder="e.g., December 2024" 
                                                       required>
                                                <span class="form-hint">Display name for the reporting period</span>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">Start Date *</label>
                                                <input type="date" 
                                                       name="start_date" 
                                                       class="form-control" 
                                                       required
                                                       onchange="updateEndDate()">
                                                <span class="form-hint">First day of the reporting period</span>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">End Date *</label>
                                                <input type="date" 
                                                       name="end_date" 
                                                       class="form-control" 
                                                       required>
                                                <span class="form-hint">Last day of the reporting period (auto-set)</span>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-primary">
                                            ➕ Create Month
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Months List Card -->
                            <div class="card">
                                <div class="card-header">
                                    <h2>📋 Existing Months</h2>
                                </div>
                                <div class="card-body">
                                    <?php if ($months_result->num_rows === 0): ?>
                                        <div class="empty-state">
                                            <div class="empty-state-icon">📅</div>
                                            <h3>No Months Created Yet</h3>
                                            <p>Start by creating your first reporting month using the form above</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-container">
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Month Details</th>
                                                        <th>Reporting Period</th>
                                                        <th>Status</th>
                                                        <th>Created</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php while ($month = $months_result->fetch_assoc()): 
                                                        $is_new = isset($_SESSION['last_created_month']) && $_SESSION['last_created_month'] == $month['id'];
                                                    ?>
                                                    <tr <?php echo $is_new ? 'class="new-month-added"' : ''; ?>>
                                                        <td>
                                                            <span class="badge badge-secondary">#<?php echo $month['id']; ?></span>
                                                        </td>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($month['name'] ?? date('F Y', strtotime($month['month_year']))); ?></strong>
                                                            <br>
                                                            <small style="color: rgba(255,255,255,0.6);">
                                                                <?php echo date('F Y', strtotime($month['month_year'])); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            📅 <?php echo date('M d, Y', strtotime($month['start_date'])); ?>
                                                            <br>
                                                            ❌ <?php echo date('M d, Y', strtotime($month['end_date'])); ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?php echo $month['status']; ?>">
                                                                ● <?php echo strtoupper($month['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M d, Y', strtotime($month['created_at'])); ?>
                                                            <br>
                                                            <small style="color: rgba(255,255,255,0.6);">
                                                                by <?php echo htmlspecialchars($month['created_by']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <?php if ($month['status'] === 'draft'): ?>
                                                                    <a href="add_data.php?month_id=<?php echo $month['id']; ?>" 
                                                                       class="btn btn-info btn-sm">
                                                                        ✏️ Enter Data
                                                                    </a>
                                                                    <form method="POST" 
                                                                          onsubmit="return confirm('Are you sure you want to delete this month?');"
                                                                          style="display: inline;">
                                                                        <input type="hidden" name="action" value="delete_month">
                                                                        <input type="hidden" name="month_id" value="<?php echo $month['id']; ?>">
                                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                                            🗑️ Delete
                                                                        </button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <a href="add_data.php?month_id=<?php echo $month['id']; ?>" 
                                                                       class="btn btn-info btn-sm">
                                                                        👁️ View Data
                                                                    </a>
                                                                    <span style="font-size: 12px; color: rgba(255,255,255,0.6);">
                                                                        🔒 Submitted
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endwhile; 
                                                    unset($_SESSION['last_created_month']);
                                                    unset($_SESSION['last_deleted_month']);
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update end date to last day of same month
        function updateEndDate() {
            const startDateInput = document.querySelector('input[name="start_date"]');
            const endDateInput = document.querySelector('input[name="end_date"]');
            
            if (startDateInput.value) {
                const startDate = new Date(startDateInput.value);
                const year = startDate.getFullYear();
                const month = startDate.getMonth();
                
                const lastDay = new Date(year, month + 1, 0);
                const lastDayStr = lastDay.toISOString().split('T')[0];
                
                endDateInput.value = lastDayStr;
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.querySelector('input[name="start_date"]');
            const endDateInput = document.querySelector('input[name="end_date"]');
            const nameInput = document.querySelector('input[name="name"]');
            
            // Set default to previous month
            const today = new Date();
            const prevMonth = today.getMonth() === 0 ? 11 : today.getMonth() - 1;
            const prevYear = today.getMonth() === 0 ? today.getFullYear() - 1 : today.getFullYear();
            
            const firstDay = new Date(prevYear, prevMonth, 1);
            const lastDay = new Date(prevYear, prevMonth + 1, 0);
            
            startDateInput.value = firstDay.toISOString().split('T')[0];
            endDateInput.value = lastDay.toISOString().split('T')[0];
            
            nameInput.focus();
            
            // Auto-generate month name
            startDateInput.addEventListener('change', function() {
                if (!nameInput.value && this.value) {
                    const date = new Date(this.value);
                    nameInput.value = date.toLocaleString('default', { month: 'long', year: 'numeric' });
                }
            });
            
            // Form validation
            document.getElementById('createMonthForm').addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<span class="spinner"></span> Creating...';
                submitBtn.disabled = true;
            });
        });
    </script>
</body>
</html>