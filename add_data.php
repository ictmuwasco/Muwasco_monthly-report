<?php
// data_entry.php - Complete Data Entry Page
require_once 'db.php';
require_once 'auth_functions.php';

// Require login
requireLogin();

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

// Handle form submission
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_submitted) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_section') {
        $section_id = intval($_POST['section_id']);
        $data = $_POST['data'] ?? [];
        
        $conn->begin_transaction();
        try {
            foreach($data as $code => $value) {
                $stmt = $conn->prepare("SELECT p.id, p.category_id FROM parameters p WHERE p.code = ?");
                $stmt->bind_param("s", $code);
                $stmt->execute();
                $result = $stmt->get_result();
                $param = $result->fetch_assoc();
                
                if ($param) {
                    if (!canAccessParameter($conn, $_SESSION['user_id'], $param['id'])) {
                        throw new Exception("You don't have permission to edit parameter: $code");
                    }
                    
                    $stmt = $conn->prepare("INSERT INTO monthly_data (month_id, parameter_id, value) 
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE value = VALUES(value)");
                    $stmt->bind_param("iis", $month_id, $param['id'], $value);
                    $stmt->execute();
                }
            }
            $conn->commit();
            $success = "Section data saved successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saving section: " . $e->getMessage();
        }
    } elseif ($action === 'submit_final' && isAdmin()) {
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
$data_query = $conn->prepare("SELECT p.code, md.value 
              FROM monthly_data md
              JOIN parameters p ON md.parameter_id = p.id
              WHERE md.month_id = ?");
$data_query->bind_param("i", $month_id);
$data_query->execute();
$result = $data_query->get_result();

while($row = $result->fetch_assoc()) {
    $existing_data[$row['code']] = $row['value'];
}

// Get sections
$sections = getUserSections($conn, $_SESSION['user_id']);
$sections_with_params = [];
foreach($sections as $section) {
    $section['parameters'] = getUserParametersForSection($conn, $_SESSION['user_id'], $section['id']);
    if (!empty($section['parameters']) || isAdmin()) {
        $sections_with_params[] = $section;
    }
}

function getSavedSections($month_id, $conn, $user_id) {
    if (isAdmin()) {
        $sections = getAllSections($conn);
    } else {
        $sections = getUserSections($conn, $user_id);
    }
    
    $section_ids = array_column($sections, 'id');
    if (empty($section_ids)) return [];
    
    $ids_str = implode(',', $section_ids);
    $query = "SELECT DISTINCT pc.id 
              FROM monthly_data md 
              JOIN parameters p ON md.parameter_id = p.id 
              JOIN parameter_categories pc ON p.category_id = pc.id 
              WHERE md.month_id = ? AND pc.id IN ($ids_str)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $month_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $saved_sections = [];
    while($row = $result->fetch_assoc()) {
        $saved_sections[] = $row['id'];
    }
    return $saved_sections;
}

$saved_sections = getSavedSections($month_id, $conn, $_SESSION['user_id']);
$total_sections = count($sections_with_params);
$saved_count = count(array_intersect($saved_sections, array_column($sections_with_params, 'id')));

$user_info = getUserInfo($conn, $_SESSION['user_id']);
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- Water Background -->
    <div class="water-bg">
        <div class="water-wave"></div>
        <div class="water-wave"></div>
        <div class="water-wave"></div>
    </div>
    
    <div class="main-container">
        <?php include 'nav_bar.php'; ?>
        
        <div class="main-content">
            <div class="page-content">
                <div class="data-entry-container">
                    <!-- Alerts -->
                    <div class="alert-container">
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <div class="alert-icon">✓</div>
                                <div class="alert-content">
                                    <div class="alert-heading">Success!</div>
                                    <p><?php echo htmlspecialchars($success); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <div class="alert-icon">⚠️</div>
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
                                <?php echo strtoupper(substr($user_info['full_name'], 0, 1)); ?>
                            </div>
                            <div style="flex: 1;">
                                <h5><?php echo htmlspecialchars($user_info['full_name']); ?></h5>
                                <div>
                                    <span class="user-role">
                                        <?php echo isAdmin() ? 'Administrator' : 'Data Entry'; ?>
                                    </span>
                                    <span style="color: var(--text-tertiary);">
                                        👤 <?php echo htmlspecialchars($user_info['username']); ?>
                                    </span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: var(--text-primary); font-weight: 700; margin-bottom: var(--spacing-xs);">
                                    <?php echo count($sections_with_params); ?> Sections Assigned
                                </div>
                                <div class="progress-label">
                                    <span>Progress</span>
                                    <span><?php echo $saved_count; ?> / <?php echo $total_sections; ?></span>
                                </div>
                                <div class="progress-indicator">
                                    <div class="progress-fill" style="width: <?php echo $total_sections > 0 ? ($saved_count / $total_sections * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Month Header -->
                    <div class="month-header-card">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-md);">
                            <div>
                                <h2>📝 Monthly Data Entry</h2>
                                <div class="month-period">
                                    📅 <?php echo htmlspecialchars($month['name']); ?>
                                    <?php if ($month['start_date']): ?>
                                        <span style="margin-left: var(--spacing-md); color: var(--text-tertiary);">
                                            🕐 <?php echo date('M d, Y', strtotime($month['start_date'])); ?> - <?php echo date('M d, Y', strtotime($month['end_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="status-badge status-<?php echo $is_submitted ? 'submitted' : ($saved_count == $total_sections ? 'saved' : 'pending'); ?>">
                                <?php echo strtoupper($month['status']); ?>
                            </span>
                        </div>
                        
                        <?php if ($is_submitted): ?>
                            <div class="read-only-banner">
                                🔒
                                <div>
                                    <h5>Read-Only Mode</h5>
                                    <p>This month has been submitted and cannot be edited.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($sections_with_params)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">⚠️</div>
                            <h4>No Parameters Assigned</h4>
                            <p>You don't have access to any data entry parameters.<br>Please contact your system administrator.</p>
                        </div>
                    <?php else: ?>
                        <!-- Sections -->
                        <?php foreach($sections_with_params as $index => $section): 
                            $is_saved = in_array($section['id'], $saved_sections);
                            $param_count = count($section['parameters']);
                            $animation_delay = ($index * 0.1) + 0.2;
                        ?>
                        <div class="section-card <?php echo $is_saved ? 'saved' : 'unsaved'; ?>" style="animation-delay: <?php echo $animation_delay; ?>s;">
                            <div class="section-header">
                                <h3 class="section-title">
                                    📁 <?php echo htmlspecialchars($section['name']); ?>
                                </h3>
                                <div class="section-status">
                                    <span class="parameter-count">
                                        📊 <?php echo $param_count; ?> parameters
                                    </span>
                                    <span class="status-badge status-<?php echo $is_saved ? 'saved' : 'pending'; ?>">
                                        <?php echo $is_saved ? '✓ Saved' : '⏳ Pending'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if (!empty($section['description'])): ?>
                                <div style="padding: var(--spacing-md) var(--spacing-lg); border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                    <p style="color: var(--text-secondary); margin: 0;">
                                        📝 <?php echo htmlspecialchars($section['description']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="parameter-grid">
                                <?php foreach($section['parameters'] as $param): 
                                    // Check if this parameter should be multi-line (IDs that need textarea)
                                    $is_multiline = ($param['id'] == 221 || $param['id'] == 222 || $param['id'] == 172 || $param['id'] == 174 || $param['id'] == 305);
                                ?>
                                <div class="parameter-item <?php echo $param['required'] ? 'required' : ''; ?> <?php echo $is_multiline ? 'multiline' : ''; ?>">
                                    <div class="parameter-label">
                                        <span class="parameter-code"><?php echo htmlspecialchars($param['code']); ?></span>
                                        <div>
                                            <div class="parameter-text">
                                                <?php echo htmlspecialchars($param['label']); ?>
                                                <?php if ($param['required']): ?>
                                                    <span style="color: var(--danger-red); margin-left: 2px;">*</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(!empty($param['unit'])): ?>
                                                <div class="parameter-unit">
                                                    📏 Unit: <?php echo htmlspecialchars($param['unit']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($is_multiline): ?>
                                                <span class="multiline-hint">
                                                    ⌨️ Press Enter for new line
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if($is_multiline): ?>
                                        <!-- Use textarea for multi-line parameters -->
                                        <textarea 
                                            name="data[<?php echo htmlspecialchars($param['code']); ?>]" 
                                            class="parameter-input parameter-textarea"
                                            rows="3"
                                            <?php echo $is_submitted ? 'readonly' : ''; ?>
                                            <?php echo $param['required'] ? 'required' : ''; ?>
                                            placeholder="Enter value (press Enter for new line)..."><?php echo isset($existing_data[$param['code']]) ? htmlspecialchars($existing_data[$param['code']]) : ''; ?></textarea>
                                    <?php else: ?>
                                        <!-- Use text input for single-line parameters -->
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
                                <form method="POST" class="save-section-form">
                                    <input type="hidden" name="month_id" value="<?php echo $month_id; ?>">
                                    <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                    <input type="hidden" name="action" value="save_section">
                                    
                                    <button type="submit" class="btn-save-section" id="save-section-<?php echo $section['id']; ?>">
                                        💾 Save This Section
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Final Submission for Admin -->
                    <?php if (!$is_submitted && isAdmin()): ?>
                    <div class="final-submission-card">
                        <div class="final-submission-header">
                            <h3>✅ Final Report Submission</h3>
                            <p>Complete all sections to submit the final report and lock data for this month</p>
                        </div>
                        
                        <div class="completion-progress">
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $total_sections > 0 ? ($saved_count / $total_sections * 100) : 0; ?>%">
                                    <?php echo $saved_count; ?> of <?php echo $total_sections; ?> Sections Complete
                                </div>
                            </div>
                            <div class="progress-stats">
                                <span>Progress</span>
                                <span><?php echo $total_sections > 0 ? round(($saved_count / $total_sections * 100), 1) : 0; ?>% Complete</span>
                            </div>
                        </div>
                        
                        <?php if ($saved_count == $total_sections): ?>
                            <form method="POST" id="final-submission-form">
                                <input type="hidden" name="action" value="submit_final">
                                <button type="submit" class="btn-submit-final" onclick="return confirmFinalSubmission();">
                                    🚀 Submit Final Report
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn-submit-final" disabled>
                                🔒 Complete All Sections First
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="months.php" class="btn-back">
                            ← Back to Months
                        </a>
                        <a href="report.php?month_id=<?php echo $month_id; ?>" class="btn-view-report">
                            📊 View Report →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.querySelector('input[type="text"]:not([readonly]), textarea:not([readonly])');
            if (firstInput) {
                setTimeout(() => {
                    firstInput.focus();
                }, 300);
            }
            
            // Handle section form submissions
            const sectionForms = document.querySelectorAll('.save-section-form');
            sectionForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Get all parameter inputs within this section
                    const sectionCard = this.closest('.section-card');
                    const parameterInputs = sectionCard.querySelectorAll('.parameter-input, .parameter-textarea');
                    
                    // Validate required fields
                    let isValid = true;
                    const requiredErrors = [];
                    parameterInputs.forEach(input => {
                        if (input.hasAttribute('required') && !input.value.trim()) {
                            isValid = false;
                            input.style.borderColor = '#ff6b6b';
                            input.style.boxShadow = '0 0 0 3px rgba(255, 107, 107, 0.2)';
                            requiredErrors.push(input.previousElementSibling.querySelector('.parameter-text').textContent);
                        }
                    });
                    
                    if (!isValid) {
                        alert('❌ Please fill in all required fields marked with *:\n\n' + requiredErrors.join('\n'));
                        return;
                    }
                    
                    // Create a FormData object and copy all inputs
                    const formData = new FormData();
                    formData.append('month_id', this.querySelector('input[name="month_id"]').value);
                    formData.append('section_id', this.querySelector('input[name="section_id"]').value);
                    formData.append('action', this.querySelector('input[name="action"]').value);
                    
                    parameterInputs.forEach(input => {
                        formData.append(input.name, input.value);
                    });
                    
                    // Add loading state
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '⏳ Saving...';
                    submitBtn.disabled = true;
                    
                    // Submit form data
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        // Reload the page to show updated status
                        window.location.reload();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        alert('❌ Error saving section. Please try again.');
                    });
                });
            });
            
            // Final submission confirmation
            window.confirmFinalSubmission = function() {
                return confirm('⚠️ Are you sure you want to submit the final report?\n\nOnce submitted, the data for this month will be locked and cannot be edited.\nThis action requires administrative privileges.');
            };
            
            // Form validation feedback
            const parameterInputs = document.querySelectorAll('.parameter-input, .parameter-textarea');
            parameterInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.hasAttribute('required') && !this.value.trim()) {
                        this.style.borderColor = '#ff6b6b';
                        this.style.boxShadow = '0 0 0 3px rgba(255, 107, 107, 0.2)';
                    } else {
                        this.style.borderColor = this.value.trim() ? '#4ade80' : 'rgba(255, 255, 255, 0.2)';
                        this.style.boxShadow = this.value.trim() ? '0 0 0 3px rgba(74, 222, 128, 0.2)' : 'none';
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.hasAttribute('required') && this.value.trim()) {
                        this.style.borderColor = '#4ade80';
                        this.style.boxShadow = '0 0 0 3px rgba(74, 222, 128, 0.2)';
                    }
                });
            });
            
            // Textarea auto-expand functionality
            const textareas = document.querySelectorAll('.parameter-textarea');
            textareas.forEach(textarea => {
                // Auto-expand textarea based on content
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                
                // Trigger auto-expand on load for existing content
                if (textarea.value.trim()) {
                    setTimeout(() => {
                        textarea.style.height = 'auto';
                        textarea.style.height = (textarea.scrollHeight) + 'px';
                    }, 100);
                }
            });
            
            // Add enter key support for form submission (only for text inputs, not textareas)
            const textInputs = document.querySelectorAll('input[type="text"].parameter-input');
            textInputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const form = this.closest('.save-section-form');
                        if (form) {
                            form.dispatchEvent(new Event('submit'));
                        }
                    }
                });
            });
            
            // Highlight unsaved sections
            const unsavedSections = document.querySelectorAll('.section-card.unsaved');
            unsavedSections.forEach(section => {
                const saveBtn = section.querySelector('.btn-save-section');
                const inputs = section.querySelectorAll('.parameter-input, .parameter-textarea');
                
                // Check if any input has been modified
                inputs.forEach(input => {
                    const originalValue = input.value;
                    input.addEventListener('input', function() {
                        if (this.value !== originalValue && saveBtn) {
                            saveBtn.style.background = 'linear-gradient(135deg, #ff6b6b, #ff4757)';
                            saveBtn.innerHTML = '💾 Save Changes';
                        }
                    });
                    
                    // Check if value was pre-filled from database
                    if (originalValue && originalValue.trim()) {
                        input.style.borderColor = '#00ffff';
                        input.style.boxShadow = '0 0 0 3px rgba(0, 255, 255, 0.2)';
                    }
                });
            });
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + S to save current section
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    const focusedElement = document.activeElement;
                    if (focusedElement && (focusedElement.classList.contains('parameter-input') || focusedElement.classList.contains('parameter-textarea'))) {
                        const form = focusedElement.closest('.save-section-form');
                        if (form) {
                            form.dispatchEvent(new Event('submit'));
                        }
                    }
                }
            });
            
            // Show character count for textareas
            textareas.forEach(textarea => {
                const container = textarea.parentElement;
                const charCount = document.createElement('div');
                charCount.style.cssText = 'color: rgba(255, 255, 255, 0.5); font-size: 12px; text-align: right; margin-top: 5px;';
                charCount.className = 'char-count';
                container.appendChild(charCount);
                
                textarea.addEventListener('input', function() {
                    const count = this.value.length;
                    charCount.textContent = `${count} characters`;
                    
                    if (count > 1000) {
                        charCount.style.color = '#ff6b6b';
                    } else if (count > 500) {
                        charCount.style.color = '#ffc107';
                    } else {
                        charCount.style.color = 'rgba(255, 255, 255, 0.5)';
                    }
                });
                
                // Initial count
                textarea.dispatchEvent(new Event('input'));
            });
            
            // Add animation for progress bar
            const progressFill = document.querySelector('.progress-bar-fill');
            if (progressFill) {
                const width = progressFill.style.width;
                progressFill.style.width = '0%';
                setTimeout(() => {
                    progressFill.style.width = width;
                }, 500);
            }
            
            // Show save notification before leaving page
            window.addEventListener('beforeunload', function(e) {
                const hasUnsavedChanges = document.querySelector('.btn-save-section[style*="background: linear-gradient(135deg, #ff6b6b, #ff4757)"]');
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