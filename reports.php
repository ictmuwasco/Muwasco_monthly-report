<?php
// report.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Include Composer autoloader for TCPDF
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Handle case where vendor folder doesn't exist
    die('TCPDF library not found. Please install via composer: composer require tecnickcom/tcpdf');
}

require_once 'db.php';

// Function to get user info (similar to what you have in other files)
function getUserInfo($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT u.*, r.name as role, r.description as role_description 
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

// Get user info
$user_info = getUserInfo($conn, $_SESSION['user_id']);

// Set session role if not set
if (!isset($_SESSION['role']) && isset($user_info['role'])) {
    $_SESSION['role'] = $user_info['role'];
}

// Get available months (only submitted ones) - ORDER BY month_year ASC for correct chronological order
$months_query = "SELECT * FROM months WHERE status = 'submitted' ORDER BY month_year ASC";
$months_result = $conn->query($months_query);
$available_months = [];
while ($row = $months_result->fetch_assoc()) {
    $available_months[] = $row;
}

// Process form submission
$selected_months = [];
$report_data = [];
$preview_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $selected_months = $_POST['months'] ?? [];
    
    if (count($selected_months) >= 3) {
        // Sort selected months chronologically
        usort($selected_months, function($a, $b) use ($available_months) {
            $dateA = null;
            $dateB = null;
            foreach ($available_months as $m) {
                if ($m['id'] == $a) $dateA = strtotime($m['month_year'] . '-01');
                if ($m['id'] == $b) $dateB = strtotime($m['month_year'] . '-01');
            }
            return $dateA - $dateB;
        });
        
        // Get report data for selected months
        $placeholders = str_repeat('?,', count($selected_months) - 1) . '?';
        $query = "
            SELECT 
                pc.name as category_name,
                pc.display_order,
                p.code,
                p.label,
                p.unit,
                p.data_type,
                m.month_year,
                m.id as month_id,
                md.value
            FROM monthly_data md
            JOIN parameters p ON md.parameter_id = p.id
            JOIN parameter_categories pc ON p.category_id = pc.id
            JOIN months m ON md.month_id = m.id
            WHERE m.id IN ($placeholders)
            ORDER BY pc.display_order, p.code
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param(str_repeat('i', count($selected_months)), ...$selected_months);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $report_data[$row['category_name']][$row['code']][$row['month_id']] = $row;
        }
        
        // Format data for preview
        $preview_data = formatPreviewData($report_data, $selected_months, $available_months, $conn);
    }
}

// Export functionality
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $months_param = $_GET['months'] ?? '';
    $month_ids = explode(',', $months_param);
    
    // Filter out empty values
    $month_ids = array_filter($month_ids, 'strlen');
    
    if (count($month_ids) >= 3) {
        // Sort month IDs chronologically for export
        $month_details = [];
        foreach ($month_ids as $id) {
            $month_query = "SELECT id, month_year FROM months WHERE id = ?";
            $stmt = $conn->prepare($month_query);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $month_details[] = $row;
            }
        }
        usort($month_details, function($a, $b) {
            return strtotime($a['month_year'] . '-01') - strtotime($b['month_year'] . '-01');
        });
        $sorted_month_ids = array_column($month_details, 'id');
        
        if ($export_type === 'word') {
            exportToWord($conn, $sorted_month_ids);
        } elseif ($export_type === 'pdf') {
            exportToPDF($conn, $sorted_month_ids);
        }
    }
    exit;
}

function formatPreviewData($report_data, $selected_month_ids, $available_months, $conn) {
    $preview = [];
    
    // Get category display order from database
    $categories_query = "SELECT name, display_order FROM parameter_categories ORDER BY display_order";
    $categories_result = $conn->query($categories_query);
    $categories = [];
    while ($row = $categories_result->fetch_assoc()) {
        $categories[$row['name']] = $row['display_order'];
    }
    
    // Sort categories by display_order
    uksort($report_data, function($a, $b) use ($categories) {
        $orderA = $categories[$a] ?? 999;
        $orderB = $categories[$b] ?? 999;
        return $orderA - $orderB;
    });
    
    // Build preview data
    foreach ($report_data as $category => $parameters) {
        $preview[$category] = [];
        foreach ($parameters as $code => $month_data) {
            // Get the first available data for label and unit
            $first_data = reset($month_data);
            $row_data = [
                'label' => $first_data['label'] ?? '',
                'unit' => $first_data['unit'] ?? '',
                'data_type' => $first_data['data_type'] ?? 'text'
            ];
            
            // Add values for each selected month
            foreach ($selected_month_ids as $month_id) {
                $value = isset($month_data[$month_id]) ? $month_data[$month_id]['value'] : '-';
                $row_data[$month_id] = $value;
            }
            
            $preview[$category][$code] = $row_data;
        }
    }
    
    return $preview;
}

