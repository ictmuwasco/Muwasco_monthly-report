<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

// Get month_id from URL or redirect
if (!isset($_GET['month_id'])) {
    header('Location: months.php');
    exit;
}

$month_id = intval($_GET['month_id']);

// Get month details
$month_query = $conn->prepare("SELECT * FROM months WHERE id = ?");
$month_query->bind_param("i", $month_id);
$month_query->execute();
$month = $month_query->get_result()->fetch_assoc();

if (!$month) {
    die("Month not found");
}

// Check if month is already submitted
$is_submitted = ($month['status'] === 'submitted');

// Get user info and role
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("
    SELECT u.*, r.name as role_name, r.description as role_description 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.id = ?
");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_info = $user_query->get_result()->fetch_assoc();

if (!$user_info) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$role_id = $user_info['role_id'];
$is_admin = ($user_info['role_name'] === 'admin');



// Function to get user's categories with parameters
function getUserCategoriesWithParameters($role_id, $is_admin) {
    global $conn;
    
    if ($is_admin) {
        // Admin gets all categories
        $query = "
            SELECT DISTINCT pc.* 
            FROM parameter_categories pc
            JOIN parameters p ON pc.id = p.category_id
            ORDER BY pc.display_order
        ";
        $result = $conn->query($query);
    } else {
        // Regular users get only categories with assigned parameters
        $stmt = $conn->prepare("
            SELECT DISTINCT pc.* 
            FROM parameter_categories pc
            JOIN parameters p ON pc.id = p.category_id
            JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
            WHERE rpa.role_id = ?
            ORDER BY pc.display_order
        ");
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    
    if (!$is_admin && isset($stmt)) {
        $stmt->close();
    }
    
    // Get parameters for each category
    $categories_with_params = [];
    foreach ($categories as $category) {
        if ($is_admin) {
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
            $stmt->bind_param("ii", $role_id, $category['id']);
        }
        
        $stmt->execute();
        $params_result = $stmt->get_result();
        
        $parameters = [];
        while ($param = $params_result->fetch_assoc()) {
            $parameters[] = $param;
        }
        
        if (!empty($parameters)) {
            $categories_with_params[] = [
                'category' => $category,
                'parameters' => $parameters
            ];
        }
        
        $stmt->close();
    }
    
    return $categories_with_params;
}

// Function to check if section is saved
function isSectionSaved($month_id, $category_id, $role_id, $is_admin) {
    global $conn;
    
    // Get parameters for this category that user has access to
    if ($is_admin) {
        $stmt = $conn->prepare("
            SELECT p.id 
            FROM parameters p 
            WHERE p.category_id = ?
        ");
        $stmt->bind_param("i", $category_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.id 
            FROM parameters p
            JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
            WHERE rpa.role_id = ? AND p.category_id = ?
        ");
        $stmt->bind_param("ii", $role_id, $category_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $param_ids = [];
    while ($row = $result->fetch_assoc()) {
        $param_ids[] = $row['id'];
    }
    $stmt->close();
    
    if (empty($param_ids)) return false;
    
    // Check if any of these parameters have data
    $param_ids_str = implode(',', $param_ids);
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) as saved_count 
        FROM monthly_data 
        WHERE month_id = ? AND parameter_id IN ($param_ids_str)
    ");
    $check_stmt->bind_param("i", $month_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    return $check_result && $check_result['saved_count'] > 0;
}

// Handle form submission
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_submitted) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_section') {
        $category_id = intval($_POST['category_id']);
        $data = $_POST['data'] ?? [];
        
        $conn->begin_transaction();
        try {
            foreach($data as $code => $value) {
                // Get parameter ID
                $stmt = $conn->prepare("SELECT p.id FROM parameters p WHERE p.code = ?");
                $stmt->bind_param("s", $code);
                $stmt->execute();
                $result = $stmt->get_result();
                $param = $result->fetch_assoc();
                
                if ($param) {
                    // Check if user has access to this parameter
                    if (!hasParameterAccess($param['id'], $role_id, $is_admin)) {
                        throw new Exception("You don't have permission to edit parameter: $code");
                    }
                    
                    // Insert or update data
                    $stmt = $conn->prepare("
                        INSERT INTO monthly_data (month_id, parameter_id, value) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE value = VALUES(value)
                    ");
                    $stmt->bind_param("iis", $month_id, $param['id'], $value);
                    if (!$stmt->execute()) {
                        throw new Exception("Error saving parameter $code");
                    }
                }
            }
            $conn->commit();
            $success = "Section data saved successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saving section: " . $e->getMessage();
        }
    } elseif ($action === 'submit_final' && $is_admin) {
        $stmt = $conn->prepare("UPDATE months SET status = 'submitted' WHERE id = ?");
        $stmt->bind_param("i", $month_id);
        
        if ($stmt->execute()) {
            $success = "Final report submitted successfully!";
            $is_submitted = true;
            $month['status'] = 'submitted';
        } else {
            $error = "Error submitting final report: " . $conn->error;
        }
    }
}

// Get existing data
$existing_data = [];
$data_query = $conn->prepare("
    SELECT p.code, md.value 
    FROM monthly_data md
    JOIN parameters p ON md.parameter_id = p.id
    WHERE md.month_id = ?
");
$data_query->bind_param("i", $month_id);
$data_query->execute();
$result = $data_query->get_result();

while($row = $result->fetch_assoc()) {
    $existing_data[$row['code']] = $row['value'];
}

// Get user's categories with parameters
$categories_with_params = getUserCategoriesWithParameters($role_id, $is_admin);

// Calculate progress
$total_categories = count($categories_with_params);
$saved_categories = 0;

foreach ($categories_with_params as $category_data) {
    if (isSectionSaved($month_id, $category_data['category']['id'], $role_id, $is_admin)) {
        $saved_categories++;
    }
}

// Get user full name
$full_name = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
if (empty($full_name)) {
    $full_name = $user_info['username'];
}

// Include navigation
require 'nav_bar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Entry - <?php echo htmlspecialchars($month['name']); ?> - AquaTrack Pro</title>
    
    <!-- External CSS -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
  
</head>
<body class="data-entry-page">
    <!-- Water Background -->
    <div class="water-bg">
        <div class="water-wave"></div>
        <div class="water-wave"></div>
        <div class="water-wave"></div>
    </div>
    
    <!-- Main Container -->
    <div class="main-container">
        <?php include 'nav_bar.php'; ?>
        
        <div class="main-content">
            <div class="page-content">
                <div class="data-entry-container">
                    <!-- Alerts -->
                    <div class="alert-container">
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
                                <div class="alert-content">
                                    <div class="alert-heading">Success!</div>
                                    <p><?php echo htmlspecialchars($success); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div class="alert-content">
                                    <div class="alert-heading">Error!</div>
                                    <p><?php echo htmlspecialchars($error); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- User Info Card -->
                    <div class="user-info-card">
                        <div class="user-info-header">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                            </div>
                            <div class="user-info-content">
                                <h5><?php echo htmlspecialchars($full_name); ?></h5>
                                <div>
                                    <span class="badge badge-info">
                                        <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($user_info['role_name'] ?? 'User'); ?>
                                    </span>
                                    <span class="text-muted ml-2">
                                        <i class="fas fa-user"></i> @<?php echo htmlspecialchars($user_info['username']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="user-info-stats">
                                <div class="stat-label mb-1">
                                    <strong><?php echo count($categories_with_params); ?></strong> Sections Assigned
                                </div>
                                <div class="progress-label">
                                    <span>Progress</span>
                                    <span><?php echo $saved_categories; ?> / <?php echo $total_categories; ?></span>
                                </div>
                                <div class="progress-indicator">
                                    <div class="progress-fill" style="width: <?php echo $total_categories > 0 ? ($saved_categories / $total_categories * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php if ($user_info['role_description']): ?>
                            <div class="permission-note mt-3">
                                <i class="fas fa-info-circle"></i> <strong>Role Description:</strong> <?php echo htmlspecialchars($user_info['role_description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Month Header -->
                    <div class="month-header-card">
                        <div class="month-header-content">
                            <div class="month-header-text">
                                <h2><i class="fas fa-edit"></i> Monthly Data Entry</h2>
                                <div class="month-period">
                                    <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($month['name']); ?>
                                    <?php if ($month['start_date']): ?>
                                        <span class="ml-3">
                                            <i class="fas fa-clock"></i> <?php echo date('M d, Y', strtotime($month['start_date'])); ?> - <?php echo date('M d, Y', strtotime($month['end_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="status-badge status-<?php echo $is_submitted ? 'submitted' : ($saved_categories == $total_categories ? 'saved' : 'pending'); ?>">
                                <?php echo strtoupper($month['status']); ?>
                            </span>
                        </div>
                        
                        <?php if ($is_submitted): ?>
                            <div class="read-only-banner">
                                <i class="fas fa-lock fa-lg"></i>
                                <div>
                                    <h5>Read-Only Mode</h5>
                                    <p>This month has been submitted and cannot be edited.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$is_admin): ?>
                            <div class="permission-note">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Note:</strong> You can only edit parameters assigned to your role. 
                                Other sections are hidden from view.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($categories_with_params)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <h4>No Parameters Assigned to Your Role</h4>
                            <p>Your role "<?php echo htmlspecialchars($user_info['role_name'] ?? 'Unknown'); ?>" doesn't have access to any data entry parameters.<br>Please contact your system administrator.</p>
                        </div>
                    <?php else: ?>
                        <!-- Categories/Sections -->
                        <?php foreach($categories_with_params as $index => $category_data): 
                            $category = $category_data['category'];
                            $parameters = $category_data['parameters'];
                            $is_saved = isSectionSaved($month_id, $category['id'], $role_id, $is_admin);
                            $param_count = count($parameters);
                        ?>
                        <div class="section-card <?php echo $is_saved ? 'saved' : 'unsaved'; ?>" style="animation-delay: <?php echo ($index * 0.1) + 0.2; ?>s;">
                            <div class="section-header">
                                <h3 class="section-title">
                                    <i class="fas fa-folder"></i> <?php echo htmlspecialchars($category['name']); ?>
                                    <?php if (!empty($category['description'])): ?>
                                        <span class="text-muted ml-2">- <?php echo htmlspecialchars($category['description']); ?></span>
                                    <?php endif; ?>
                                </h3>
                                <div class="section-status">
                                    <span class="parameter-count">
                                        <i class="fas fa-chart-bar"></i> <?php echo $param_count; ?> parameters
                                    </span>
                                    <span class="status-badge status-<?php echo $is_saved ? 'saved' : 'pending'; ?>">
                                        <?php echo $is_saved ? '<i class="fas fa-check"></i> Saved' : '<i class="fas fa-clock"></i> Pending'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="parameter-grid">
                                <?php foreach($parameters as $param): 
                                    // Check if this parameter should be multi-line
                                    $is_multiline = in_array($param['id'], [221, 222, 172, 174, 305]);
                                ?>
                                <div class="parameter-item <?php echo $param['required'] ? 'required' : ''; ?> <?php echo $is_multiline ? 'multiline' : ''; ?>">
                                    <div class="parameter-label">
                                        <span class="parameter-code"><?php echo htmlspecialchars($param['code']); ?></span>
                                        <div>
                                            <div class="parameter-text">
                                                <?php echo htmlspecialchars($param['label']); ?>
                                                <?php if ($param['required']): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(!empty($param['unit'])): ?>
                                                <div class="parameter-unit">
                                                    <i class="fas fa-ruler"></i> Unit: <?php echo htmlspecialchars($param['unit']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($is_multiline): ?>
                                                <div class="multiline-hint">
                                                    <i class="fas fa-keyboard"></i> Press Enter for new line
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if($is_multiline): ?>
                                        <textarea 
                                            name="data[<?php echo htmlspecialchars($param['code']); ?>]" 
                                            class="parameter-input parameter-textarea"
                                            rows="3"
                                            <?php echo $is_submitted ? 'readonly' : ''; ?>
                                            <?php echo $param['required'] ? 'required' : ''; ?>
                                            placeholder="Enter value (press Enter for new line)..."><?php echo isset($existing_data[$param['code']]) ? htmlspecialchars($existing_data[$param['code']]) : ''; ?></textarea>
                                    <?php else: ?>
                                        <input type="text" 
                                               name="data[<?php echo htmlspecialchars($param['code']); ?>]" 
                                               value="<?php echo isset($existing_data[$param['code']]) ? htmlspecialchars($existing_data[$param['code']]) : ''; ?>"
                                               class="parameter-input"
                                               <?php echo $is_submitted ? 'readonly' : ''; ?>
                                               <?php echo $param['required'] ? 'required' : ''; ?>
                                               placeholder="Enter value...">
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if (!$is_submitted && $param_count > 0): ?>
                            <div class="section-actions">
                                <form method="POST" class="save-section-form" id="form-<?php echo $category['id']; ?>">
                                    <input type="hidden" name="month_id" value="<?php echo $month_id; ?>">
                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                    <input type="hidden" name="action" value="save_section">
                                    
                                    <button type="submit" class="btn-save-section" id="save-section-<?php echo $category['id']; ?>">
                                        <i class="fas fa-save"></i> Save This Section
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Final Submission for Admin -->
                    <?php if (!$is_submitted && $is_admin): ?>
                    <div class="final-submission-card">
                        <div class="final-submission-header">
                            <h3><i class="fas fa-check-circle"></i> Final Report Submission</h3>
                            <p>Complete all sections to submit the final report and lock data for this month</p>
                        </div>
                        
                        <div class="completion-progress">
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $total_categories > 0 ? ($saved_categories / $total_categories * 100) : 0; ?>%">
                                    <?php echo $saved_categories; ?> of <?php echo $total_categories; ?> Sections Complete
                                </div>
                            </div>
                            <div class="progress-stats">
                                <span>Progress</span>
                                <span><?php echo $total_categories > 0 ? round(($saved_categories / $total_categories * 100), 1) : 0; ?>% Complete</span>
                            </div>
                        </div>
                        
                        <?php if ($saved_categories == $total_categories): ?>
                            <form method="POST" id="final-submission-form">
                                <input type="hidden" name="action" value="submit_final">
                                <button type="submit" class="btn-submit-final" onclick="return confirmFinalSubmission();">
                                    <i class="fas fa-rocket"></i> Submit Final Report
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn-submit-final" disabled>
                                <i class="fas fa-lock"></i> Complete All Sections First
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="months.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Months
                        </a>
                        <a href="report.php?month_id=<?php echo $month_id; ?>" class="btn-view-report">
                            <i class="fas fa-chart-bar"></i> View Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle section form submissions
        document.addEventListener('DOMContentLoaded', function() {
            const sectionForms = document.querySelectorAll('.save-section-form');
            sectionForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Validate required fields
                    const formData = new FormData(this);
                    const dataObj = Object.fromEntries(formData.entries());
                    const categoryId = dataObj.category_id;
                    
                    // Get all inputs in this section
                    const section = document.getElementById('form-' + categoryId).closest('.section-card');
                    const inputs = section.querySelectorAll('input[name^="data["], textarea[name^="data["]');
                    
                    let isValid = true;
                    const requiredFields = [];
                    
                    inputs.forEach(input => {
                        if (input.hasAttribute('required') && !input.value.trim()) {
                            isValid = false;
                            input.style.borderColor = '#ff4757';
                            const fieldLabel = input.closest('.parameter-item').querySelector('.parameter-text').textContent;
                            requiredFields.push(fieldLabel.trim());
                        }
                    });
                    
                    if (!isValid) {
                        alert('Please fill in all required fields marked with *:\n\n' + requiredFields.join('\n'));
                        return;
                    }
                    
                    // Submit form
                    const submitBtn = this.querySelector('.btn-save-section');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    submitBtn.disabled = true;
                    
                    fetch('', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(response => response.text())
                    .then(() => {
                        // Reload the page to show updated status
                        window.location.reload();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        alert('Error saving section. Please try again.');
                    });
                });
            });
            
            // Final submission confirmation
            window.confirmFinalSubmission = function() {
                return confirm('⚠️ Are you sure you want to submit the final report?\n\nOnce submitted, the data for this month will be locked and cannot be edited.\nThis action requires administrative privileges.');
            };
            
            // Auto-expand textareas based on content
            const textareas = document.querySelectorAll('.parameter-textarea');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                
                // Trigger on page load for existing content
                if (textarea.value.trim()) {
                    setTimeout(() => {
                        textarea.style.height = 'auto';
                        textarea.style.height = (textarea.scrollHeight) + 'px';
                    }, 100);
                }
            });
            
            // Remove error styling when user starts typing
            const inputs = document.querySelectorAll('input, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.style.borderColor === 'rgb(255, 71, 87)') {
                        this.style.borderColor = '';
                    }
                });
            });
            
            // Character count for textareas
            textareas.forEach(textarea => {
                const container = textarea.parentElement;
                const charCount = document.createElement('div');
                charCount.className = 'char-count text-muted small mt-1';
                container.appendChild(charCount);
                
                textarea.addEventListener('input', function() {
                    const count = this.value.length;
                    charCount.textContent = `${count} characters`;
                    
                    if (count > 1000) {
                        charCount.style.color = '#ff4757';
                    } else if (count > 500) {
                        charCount.style.color = '#ffc107';
                    } else {
                        charCount.style.color = 'var(--text-tertiary)';
                    }
                });
                
                // Initial count
                textarea.dispatchEvent(new Event('input'));
            });
            
            // Show unsaved changes warning
            window.addEventListener('beforeunload', function(e) {
                const hasUnsavedChanges = document.querySelectorAll('input:not([readonly]), textarea:not([readonly])')
                    .some(input => input.value !== input.defaultValue);
                
                if (hasUnsavedChanges) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                    return e.returnValue;
                }
            });
        });
    </script>
</body>
</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>