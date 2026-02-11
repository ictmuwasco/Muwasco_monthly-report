<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'auth_functions.php';
require_once 'role_functions.php';

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
        $query = "
            SELECT DISTINCT pc.* 
            FROM parameter_categories pc
            JOIN parameters p ON pc.id = p.category_id
            ORDER BY pc.display_order
        ";
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

// Function to validate section data before final submission
function validateSectionForSubmission($month_id, $category_id, $is_admin) {
    global $conn;
    
    // Get all parameters for this category
    $stmt = $conn->prepare("
        SELECT p.id, p.code, p.label, p.required, md.value
        FROM parameters p
        LEFT JOIN monthly_data md ON p.id = md.parameter_id AND md.month_id = ?
        WHERE p.category_id = ?
        ORDER BY p.code
    ");
    $stmt->bind_param("ii", $month_id, $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $issues = [];
    $missing_required = [];
    $empty_values = [];
    
    while ($row = $result->fetch_assoc()) {
        $value = trim($row['value'] ?? '');
        $is_empty = empty($value);
        
        // Check required fields
        if ($row['required'] && $is_empty) {
            $missing_required[] = [
                'code' => $row['code'],
                'label' => $row['label']
            ];
        }
        
        // Check all fields (including non-required) that are empty
        if ($is_empty) {
            $empty_values[] = [
                'code' => $row['code'],
                'label' => $row['label'],
                'required' => $row['required']
            ];
        }
    }
    
    $stmt->close();
    
    if (!empty($missing_required) || !empty($empty_values)) {
        $issues['missing_required'] = $missing_required;
        $issues['empty_values'] = $empty_values;
        return $issues;
    }
    
    return true; // All good
}

// Handle form submission
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_submitted) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_section') {
        $category_id = intval($_POST['category_id']);
        $data = $_POST['data'] ?? [];
        
        // Validate: Check for empty required fields
        $validation_errors = [];
        foreach ($data as $code => $value) {
            $value = trim($value);
            // Get parameter details
            $stmt = $conn->prepare("SELECT id, required FROM parameters WHERE code = ?");
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $param = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($param && $param['required'] && empty($value)) {
                // For empty required fields, automatically insert dash
                $data[$code] = '-';
            } elseif (empty($value)) {
                // For empty non-required fields, also insert dash
                $data[$code] = '-';
            }
        }
        
        $conn->begin_transaction();
        try {
            foreach($data as $code => $value) {
                $value = trim($value);
                
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
                        throw new Exception("Error saving parameter $code: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
            $conn->commit();
            $success = "Section data saved successfully! Empty fields have been marked with '-'.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saving section: " . $e->getMessage();
        }
    } elseif ($action === 'submit_final' && $is_admin) {
        // Validate all sections before final submission
        $all_valid = true;
        $validation_results = [];
        
        // Get all categories
        $categories_with_params = getUserCategoriesWithParameters($role_id, $is_admin);
        
        foreach ($categories_with_params as $category_data) {
            $category_id = $category_data['category']['id'];
            $validation = validateSectionForSubmission($month_id, $category_id, $is_admin);
            
            if ($validation !== true) {
                $all_valid = false;
                $validation_results[$category_data['category']['name']] = $validation;
            }
        }
        
        if (!$all_valid) {
            // Build comprehensive error message
            $error = "Cannot submit final report. Please fix the following issues:<br><br>";
            
            foreach ($validation_results as $category_name => $issues) {
                $error .= "<strong>$category_name:</strong><br>";
                
                if (!empty($issues['missing_required'])) {
                    $error .= "&nbsp;&nbsp;• Missing required fields:<br>";
                    foreach ($issues['missing_required'] as $field) {
                        $error .= "&nbsp;&nbsp;&nbsp;&nbsp;- {$field['code']}: {$field['label']}<br>";
                    }
                }
                
                if (!empty($issues['empty_values'])) {
                    $error .= "&nbsp;&nbsp;• Empty fields (please enter value or '-'):<br>";
                    foreach ($issues['empty_values'] as $field) {
                        $error .= "&nbsp;&nbsp;&nbsp;&nbsp;- {$field['code']}: {$field['label']}";
                        $error .= $field['required'] ? " (Required)" : " (Optional)";
                        $error .= "<br>";
                    }
                }
                $error .= "<br>";
            }
            
            $error .= "<strong>Instructions:</strong><br>";
            $error .= "1. Required fields must have a value<br>";
            $error .= "2. Optional fields should have a value or '-' to indicate 'not applicable'<br>";
            $error .= "3. Click 'Save This Section' for each section before final submission";
        } else {
            // All valid, proceed with submission
            $conn->begin_transaction();
            try {
                // First, ensure all empty fields have dashes
                $update_stmt = $conn->prepare("
                    UPDATE monthly_data md
                    JOIN parameters p ON md.parameter_id = p.id
                    SET md.value = '-'
                    WHERE md.month_id = ? AND (md.value = '' OR md.value IS NULL)
                ");
                $update_stmt->bind_param("i", $month_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Update month status
                $stmt = $conn->prepare("UPDATE months SET status = 'submitted' WHERE id = ?");
                $stmt->bind_param("i", $month_id);
                
                if ($stmt->execute()) {
                    $conn->commit();
                    $success = "Final report submitted successfully! All data has been verified and empty fields marked with '-'.";
                    $is_submitted = true;
                    $month['status'] = 'submitted';
                } else {
                    throw new Exception("Error updating month status: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error submitting final report: " . $e->getMessage();
            }
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
$completely_filled_categories = 0;

foreach ($categories_with_params as $category_data) {
    if (isSectionSaved($month_id, $category_data['category']['id'], $role_id, $is_admin)) {
        $saved_categories++;
        
        // Check if category is completely filled (no empty values)
        $validation = validateSectionForSubmission($month_id, $category_data['category']['id'], $is_admin);
        if ($validation === true) {
            $completely_filled_categories++;
        }
    }
}

// Get user full name
$full_name = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
if (empty($full_name)) {
    $full_name = $user_info['username'];
}
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
    <style>
        /* Enhanced CSS for better user experience */
        .section-validation-warning {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: var(--radius-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            margin: var(--spacing-md) var(--spacing-lg);
            color: var(--warning-orange);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            font-size: 0.9rem;
        }
        
        .section-validation-warning i {
            font-size: 1.1rem;
        }
        
        .save-hint, .submit-hint {
            margin-top: var(--spacing-sm);
            color: var(--text-tertiary);
            font-size: 0.85rem;
            text-align: center;
        }
        
        .save-hint i, .submit-hint i {
            margin-right: 5px;
        }
        
        .data-quality-note {
            background: rgba(0, 168, 255, 0.1);
            border: 1px solid rgba(0, 168, 255, 0.3);
            border-radius: var(--radius-sm);
            padding: var(--spacing-sm) var(--spacing-md);
            margin-top: var(--spacing-md);
            font-size: 14px;
            color: var(--accent-cyan);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
        }
        
        .data-quality-note i {
            font-size: 1.2rem;
        }
        
        .field-status-indicator {
            position: absolute;
            right: 10px;
            top: 10px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--text-tertiary);
            transition: all 0.3s ease;
        }
        
        .field-status-indicator.filled {
            background: var(--success-green);
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
        }
        
        .field-status-indicator.empty {
            background: var(--warning-orange);
        }
        
        .field-status-indicator.required-empty {
            background: var(--danger-red);
            animation: blink 2s infinite;
        }
        
        .field-status-indicator.saved {
            background: var(--success-green);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.3);
            transform: scale(1.2);
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .parameter-item {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .parameter-item.missing-required {
            border-color: var(--danger-red) !important;
            background-color: rgba(255, 77, 77, 0.05);
        }
        
        .parameter-item.empty-field {
            border-color: var(--warning-orange) !important;
            background-color: rgba(255, 193, 7, 0.05);
        }
        
        .parameter-item.saved-field {
            border-color: var(--success-green) !important;
            background-color: rgba(40, 167, 69, 0.05);
            border-left: 3px solid var(--success-green);
        }
        
        .validation-hint {
            font-size: 12px;
            color: var(--text-tertiary);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .validation-hint i {
            font-size: 11px;
        }
        
        .btn-validate-all {
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-teal));
            color: var(--primary-dark);
            border: none;
            padding: var(--spacing-sm) var(--spacing-xl);
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: var(--spacing-sm);
            text-decoration: none;
            min-width: 200px;
            justify-content: center;
        }
        
        .btn-validate-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(0, 247, 255, 0.4);
        }
        
        .validation-panel {
            background: var(--glass-bg);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            margin: var(--spacing-lg) 0;
            border: 1px solid var(--glass-border);
        }
        
        .validation-header {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            margin-bottom: var(--spacing-md);
            padding-bottom: var(--spacing-sm);
            border-bottom: 1px solid var(--glass-border);
        }
        
        .validation-header h4 {
            margin: 0;
            color: var(--text-primary);
        }
        
        .validation-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .validation-item {
            padding: var(--spacing-md);
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-md);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }
        
        .validation-item.good {
            border-left: 4px solid var(--success-green);
        }
        
        .validation-item.warning {
            border-left: 4px solid var(--warning-orange);
        }
        
        .validation-item.error {
            border-left: 4px solid var(--danger-red);
        }
        
        .validation-item h5 {
            margin: 0 0 5px 0;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        .validation-item p {
            margin: 0;
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--text-primary);
        }
        
        .validation-instructions {
            background: rgba(0, 168, 255, 0.1);
            border-left: 4px solid var(--primary-blue);
            padding: var(--spacing-md);
            border-radius: var(--radius-sm);
            margin-top: var(--spacing-lg);
        }
        
        .validation-instructions h5 {
            margin-top: 0;
            color: var(--primary-light);
        }
        
        .validation-instructions ul {
            margin-bottom: 0;
            padding-left: 20px;
        }
        
        .validation-instructions li {
            margin-bottom: 5px;
            color: var(--text-secondary);
        }
        
        .validation-instructions li:last-child {
            margin-bottom: 0;
        }
        
        .partially-saved {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.3), rgba(253, 126, 20, 0.2));
            color: var(--warning-orange);
            border: 1px solid rgba(255, 193, 7, 0.4);
        }
        
        /* Toast notifications */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: var(--spacing-md) var(--spacing-lg);
            border-radius: var(--radius-md);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            z-index: 9999;
            animation: slideInDown 0.3s ease-out;
            transition: var(--transition);
            max-width: 400px;
        }
        
        .toast-success {
            border-left: 4px solid var(--success-green);
        }
        
        .toast-error {
            border-left: 4px solid var(--danger-red);
        }
        
        .toast-warning {
            border-left: 4px solid var(--warning-orange);
        }
        
        .toast-icon {
            font-size: 1.25rem;
        }
        
        .toast-success .toast-icon {
            color: var(--success-green);
        }
        
        .toast-error .toast-icon {
            color: var(--danger-red);
        }
        
        .toast-warning .toast-icon {
            color: var(--warning-orange);
        }
        
        .toast-message {
            font-weight: 500;
            flex: 1;
            color: var(--primary-dark);
        }
        
        .toast-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--text-tertiary);
            margin-left: var(--spacing-sm);
            line-height: 1;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Save success notification */
        .save-success-notification {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--success-green);
            color: var(--primary-dark);
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: var(--radius-sm);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            z-index: 100;
            animation: slideInRight 0.3s ease-out;
            transition: var(--transition);
            font-weight: 500;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Saving animation */
        @keyframes pulseSuccess {
            0% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
            }
        }
        
        .saving-animation {
            animation: pulseSuccess 1.5s ease;
        }
        
        /* Save pulse animation */
        @keyframes pulseSave {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .save-pulse {
            animation: pulseSave 0.5s ease;
        }
        
        /* Completely filled section */
        .completely-filled {
            border-left: 4px solid var(--primary-blue) !important;
        }
        
        .section-card.completely-filled {
            border-left: 4px solid var(--primary-blue);
        }
        
        .section-card.saved {
            border-left: 4px solid var(--success-green);
            background: rgba(40, 167, 69, 0.02);
        }
        
        /* Tooltip for saved timestamp */
        .status-badge[title] {
            position: relative;
            cursor: help;
        }
        
        .status-badge[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            pointer-events: none;
            font-family: 'Inter', sans-serif;
            font-weight: 400;
        }
        
        /* Auto-save indicator */
        .auto-save-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--success-green);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }
        
        .auto-save-indicator.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Progress animation */
        .progress-fill {
            transition: width 0.5s ease;
        }
        
        /* Saved data indicator */
        .saved-data-indicator {
            position: absolute;
            top: 5px;
            right: 30px;
            font-size: 10px;
            color: var(--success-green);
            background: rgba(40, 167, 69, 0.1);
            padding: 2px 6px;
            border-radius: 10px;
            display: none;
        }
        
        .parameter-item.saved-field .saved-data-indicator {
            display: block;
        }
        
        /* Enhanced section actions */
        .section-actions {
            position: relative;
        }
        
        .last-saved-time {
            font-size: 11px;
            color: var(--text-tertiary);
            text-align: center;
            margin-top: 5px;
            font-style: italic;
        }
    </style>
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
                                    <p><?php echo $success; ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                                <div class="alert-content">
                                    <div class="alert-heading">Validation Error!</div>
                                    <p><?php echo $error; ?></p>
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
                        <?php if (isset($user_info['role_description']) && !empty($user_info['role_description'])): ?>
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
                        
                        <div class="data-quality-note mt-3">
                            <i class="fas fa-clipboard-check"></i> 
                            <strong>Data Quality Guidelines:</strong> 
                            Required fields must be filled. Optional fields should contain data or '-' to indicate "not applicable". 
                            Empty fields will be automatically marked with '-' when saving.
                        </div>
                        
                        <!-- Auto-save status -->
                        <div class="auto-save-indicator" id="autoSaveIndicator">
                            <i class="fas fa-save"></i>
                            <span>Data saved successfully</span>
                        </div>
                    </div>

                    <?php if (empty($categories_with_params)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <h4>No Parameters Assigned to Your Role</h4>
                            <p>Your role "<?php echo htmlspecialchars($user_info['role_name'] ?? 'Unknown'); ?>" doesn't have access to any data entry parameters.<br>Please contact your system administrator.</p>
                        </div>
                    <?php else: ?>
                        <!-- Validation Summary for Admin -->
                        <?php if ($is_admin && !$is_submitted): ?>
                        <div class="validation-panel">
                            <div class="validation-header">
                                <i class="fas fa-clipboard-check fa-lg text-primary"></i>
                                <h4>Data Quality Validation</h4>
                            </div>
                            <div class="validation-summary">
                                <div class="validation-item <?php echo $saved_categories == $total_categories ? 'good' : 'warning'; ?>">
                                    <h5>Saved Sections</h5>
                                    <p><?php echo $saved_categories; ?> / <?php echo $total_categories; ?></p>
                                </div>
                                <div class="validation-item <?php echo $completely_filled_categories == $total_categories ? 'good' : 'warning'; ?>">
                                    <h5>Completely Filled Sections</h5>
                                    <p><?php echo $completely_filled_categories; ?> / <?php echo $total_categories; ?></p>
                                </div>
                                <div class="validation-item <?php echo ($total_categories - $completely_filled_categories) == 0 ? 'good' : 'error'; ?>">
                                    <h5>Sections Needing Attention</h5>
                                    <p><?php echo $total_categories - $completely_filled_categories; ?></p>
                                </div>
                            </div>
                            
                            <?php if ($total_categories - $completely_filled_categories > 0): ?>
                            <div class="validation-instructions">
                                <h5><i class="fas fa-exclamation-triangle"></i> Action Required</h5>
                                <p>Before final submission, please ensure:</p>
                                <ul>
                                    <li>All required fields are filled</li>
                                    <li>Optional fields have data or are marked with '-'</li>
                                    <li>Each section shows "Completely Filled" status</li>
                                </ul>
                                <p class="mb-0"><strong>Tip:</strong> Click on any section's "Validate & Save" button to check for empty fields.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Categories/Sections -->
                        <?php foreach($categories_with_params as $index => $category_data): 
                            $category = $category_data['category'];
                            $parameters = $category_data['parameters'];
                            $is_saved = isSectionSaved($month_id, $category['id'], $role_id, $is_admin);
                            $param_count = count($parameters);
                            
                            // Check if completely filled
                            $validation_result = validateSectionForSubmission($month_id, $category['id'], $is_admin);
                            $is_completely_filled = ($validation_result === true);
                        ?>
                        <div class="section-card <?php echo $is_saved ? ($is_completely_filled ? 'completely-filled' : 'saved') : 'unsaved'; ?>" 
                             data-section-id="<?php echo $category['id']; ?>"
                             data-section-saved="<?php echo $is_saved ? 'true' : 'false'; ?>"
                             style="animation-delay: <?php echo ($index * 0.1) + 0.2; ?>s;">
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
                                    <span class="status-badge status-<?php echo $is_saved ? ($is_completely_filled ? 'saved' : 'partially-saved') : 'pending'; ?>" 
                                          id="status-badge-<?php echo $category['id']; ?>"
                                          title="<?php echo $is_saved ? 'Last saved: ' . date('M d, Y H:i') : 'Not saved yet'; ?>">
                                        <?php if ($is_saved): ?>
                                            <?php if ($is_completely_filled): ?>
                                                <i class="fas fa-check-circle"></i> Completely Filled
                                            <?php else: ?>
                                                <i class="fas fa-exclamation-circle"></i> Needs Attention
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <i class="fas fa-clock"></i> Pending
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <?php if ($is_saved && !$is_completely_filled): ?>
                            <div class="section-validation-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>This section has empty fields. Please fill all fields or mark with '-' before final submission.</span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="parameter-grid">
                                <?php foreach($parameters as $param): 
                                    $is_multiline = in_array($param['id'], [221, 222, 172, 174, 305]);
                                    $current_value = isset($existing_data[$param['code']]) ? htmlspecialchars($existing_data[$param['code']]) : '';
                                    $is_empty = empty(trim($current_value));
                                    $is_saved_field = isset($existing_data[$param['code']]) && trim($existing_data[$param['code']]) !== '';
                                    $field_class = '';
                                    
                                    if ($param['required'] && $is_empty) {
                                        $field_class = 'missing-required';
                                    } elseif ($is_empty) {
                                        $field_class = 'empty-field';
                                    } elseif ($is_saved_field) {
                                        $field_class = 'saved-field';
                                    }
                                ?>
                                <div class="parameter-item <?php echo $param['required'] ? 'required' : ''; ?> <?php echo $is_multiline ? 'multiline' : ''; ?> <?php echo $field_class; ?>"
                                     data-param-id="<?php echo $param['id']; ?>">
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
                                            <div class="validation-hint">
                                                <?php if ($param['required']): ?>
                                                    <i class="fas fa-asterisk text-danger" style="font-size: 8px;"></i>
                                                    <span>Required field</span>
                                                <?php else: ?>
                                                    <i class="fas fa-info-circle text-info" style="font-size: 8px;"></i>
                                                    <span>Enter value or '-' if not applicable</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="saved-data-indicator">
                                        <i class="fas fa-check"></i> Saved
                                    </div>
                                    
                                    <?php if($is_multiline): ?>
                                        <textarea 
                                            name="data[<?php echo htmlspecialchars($param['code']); ?>]" 
                                            class="parameter-input parameter-textarea"
                                            rows="3"
                                            <?php echo $is_submitted ? 'readonly' : ''; ?>
                                            <?php echo $param['required'] ? 'required' : ''; ?>
                                            data-param-code="<?php echo htmlspecialchars($param['code']); ?>"
                                            data-param-required="<?php echo $param['required'] ? 'true' : 'false'; ?>"
                                            data-original-value="<?php echo htmlspecialchars($current_value); ?>"
                                            placeholder="<?php echo $param['required'] ? 'Required - enter value' : 'Enter value or "-" if not applicable'; ?>"><?php echo $current_value; ?></textarea>
                                    <?php else: ?>
                                        <input type="text" 
                                               name="data[<?php echo htmlspecialchars($param['code']); ?>]" 
                                               value="<?php echo $current_value; ?>"
                                               class="parameter-input"
                                               <?php echo $is_submitted ? 'readonly' : ''; ?>
                                               <?php echo $param['required'] ? 'required' : ''; ?>
                                               data-param-code="<?php echo htmlspecialchars($param['code']); ?>"
                                               data-param-required="<?php echo $param['required'] ? 'true' : 'false'; ?>"
                                               data-original-value="<?php echo htmlspecialchars($current_value); ?>"
                                               placeholder="<?php echo $param['required'] ? 'Required - enter value' : 'Enter value or "-" if not applicable'; ?>">
                                    <?php endif; ?>
                                    
                                    <div class="field-status-indicator <?php echo $is_empty ? ($param['required'] ? 'required-empty' : 'empty') : ($is_saved_field ? 'saved' : 'filled'); ?>"
                                         title="<?php echo $is_saved_field ? 'Data saved to database' : ($is_empty ? 'Empty field' : 'Filled'); ?>"></div>
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
                                        <i class="fas fa-clipboard-check"></i> Validate & Save Section
                                    </button>
                                    <div class="save-hint">
                                        <small><i class="fas fa-info-circle"></i> Data will be preserved. You can return and edit anytime.</small>
                                    </div>
                                    <div class="last-saved-time" id="last-saved-<?php echo $category['id']; ?>">
                                        <?php if ($is_saved): ?>
                                            Last saved: <?php echo date('M d, Y H:i'); ?>
                                        <?php endif; ?>
                                    </div>
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
                            <p>Complete all sections with proper validation to submit the final report</p>
                        </div>
                        
                        <div class="completion-progress">
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?php echo $total_categories > 0 ? ($completely_filled_categories / $total_categories * 100) : 0; ?>%">
                                    <?php echo $completely_filled_categories; ?> of <?php echo $total_categories; ?> Sections Completely Filled
                                </div>
                            </div>
                            <div class="progress-stats">
                                <span>Data Quality</span>
                                <span><?php echo $total_categories > 0 ? round(($completely_filled_categories / $total_categories * 100), 1) : 0; ?>% Complete</span>
                            </div>
                        </div>
                        
                        <?php if ($completely_filled_categories == $total_categories && $saved_categories == $total_categories): ?>
                            <form method="POST" id="final-submission-form">
                                <input type="hidden" name="action" value="submit_final">
                                <button type="submit" class="btn-submit-final" onclick="return confirmFinalSubmission();">
                                    <i class="fas fa-rocket"></i> Submit Final Report
                                </button>
                                <div class="submit-hint">
                                    <small><i class="fas fa-shield-alt"></i> All sections validated. Data will be locked after submission.</small>
                                </div>
                            </form>
                        <?php else: ?>
                            <button class="btn-submit-final" disabled>
                                <i class="fas fa-lock"></i> 
                                <?php if ($saved_categories < $total_categories): ?>
                                    Save All Sections First
                                <?php else: ?>
                                    Complete All Validations First
                                <?php endif; ?>
                            </button>
                            <div class="submit-hint">
                                <small><i class="fas fa-exclamation-circle"></i> 
                                <?php if ($saved_categories < $total_categories): ?>
                                    <?php echo $total_categories - $saved_categories; ?> sections still need to be saved
                                <?php else: ?>
                                    <?php echo $total_categories - $completely_filled_categories; ?> sections have empty fields that need attention
                                <?php endif; ?>
                                </small>
                            </div>
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
                        <?php if ($is_admin && !$is_submitted): ?>
                        <button class="btn-validate-all" onclick="validateAllSections()">
                            <i class="fas fa-clipboard-check"></i> Validate All Sections
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toast notification system
        function showToast(message, type = 'success', duration = 5000) {
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'}"></i>
                </div>
                <div class="toast-message">${message}</div>
                <button class="toast-close">&times;</button>
            `;
            
            document.body.appendChild(toast);
            
            // Remove existing toasts if too many
            const existingToasts = document.querySelectorAll('.toast-notification');
            if (existingToasts.length > 3) {
                existingToasts[0].remove();
            }
            
            // Auto remove after duration
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => toast.remove(), 300);
            }, duration);
            
            // Close button
            toast.querySelector('.toast-close').addEventListener('click', () => {
                toast.remove();
            });
            
            return toast;
        }
        
        // Show auto-save indicator
        function showAutoSaveIndicator() {
            const indicator = document.getElementById('autoSaveIndicator');
            indicator.classList.add('show');
            
            setTimeout(() => {
                indicator.classList.remove('show');
            }, 3000);
        }
        
        // Update progress indicator WITHOUT reloading page
        function updateProgressIndicator() {
            const sections = document.querySelectorAll('.section-card');
            const totalSections = sections.length;
            let savedSections = 0;
            let filledSections = 0;
            
            sections.forEach(section => {
                if (section.classList.contains('saved') || section.classList.contains('completely-filled')) {
                    savedSections++;
                }
                if (section.classList.contains('completely-filled')) {
                    filledSections++;
                }
            });
            
            // Update user info card
            const progressFill = document.querySelector('.progress-fill');
            const progressText = document.querySelector('.progress-label span:last-child');
            
            if (progressFill && progressText) {
                const percentage = totalSections > 0 ? (savedSections / totalSections * 100) : 0;
                progressFill.style.width = percentage + '%';
                progressText.textContent = `${savedSections} / ${totalSections}`;
            }
            
            // Update final submission progress if exists
            const finalProgressFill = document.querySelector('.progress-bar-fill');
            const finalProgressText = document.querySelector('.progress-stats span:last-child');
            
            if (finalProgressFill && finalProgressText) {
                const filledPercentage = totalSections > 0 ? (filledSections / totalSections * 100) : 0;
                finalProgressFill.style.width = filledPercentage + '%';
                finalProgressFill.textContent = `${filledSections} of ${totalSections} Sections Completely Filled`;
                finalProgressText.textContent = `${filledPercentage.toFixed(1)}% Complete`;
            }
            
            // Update validation panel if exists
            const validationItems = document.querySelectorAll('.validation-item');
            if (validationItems.length >= 3) {
                validationItems[0].querySelector('p').textContent = `${savedSections} / ${totalSections}`;
                validationItems[1].querySelector('p').textContent = `${filledSections} / ${totalSections}`;
                validationItems[2].querySelector('p').textContent = `${totalSections - filledSections}`;
                
                // Update classes based on status
                validationItems[0].className = `validation-item ${savedSections === totalSections ? 'good' : 'warning'}`;
                validationItems[1].className = `validation-item ${filledSections === totalSections ? 'good' : 'warning'}`;
                validationItems[2].className = `validation-item ${filledSections === totalSections ? 'good' : 'error'}`;
            }
            
            return { saved: savedSections, filled: filledSections, total: totalSections };
        }
        
        // Validate a single section before saving
        function validateSection(sectionId) {
            const section = document.querySelector(`[data-section-id="${sectionId}"]`);
            const inputs = section.querySelectorAll('input[name^="data["], textarea[name^="data["]');
            
            let hasRequiredEmpty = false;
            let hasEmptyFields = false;
            const emptyFields = [];
            const requiredEmptyFields = [];
            
            inputs.forEach(input => {
                const value = input.value.trim();
                const isRequired = input.hasAttribute('required');
                const paramCode = input.dataset.paramCode;
                
                if (isRequired && !value) {
                    hasRequiredEmpty = true;
                    requiredEmptyFields.push(paramCode);
                    input.closest('.parameter-item').classList.add('missing-required');
                } else if (!value) {
                    hasEmptyFields = true;
                    emptyFields.push(paramCode);
                    input.closest('.parameter-item').classList.add('empty-field');
                } else {
                    input.closest('.parameter-item').classList.remove('missing-required', 'empty-field');
                }
                
                // Update field status indicator
                const indicator = input.closest('.parameter-item').querySelector('.field-status-indicator');
                if (indicator) {
                    indicator.className = 'field-status-indicator ';
                    if (!value) {
                        indicator.classList.add(isRequired ? 'required-empty' : 'empty');
                    } else {
                        indicator.classList.add('filled');
                    }
                }
            });
            
            return {
                isValid: !hasRequiredEmpty,
                hasRequiredEmpty,
                hasEmptyFields,
                requiredEmptyFields,
                emptyFields
            };
        }
        
        // Handle section form submissions WITHOUT page reload
        document.addEventListener('DOMContentLoaded', function() {
            const sectionForms = document.querySelectorAll('.save-section-form');
            
            sectionForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const dataObj = Object.fromEntries(formData.entries());
                    const categoryId = dataObj.category_id;
                    const section = document.getElementById('form-' + categoryId).closest('.section-card');
                    const statusBadge = section.querySelector('.status-badge');
                    const submitBtn = this.querySelector('.btn-save-section');
                    const sectionName = section.querySelector('.section-title').textContent.trim().split('\n')[0];
                    const lastSavedTime = document.getElementById('last-saved-' + categoryId);
                    
                    // Validate section
                    const validation = validateSection(categoryId);
                    
                    if (!validation.isValid) {
                        showToast(`Please fill all required fields in "${sectionName}"`, 'error', 6000);
                        
                        // Scroll to first empty required field
                        const firstEmpty = section.querySelector('.missing-required');
                        if (firstEmpty) {
                            firstEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            const input = firstEmpty.querySelector('input, textarea');
                            if (input) input.focus();
                        }
                        return;
                    }
                    
                    // Show warning for empty optional fields
                    if (validation.hasEmptyFields) {
                        showToast(`Empty optional fields in "${sectionName}" will be marked with '-'`, 'warning', 4000);
                    }
                    
                    // Add saving state
                    const originalBtnText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    submitBtn.disabled = true;
                    
                    // Auto-fill empty fields with dash in the UI
                    const formDataToSend = new FormData(this);
                    const dataInputs = section.querySelectorAll('input[name^="data["], textarea[name^="data["]');
                    
                    dataInputs.forEach(input => {
                        if (!input.value.trim()) {
                            formDataToSend.set(input.name, '-');
                            // Update the input value in the UI
                            input.value = '-';
                        }
                    });
                    
                    // Save to database
                    fetch('', {
                        method: 'POST',
                        body: formDataToSend
                    })
                    .then(response => response.text())
                    .then((html) => {
                        // Check if there's a success message in the response
                        if (html.includes('Section data saved successfully') || html.includes('Success!')) {
                            // Update section status
                            const allFieldsFilled = !validation.hasEmptyFields && !validation.hasRequiredEmpty;
                            section.classList.remove('unsaved', 'saved');
                            section.classList.add(allFieldsFilled ? 'completely-filled' : 'saved');
                            
                            // Update status badge
                            statusBadge.className = 'status-badge status-saved save-pulse';
                            statusBadge.innerHTML = allFieldsFilled ? 
                                '<i class="fas fa-check-circle"></i> Completely Filled' : 
                                '<i class="fas fa-check-circle"></i> Saved';
                            
                            // Update timestamp in status badge
                            const now = new Date();
                            const formattedTime = now.toLocaleString('en-US', {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            });
                            statusBadge.title = `Last saved: ${formattedTime}`;
                            
                            // Update last saved time display
                            if (lastSavedTime) {
                                lastSavedTime.textContent = `Last saved: ${formattedTime}`;
                                lastSavedTime.style.display = 'block';
                            }
                            
                            // Mark all fields as saved
                            dataInputs.forEach(input => {
                                const paramItem = input.closest('.parameter-item');
                                paramItem.classList.add('saved-field');
                                
                                // Update field status indicator
                                const indicator = paramItem.querySelector('.field-status-indicator');
                                if (indicator) {
                                    indicator.className = 'field-status-indicator saved';
                                    indicator.title = 'Data saved to database';
                                }
                                
                                // Store current value as original value
                                input.dataset.originalValue = input.value;
                            });
                            
                            // Update progress indicators
                            updateProgressIndicator();
                            
                            // Show success notifications
                            showToast(`"${sectionName}" saved successfully! Data is preserved.`, 'success');
                            showAutoSaveIndicator();
                            
                            // Store saved state in localStorage
                            localStorage.setItem(`section_${categoryId}_saved`, 'true');
                            localStorage.setItem(`section_${categoryId}_timestamp`, now.toISOString());
                            localStorage.setItem(`section_${categoryId}_name`, sectionName);
                            
                        } else {
                            // Check for error message
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = html;
                            const errorDiv = tempDiv.querySelector('.alert-danger');
                            if (errorDiv) {
                                const errorText = errorDiv.textContent.trim();
                                showToast(`Error saving "${sectionName}": ${errorText}`, 'error');
                            } else {
                                showToast(`Unknown error occurred while saving "${sectionName}"`, 'error');
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Save error:', error);
                        showToast(`Error saving "${sectionName}". Please try again.`, 'error');
                    })
                    .finally(() => {
                        // Re-enable button
                        submitBtn.innerHTML = originalBtnText;
                        submitBtn.disabled = false;
                    });
                });
            });
            
            // Real-time field validation
            const inputs = document.querySelectorAll('input[name^="data["], textarea[name^="data["]');
            inputs.forEach(input => {
                // Check if field has saved data on page load
                const paramItem = input.closest('.parameter-item');
                if (paramItem && input.value.trim() && input.value !== '-') {
                    const indicator = paramItem.querySelector('.field-status-indicator');
                    if (indicator) {
                        indicator.classList.add('filled');
                    }
                }
                
                input.addEventListener('input', function() {
                    const value = this.value.trim();
                    const isRequired = this.hasAttribute('required');
                    const paramItem = this.closest('.parameter-item');
                    
                    // Remove error styling when user starts typing
                    paramItem.classList.remove('missing-required', 'empty-field');
                    
                    // Update field status indicator
                    const indicator = paramItem.querySelector('.field-status-indicator');
                    if (indicator) {
                        indicator.className = 'field-status-indicator ';
                        if (!value) {
                            indicator.classList.add(isRequired ? 'required-empty' : 'empty');
                        } else {
                            indicator.classList.add('filled');
                        }
                    }
                });
                
                // Add blur validation
                input.addEventListener('blur', function() {
                    const value = this.value.trim();
                    const isRequired = this.hasAttribute('required');
                    const paramItem = this.closest('.parameter-item');
                    
                    if (isRequired && !value) {
                        paramItem.classList.add('missing-required');
                    } else if (!value) {
                        paramItem.classList.add('empty-field');
                    }
                });
            });
            
            // Auto-expand textareas
            const textareas = document.querySelectorAll('.parameter-textarea');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                
                if (textarea.value.trim()) {
                    setTimeout(() => {
                        textarea.style.height = 'auto';
                        textarea.style.height = (textarea.scrollHeight) + 'px';
                    }, 100);
                }
            });
            
            // Load saved sections state from localStorage
            loadSavedSectionsState();
        });
        
        // Load saved sections state from localStorage
        function loadSavedSectionsState() {
            const sections = document.querySelectorAll('.section-card');
            sections.forEach(section => {
                const sectionId = section.dataset.sectionId;
                const isSaved = localStorage.getItem(`section_${sectionId}_saved`) === 'true';
                const savedTimestamp = localStorage.getItem(`section_${sectionId}_timestamp`);
                const sectionName = localStorage.getItem(`section_${sectionId}_name`);
                
                if (isSaved && savedTimestamp) {
                    // Update status badge
                    const statusBadge = section.querySelector('.status-badge');
                    if (statusBadge) {
                        const timestamp = new Date(savedTimestamp);
                        const formattedTime = timestamp.toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        statusBadge.title = `Last saved: ${formattedTime}`;
                        statusBadge.className = 'status-badge status-saved';
                        statusBadge.innerHTML = '<i class="fas fa-check-circle"></i> Saved';
                    }
                    
                    // Update last saved time display
                    const lastSavedTime = document.getElementById('last-saved-' + sectionId);
                    if (lastSavedTime && savedTimestamp) {
                        const timestamp = new Date(savedTimestamp);
                        const formattedTime = timestamp.toLocaleString('en-US', {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        lastSavedTime.textContent = `Last saved: ${formattedTime}`;
                        lastSavedTime.style.display = 'block';
                    }
                }
            });
        }
        
        // Final submission confirmation
        window.confirmFinalSubmission = function() {
            return confirm(`⚠️ FINAL SUBMISSION CONFIRMATION\n\nAre you sure you want to submit the final report?\n\n• All data will be verified\n• Empty fields have been marked with '-'\n• The data will be locked and cannot be edited\n• This action requires administrative privileges\n\nClick OK to proceed with final submission.`);
        };
        
        // Validate all sections function
        window.validateAllSections = function() {
            const sections = document.querySelectorAll('.section-card');
            let issuesFound = 0;
            const sectionsWithIssues = [];
            
            sections.forEach(section => {
                const sectionId = section.dataset.sectionId;
                const validation = validateSection(sectionId);
                const sectionName = section.querySelector('.section-title').textContent.trim().split('\n')[0];
                
                if (!validation.isValid || validation.hasEmptyFields) {
                    issuesFound++;
                    sectionsWithIssues.push(sectionName);
                    
                    // Highlight section with issues
                    section.style.boxShadow = '0 0 0 2px rgba(220, 53, 69, 0.3)';
                    setTimeout(() => {
                        section.style.boxShadow = '';
                    }, 3000);
                }
            });
            
            if (issuesFound > 0) {
                const sectionsList = sectionsWithIssues.join(', ');
                showToast(`Found ${issuesFound} section(s) that need attention: ${sectionsList}`, 'warning', 5000);
            } else {
                showToast('All sections are properly filled! Ready for final submission.', 'success');
            }
        };
        
        // Show unsaved changes warning
        window.addEventListener('beforeunload', function(e) {
            const hasUnsavedChanges = document.querySelectorAll('input:not([readonly]), textarea:not([readonly])')
                .some(input => {
                    const currentValue = input.value.trim();
                    const originalValue = input.dataset.originalValue || '';
                    return currentValue !== originalValue;
                });
            
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                return e.returnValue;
            }
        });
        
        // Auto-save reminder (every 2 minutes)
        setInterval(() => {
            const hasUnsavedChanges = document.querySelectorAll('input:not([readonly]), textarea:not([readonly])')
                .some(input => {
                    const currentValue = input.value.trim();
                    const originalValue = input.dataset.originalValue || '';
                    return currentValue !== originalValue && currentValue !== '';
                });
            
            if (hasUnsavedChanges) {
                showToast('You have unsaved changes. Click "Save Section" to preserve your work.', 'warning', 3000);
            }
        }, 120000); // 2 minutes
    </script>
</body>
</html>
<?php
if (isset($conn)) {
    $conn->close();
}