function getExportData($conn, $month_ids) {
    $placeholders = str_repeat('?,', count($month_ids) - 1) . '?';
    $query = "
        SELECT 
            pc.name as category_name,
            pc.display_order,
            p.code,
            p.label,
            p.unit,
            p.data_type,
            m.month_year,
            m.id as month_id,
            md.value
        FROM monthly_data md
        JOIN parameters p ON md.parameter_id = p.id
        JOIN parameter_categories pc ON p.category_id = pc.id
        JOIN months m ON md.month_id = m.id
        WHERE m.id IN ($placeholders)
        ORDER BY pc.display_order, p.code
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('i', count($month_ids)), ...$month_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['category_name']][$row['code']][$row['month_id']] = $row;
    }
    
    return $data;
}

function exportToWord($conn, $month_ids) {
    $data = getExportData($conn, $month_ids);
    
    // Get month labels in chronological order
    $month_labels = [];
    $months_query = "SELECT id, month_year FROM months WHERE id IN (" . implode(',', $month_ids) . ") ORDER BY month_year ASC";
    $result = $conn->query($months_query);
    while ($row = $result->fetch_assoc()) {
        $month_labels[$row['id']] = date('F Y', strtotime($row['month_year'] . '-01'));
    }
    
    header("Content-Type: application/vnd.ms-word");
    header("Content-Disposition: attachment; filename=muwasco_monitoring_report.doc");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo generateWordContent($data, $month_ids, $month_labels, $conn);
    exit;
}

function exportToPDF($conn, $month_ids) {
    $data = getExportData($conn, $month_ids);
    
    // Get month labels in chronological order
    $month_labels = [];
    $months_query = "SELECT id, month_year FROM months WHERE id IN (" . implode(',', $month_ids) . ") ORDER BY month_year ASC";
    $result = $conn->query($months_query);
    while ($row = $result->fetch_assoc()) {
        $month_labels[$row['id']] = date('F Y', strtotime($row['month_year'] . '-01'));
    }
    
    try {
        // Create new PDF document
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('MUWASCO Monitoring System');
        $pdf->SetAuthor('MUWASCO');
        $pdf->SetTitle('Water Monitoring Report');
        $pdf->SetSubject('Monthly Water Monitoring Data');
        
        // Set margins
        $pdf->SetMargins(10, 15, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Add content
        $html = generatePDFContent($data, $month_ids, $month_labels, $conn);
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Close and output PDF document
        $pdf->Output('muwasco_monitoring_report.pdf', 'D');
        exit;
    } catch (Exception $e) {
        die("Error generating PDF: " . $e->getMessage());
    }
}

function generateWordContent($data, $month_ids, $month_labels, $conn) {
    $content = "<html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; font-size: 10pt; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            th, td { border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .header { text-align: center; margin-bottom: 20px; }
            .category-header { background-color: #e6e6e6; font-weight: bold; padding: 8px; text-align: center; }
            .parameter-label { font-weight: bold; }
            .parameter-code { font-weight: bold; color: #0066cc; }
            .signature-section { margin-top: 30px; margin-bottom: 30px; }
        </style>
    </head>
    <body>";
    
    $content .= "<div class='header'>
        <h2>ATHI WATER WORKS DEVELOPMENT AGENCY</h2>
        <h3>MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h3>
        <h4>(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h4>
        <p><strong>WSP's NAME: MUWASCO - DATE OF MONITORING: " . date('F Y') . "</strong></p>
    </div>";
    
    $categories_displayed = 0;
    foreach ($data as $category => $parameters) {
        if (empty($parameters)) continue;
        
        $content .= "<div class='category-header'>" . strtoupper(str_replace('_', ' ', $category)) . "</div>";
        $content .= "<table>
            <tr>
                <th width='5%'><strong>NO</strong></th>
                <th width='10%'><strong>CODE</strong></th>
                <th width='35%'><strong>PARAMETERS</strong></th>";
        
        // Add month columns in chronological order
        foreach ($month_ids as $month_id) {
            $content .= "<th width='" . (50/count($month_ids)) . "%'><strong>" . strtoupper($month_labels[$month_id]) . "</strong></th>";
        }
        
        $content .= "</tr>";
        
        $counter = 1;
        foreach ($parameters as $code => $month_data) {
            $first_data = reset($month_data);
            $content .= "<tr>
                <td>" . $counter . "</td>
                <td class='parameter-code'>" . htmlspecialchars($code) . "</td>
                <td class='parameter-label'>" . htmlspecialchars($first_data['label']) . "</td>";
            
            foreach ($month_ids as $month_id) {
                $value = isset($month_data[$month_id]) ? $month_data[$month_id]['value'] : '-';
                $content .= "<td>" . htmlspecialchars($value) . "</td>";
            }
            
            $content .= "</tr>";
            $counter++;
        }
        
        $content .= "</table>";
        
        $categories_displayed++;
        
        // Add Technical Manager signature after Water Quality (assuming it's the 5th category)
        if ($categories_displayed == 5) {
            $content .= "<div class='signature-section'>
                <p><strong>Above data</strong></p>
                <p><strong>verified by:</strong></p>
                <p><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                <p>SIGN...............................................................DATE....................................................................</p>
            </div>";
        }
    }
    
    // Add final verification section
    $content .= "<div class='signature-section'>
        <p><strong>data verified by:</strong></p>
        <p><strong>JOSEPH MAINA (CMT) - COMMERCIAL MANAGER</strong></p>
        <p>Sign.......................................................................Date....................................................................</p>
        <br>
        <p><strong>ENG. D. NG'ANG'A - MANAGING DIRECTOR</strong></p>
        <p>Sign.......................................................................Date....................................................................</p>
    </div>";
    
    $content .= "</body></html>";
    return $content;
}

function generatePDFContent($data, $month_ids, $month_labels, $conn) {
    $html = '<div style="text-align: center;">
        <h2 style="margin-bottom: 5px;">ATHI WATER WORKS DEVELOPMENT AGENCY</h2>
        <h3 style="margin-bottom: 5px;">MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h3>
        <h4 style="margin-bottom: 5px;">(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h4>
        <p style="margin-bottom: 15px;"><strong>WSP\'s NAME: MUWASCO - DATE OF MONITORING: ' . date('F Y') . '</strong></p>
    </div>';
    
    $categories_displayed = 0;
    foreach ($data as $category => $parameters) {
        if (empty($parameters)) continue;
        
        // Add category header
        $html .= '<div style="background-color: #e6e6e6; font-weight: bold; padding: 8px; text-align: center; margin-bottom: 10px; border: 1px solid #000;">
            <strong>' . strtoupper(str_replace('_', ' ', $category)) . '</strong>
        </div>';
        
        $html .= '<table border="1" cellpadding="4" style="border-collapse:collapse; width:100%; font-size:8pt; margin-bottom:10px;">';
        $html .= '<tr style="background-color:#f2f2f2;">
            <th width="5%"><strong>NO</strong></th>
            <th width="10%"><strong>CODE</strong></th>
            <th width="35%"><strong>PARAMETERS</strong></th>';
        
        // Add month columns in chronological order
        foreach ($month_ids as $month_id) {
            $html .= '<th width="' . (50/count($month_ids)) . '%"><strong>' . strtoupper($month_labels[$month_id]) . '</strong></th>';
        }
        
        $html .= '</tr>';
        
        $counter = 1;
        foreach ($parameters as $code => $month_data) {
            $first_data = reset($month_data);
            $html .= '<tr>
                <td>' . $counter . '</td>
                <td><strong style="color: #0066cc;">' . htmlspecialchars($code) . '</strong></td>
                <td><strong>' . htmlspecialchars($first_data['label']) . '</strong></td>';
            
            foreach ($month_ids as $month_id) {
                $value = isset($month_data[$month_id]) ? $month_data[$month_id]['value'] : '-';
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            
            $html .= '</tr>';
            $counter++;
        }
        
        $html .= '</table>';
        
        $categories_displayed++;
        
        // Add Technical Manager signature after Water Quality
        if ($categories_displayed == 5) {
            $html .= '<div style="margin-top: 20px; margin-bottom: 20px;">
                <p><strong>Above data</strong></p>
                <p><strong>verified by:</strong></p>
                <p><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                <p>SIGN..............................................DATE....................................................</p>
            </div>';
        }
    }
    
    // Add final verification section
    $html .= '<div style="margin-top: 20px;">
        <p><strong>data verified by:</strong></p>
        <p><strong>JOSEPH MAINA (CMT) - COMMERCIAL MANAGER</strong></p>
        <p>Sign..............................................Date....................................................</p>
        <br>
        <p><strong>ENG. D. NG\'ANG\'A - MANAGING DIRECTOR</strong></p>
        <p>Sign..............................................Date....................................................</p>
    </div>';
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MUWASCO Monitoring Report</title>
    
    <!-- External Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="style.css">
    
</head>
<body>
    <!-- Navigation -->
    <?php 
    // Check if nav_bar.php exists and include it
    if (file_exists('nav_bar.php')) {
        include 'nav_bar.php'; 
    }
    ?>

    <div class="main-container">
        <div class="main-content">
            <div class="page-content">
                <div class="reports-dashboard-container">
                    <div class="reports-main-container">
                        <div class="reports-header-section">
                            <h1>ATHI WATER WORKS DEVELOPMENT AGENCY</h1>
                            <h2>MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h2>
                            <h3>(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h3>
                            <div class="mt-4">
                                <span class="badge bg-light text-dark fs-6">
                                    <i class="bi bi-calendar-check me-2"></i>
                                    <?php echo count($available_months); ?> Submitted Months Available
                                </span>
                            </div>
                        </div>

                        <div class="reports-form-section no-print">
                            <form method="POST" id="reportForm">
                                <div class="form-group">
                                    <label class="reports-form-label">
                                        <i class="bi bi-calendar-month me-2"></i>
                                        Select Months for Report (Minimum 3 months required):
                                    </label>
                                    <div class="reports-months-grid" id="monthsGrid">
                                        <?php foreach ($available_months as $month): ?>
                                            <label class="report-month-checkbox" id="month-<?= $month['id'] ?>">
                                                <input type="checkbox" name="months[]" value="<?= $month['id'] ?>" 
                                                    <?= in_array($month['id'], $selected_months) ? 'checked' : '' ?>>
                                                <i class="bi bi-calendar-check me-2"></i>
                                                <?= date('F Y', strtotime($month['month_year'] . '-01')) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($selected_months) < 3): ?>
                                    <div class="report-alert-message report-alert-error">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        <strong>Selection Required:</strong> Please select at least 3 months to generate the report.
                                    </div>
                                <?php endif; ?>
                                
                                <div class="report-btn-group">
                                    <button type="submit" name="generate_report" class="report-generate-btn" id="generateBtn">
                                        <i class="bi bi-bar-chart me-2"></i>Generate Report Preview
                                    </button>
                                </div>
                            </form>
                        </div>

                        <?php if (!empty($preview_data) && count($selected_months) >= 3): ?>
                            <div class="report-alert-message report-alert-success no-print">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <strong>Report Generated Successfully!</strong> 
                                Selected months: 
                                <?php 
                                $month_labels = [];
                                foreach ($selected_months as $id) {
                                    foreach ($available_months as $m) {
                                        if ($m['id'] == $id) {
                                            $month_labels[] = date('F Y', strtotime($m['month_year'] . '-01'));
                                            break;
                                        }
                                    }
                                }
                                echo implode(', ', $month_labels);
                                ?>
                            </div>

                            <div class="report-export-options no-print">
                                <h3 class="mb-4">
                                    <i class="bi bi-download me-2"></i>
                                    Export Options
                                </h3>
                                <div class="report-export-buttons">
                                    <a href="?export=word&months=<?= implode(',', $selected_months) ?>" 
                                       class="report-export-btn report-floating">
                                        <i class="bi bi-file-word me-2"></i>Export to Word
                                    </a>
                                    <a href="?export=pdf&months=<?= implode(',', $selected_months) ?>" 
                                       class="report-export-btn report-export-btn-pdf report-floating" style="animation-delay: 0.2s;">
                                        <i class="bi bi-file-pdf me-2"></i>Export to PDF
                                    </a>
                                    <button type="button" onclick="window.print()" class="report-export-btn report-export-btn-print">
                                        <i class="bi bi-printer me-2"></i>Print Report
                                    </button>
                                </div>
                                <p class="text-muted mt-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Generated on: <?= date('F j, Y g:i A') ?>
                                </p>
                            </div>

                            <div class="report-preview-section">
                                <div class="text-center mb-4">
                                    <h2 class="report-title">
                                        <i class="bi bi-droplet me-2"></i>WATER MONITORING REPORT
                                    </h2>
                                    <p class="text-muted report-subtitle">
                                        WSP's NAME: MUWASCO - DATE OF MONITORING: <?= date('F Y') ?>
                                    </p>
                                </div>

                                <?php 
                                $categories_displayed = 0;
                                foreach ($preview_data as $category => $parameters): 
                                    if (empty($parameters)) continue;
                                    $categories_displayed++;
                                ?>
                                    <div class="report-category-header">
                                        <?= strtoupper(str_replace('_', ' ', $category)) ?>
                                    </div>
                                    
                                    <table class="report-preview-table">
                                        <thead>
                                            <tr>
                                                <th class="column-no">NO</th>
                                                <th class="column-code">CODE</th>
                                                <th class="column-parameters">PARAMETERS</th>
                                                <?php
                                                foreach ($selected_months as $month_id):
                                                    $month_label = '';
                                                    foreach ($available_months as $m) {
                                                        if ($m['id'] == $month_id) {
                                                            $month_label = date('F Y', strtotime($m['month_year'] . '-01'));
                                                            break;
                                                        }
                                                    }
                                                    echo "<th class=\"column-month\">" . strtoupper($month_label) . "</th>";
                                                endforeach;
                                                ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $counter = 1;
                                            foreach ($parameters as $code => $row_data):
                                            ?>
                                                <tr>
                                                    <td class="report-parameter-number"><strong><?= $counter ?></strong></td>
                                                    <td class="report-parameter-code-cell">
                                                        <div class="report-parameter-code">
                                                            <span class="badge bg-primary"><?= $code ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="report-parameter-label-cell">
                                                        <div class="report-parameter-label">
                                                            <?= htmlspecialchars($row_data['label']) ?>
                                                        </div>
                                                    </td>
                                                    <?php foreach ($selected_months as $month_id): 
                                                        $value = isset($row_data[$month_id]) ? $row_data[$month_id] : '-';
                                                    ?>
                                                        <td class="report-parameter-value"><?= htmlspecialchars($value) ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php $counter++; endforeach; ?>
                                        </tbody>
                                    </table>

                                    <!-- Technical Manager signature after Water Quality -->
                                    <?php if ($categories_displayed == 5 && $category == 'Water Quality'): ?>
                                        <div class="report-signature-section report-technical-signature">
                                            <h5>
                                                <i class="bi bi-pen me-2"></i>
                                                Technical Manager Verification
                                            </h5>
                                            <p><strong>Above data</strong></p>
                                            <p><strong>verified by:</strong></p>
                                            <p class="h5"><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                                            <p class="mt-3">SIGN...............................................................DATE....................................................................</p>
                                        </div>
                                    <?php endif; ?>

                                <?php endforeach; ?>

                                <!-- Final verification section -->
                                <div class="report-signature-section report-final-signature">
                                    <h5>
                                        <i class="bi bi-shield-check me-2"></i>
                                        Final Verification & Authorization
                                    </h5>
                                    <div class="row mt-4">
                                        <div class="col-md-6">
                                            <p><strong>data verified by:</strong></p>
                                            <p class="h5"><strong>JOSEPH MAINA (CMT)</strong></p>
                                            <p class="text-muted">COMMERCIAL MANAGER</p>
                                            <p>Sign.......................................................................Date....................................................................</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>authorized by:</strong></p>
                                            <p class="h5"><strong>ENG. D. NG'ANG'A</strong></p>
                                            <p class="text-muted">MANAGING DIRECTOR</p>
                                            <p>Sign.......................................................................Date....................................................................</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('input[name="months[]"]');
            const generateBtn = document.getElementById('generateBtn');
            
            // Update checkbox styling
            function updateCheckboxStyles() {
                const selectedCount = document.querySelectorAll('input[name="months[]"]:checked').length;
                
                checkboxes.forEach(checkbox => {
                    const label = checkbox.closest('.report-month-checkbox');
                    if (checkbox.checked) {
                        label.classList.add('checked');
                    } else {
                        label.classList.remove('checked');
                    }
                });
                
                // Update button state and text
                generateBtn.disabled = selectedCount < 3;
                if (selectedCount < 3) {
                    generateBtn.innerHTML = '<i class="bi bi-bar-chart me-2"></i>Select ' + (3 - selectedCount) + ' more month(s)';
                    generateBtn.classList.remove('pulse');
                } else {
                    generateBtn.innerHTML = '<i class="bi bi-bar-chart me-2"></i>Generate Report (' + selectedCount + ' months)';
                    generateBtn.classList.add('pulse');
                }
            }
            
            // Initialize styles
            updateCheckboxStyles();
            
            // Add event listeners
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateCheckboxStyles);
            });
            
            // Add click animation to checkboxes
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('click', function() {
                    const label = this.closest('.report-month-checkbox');
                    label.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        label.style.transform = '';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>