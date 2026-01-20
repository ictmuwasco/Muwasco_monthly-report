<?php
// report.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include Composer autoloader for TCPDF
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';
require_once 'auth_functions.php';

requireLogin();

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
                if ($m['id'] == $a) $dateA = strtotime($m['month_year']);
                if ($m['id'] == $b) $dateB = strtotime($m['month_year']);
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
        $preview_data = formatPreviewData($report_data, $selected_months, $available_months);
    }
}

// Export functionality
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $months_param = $_GET['months'] ?? '';
    $month_ids = explode(',', $months_param);
    
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
        return strtotime($a['month_year']) - strtotime($b['month_year']);
    });
    $sorted_month_ids = array_column($month_details, 'id');
    
    if (count($sorted_month_ids) >= 3) {
        if ($export_type === 'word') {
            exportToWord($conn, $sorted_month_ids);
        } elseif ($export_type === 'pdf') {
            exportToPDF($conn, $sorted_month_ids);
        }
    }
    exit;
}

function formatPreviewData($report_data, $selected_month_ids, $available_months) {
    $preview = [];
    
    // Define the exact structure from your document
    $structure = [
        'Population Coverage' => ['1a', '1b', '1c', '1d'],
        'Water Connections' => ['2a', '2b', '2c', '2d', '2e', '2f', '2g'],
        'Breakdowns & Maintenance' => ['3a', '3b', '3c','3ci','3cii','3ciii', '3d', '3e'],
        'Consumer Complaints' => ['4a', '4b', '4c'],
        'Water Quality' => ['5a','5ai', '5aii','5aiii','5aiv', '5b','5c', '5ci', '5cii','5ciii', '5d', '5e', '5f', '5g', '5h', '5i', '5j', '5k', '5l', '5m', '5n'],
        'Staff & Operations' => ['6a', '6b', '6c', '6d', '6e'],
        'Billing & Revenue' => ['7a', '7b', '7c', '7d', '7e', '7f', '7g', '7h', '7i', '7j', '7k', '7l', '7m', '7n', '7o', '7p', '7q', '7r', '7s', '7t', '7u'],
        'Water Production' => ['8a', '8bi', '8bii','8biii','8c', '8d', '8e', '8f', '8g', '8h', '8i', '8j', '8k'],
        'Infrastructure' => ['9a', '9b'],
        'Expenditure' => ['10a', '10b', '10c', '10d', '10e', '10f', '10g', '10h', '10i', '10j'],
        'Sustainability' => ['11a', '11b', '11c', '11d', '11e', '11f', '11g', '11h', '11i'],
        'Expenditure Details' => [
            '12_1', '12_2', '12_3', '12_4', '12_5', '12_6', '12_7', '12_8', '12_9', '12_10',
            '12_11', '12_12', '12_13', '12_14', '12_15', '12_16', '12_17', '12_18', '12_19', '12_20',
            '12_21', '12_22', '12_23', '12_24', '12_25', '12_26', '12_27', '12_28', '12_29', '12_30',
            '12_31', '12_32', '12_33', '12_34', '12_35', '12_36', '12_37', '12_38', '12_39', '12_40',
            '12_41', '12_42', '12_43', '12_44', '12_45', '12_46', '12_47', '12_48', '12_49', '12_50',
            '12_51', '12_52', '12_total'
        ],
        'Financial Sustainability' => ['13a', '13b', '13c', '13d', '13e', '13f']
    ];
    
    // Build preview data according to structure
    foreach ($structure as $category => $codes) {
        $preview[$category] = [];
        foreach ($codes as $code) {
            if (isset($report_data[$category][$code])) {
                $row_data = ['label' => ''];
                
                // Get label from first available data
                $first_data = reset($report_data[$category][$code]);
                $row_data['label'] = $first_data['label'];
                
                // Add values for each selected month
                foreach ($selected_month_ids as $month_id) {
                    $value = isset($report_data[$category][$code][$month_id]) ? 
                        $report_data[$category][$code][$month_id]['value'] : '-';
                    $row_data[$month_id] = $value;
                }
                
                $preview[$category][$code] = $row_data;
            } else {
                // Get label from database if parameter exists but has no data
                global $conn;
                $param_query = "SELECT p.label FROM parameters p 
                              JOIN parameter_categories pc ON p.category_id = pc.id 
                              WHERE p.code = ? AND pc.name = ?";
                $stmt = $conn->prepare($param_query);
                $stmt->bind_param("ss", $code, $category);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $row_data = ['label' => $row['label']];
                    foreach ($selected_month_ids as $month_id) {
                        $row_data[$month_id] = '-';
                    }
                    $preview[$category][$code] = $row_data;
                }
            }
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

function formatExportData($data, $month_ids, $conn) {
    // Define the exact structure from your document
    $structure = [
        'Population Coverage' => ['1a', '1b', '1c', '1d'],
        'Water Connections' => ['2a', '2b', '2c', '2d', '2e', '2f', '2g'],
        'Breakdowns & Maintenance' => ['3a', '3b', '3c', '3ci','3cii','3ciii', '3d', '3e'],
        'Consumer Complaints' => ['4a', '4b', '4c'],
        'Water Quality' => ['5a','5ai', '5aii','5aiii','5aiv', '5b', '5c', '5ci', '5cii','5ciii', '5d', '5e', '5f', '5g', '5h', '5i', '5j', '5k', '5l', '5m', '5n'],
        'Staff & Operations' => ['6a', '6b', '6c', '6d', '6e'],
        'Billing & Revenue' => ['7a', '7b', '7c', '7d', '7e', '7f', '7g', '7h', '7i', '7j', '7k', '7l', '7m', '7n', '7o', '7p', '7q', '7r', '7s', '7t', '7u'],
        'Water Production' => ['8a', '8bi', '8bii','8biii','8c', '8d', '8e', '8f', '8g', '8h', '8i', '8j', '8k'],
        'Infrastructure' => ['9a', '9b'],
        'Expenditure' => ['10a', '10b', '10c', '10d', '10e', '10f', '10g', '10h', '10i', '10j'],
        'Sustainability' => ['11a', '11b', '11c', '11d', '11e', '11f', '11g', '11h', '11i'],
        'Expenditure Details' => [
            '12_1', '12_2', '12_3', '12_4', '12_5', '12_6', '12_7', '12_8', '12_9', '12_10',
            '12_11', '12_12', '12_13', '12_14', '12_15', '12_16', '12_17', '12_18', '12_19', '12_20',
            '12_21', '12_22', '12_23', '12_24', '12_25', '12_26', '12_27', '12_28', '12_29', '12_30',
            '12_31', '12_32', '12_33', '12_34', '12_35', '12_36', '12_37', '12_38', '12_39', '12_40',
            '12_41', '12_42', '12_43', '12_44', '12_45', '12_46', '12_47', '12_48', '12_49', '12_50',
            '12_51', '12_52', '12_total'
        ],
        'Financial Sustainability' => ['13a', '13b', '13c', '13d', '13e', '13f']
    ];
    
    $formatted_data = [];
    
    // Build formatted data according to structure
    foreach ($structure as $category => $codes) {
        $formatted_data[$category] = [];
        foreach ($codes as $code) {
            if (isset($data[$category][$code])) {
                $row_data = [];
                $first_month = reset($data[$category][$code]);
                $row_data['label'] = $first_month['label'];
                $row_data['unit'] = $first_month['unit'];
                
                // Add values for each selected month
                foreach ($month_ids as $month_id) {
                    $row_data[$month_id] = isset($data[$category][$code][$month_id]) ? 
                        $data[$category][$code][$month_id]['value'] : '-';
                }
                
                $formatted_data[$category][$code] = $row_data;
            } else {
                // Get label from database if parameter exists but has no data
                $param_query = "SELECT p.label, p.unit FROM parameters p 
                              JOIN parameter_categories pc ON p.category_id = pc.id 
                              WHERE p.code = ? AND pc.name = ?";
                $stmt = $conn->prepare($param_query);
                $stmt->bind_param("ss", $code, $category);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $row_data = [
                        'label' => $row['label'],
                        'unit' => $row['unit']
                    ];
                    foreach ($month_ids as $month_id) {
                        $row_data[$month_id] = '-';
                    }
                    $formatted_data[$category][$code] = $row_data;
                }
            }
        }
    }
    
    return $formatted_data;
}

function exportToWord($conn, $month_ids) {
    $data = getExportData($conn, $month_ids);
    $formatted_data = formatExportData($data, $month_ids, $conn);
    
    header("Content-Type: application/vnd.ms-word");
    header("Content-Disposition: attachment; filename=muwasco_monitoring_report.doc");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo generateWordContent($formatted_data, $month_ids, $conn);
    exit;
}

function exportToPDF($conn, $month_ids) {
    $data = getExportData($conn, $month_ids);
    $formatted_data = formatExportData($data, $month_ids, $conn);
    
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
    $html = generatePDFContent($formatted_data, $month_ids, $conn);
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Close and output PDF document
    $pdf->Output('muwasco_monitoring_report.pdf', 'D');
    exit;
}

function generateWordContent($data, $month_ids, $conn) {
    // Get month labels in chronological order
    $month_labels = [];
    $months_query = "SELECT id, month_year FROM months WHERE id IN (" . implode(',', $month_ids) . ") ORDER BY month_year ASC";
    $result = $conn->query($months_query);
    while ($row = $result->fetch_assoc()) {
        $month_labels[$row['id']] = date('F Y', strtotime($row['month_year'] . '-01'));
    }
    
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
        foreach ($parameters as $code => $row_data) {
            $content .= "<tr>
                <td>" . $counter . "</td>
                <td class='parameter-code'>" . htmlspecialchars($code) . "</td>
                <td class='parameter-label'>" . htmlspecialchars($row_data['label']) . "</td>";
            
            foreach ($month_ids as $month_id) {
                $value = isset($row_data[$month_id]) ? $row_data[$month_id] : '-';
                $content .= "<td>" . htmlspecialchars($value) . "</td>";
            }
            
            $content .= "</tr>";
            $counter++;
        }
        
        $content .= "</table>";
        
        $categories_displayed++;
        
        // Add Technical Manager signature after Water Quality (5th category)
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

function generatePDFContent($data, $month_ids, $conn) {
    // Get month labels in chronological order
    $month_labels = [];
    $months_query = "SELECT id, month_year FROM months WHERE id IN (" . implode(',', $month_ids) . ") ORDER BY month_year ASC";
    $result = $conn->query($months_query);
    while ($row = $result->fetch_assoc()) {
        $month_labels[$row['id']] = date('F Y', strtotime($row['month_year'] . '-01'));
    }
    
    $html = '<div style="text-align: center;">
        <h2 style="margin-bottom: 5px;">ATHI WATER WORKS DEVELOPMENT AGENCY</h2>
        <h3 style="margin-bottom: 5px;">MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h3>
        <h4 style="margin-bottom: 5px;">(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h4>
        <p style="margin-bottom: 15px;"><strong>WSP\'s NAME: MUWASCO - DATE OF MONITORING: ' . date('F Y') . '</strong></p>
    </div>';
    
    $categories_displayed = 0;
    foreach ($data as $category => $parameters) {
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
        foreach ($parameters as $code => $row_data) {
            $html .= '<tr>
                <td>' . $counter . '</td>
                <td><strong style="color: #0066cc;">' . htmlspecialchars($code) . '</strong></td>
                <td><strong>' . htmlspecialchars($row_data['label']) . '</strong></td>';
            
            foreach ($month_ids as $month_id) {
                $value = isset($row_data[$month_id]) ? $row_data[$month_id] : '-';
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            
            $html .= '</tr>';
            $counter++;
        }
        
        $html .= '</table>';
        
        $categories_displayed++;
        
        // Add Technical Manager signature after Water Quality (5th category)
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

// Get user info for display
$user_info = getUserInfo($conn, $_SESSION['user_id']);
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
    <?php include 'nav_bar.php'; ?>

    <div class="main-container">
        <div class="main-content">
            <div class="page-content">
                <div class="reports-dashboard-container">
                    <div class="reports-main-container animate-fade-in-up">
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
                                    <button type="submit" name="generate_report" class="report-generate-btn pulse" id="generateBtn">
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
                                <?= implode(', ', array_map(function($id) use ($available_months) {
                                    $month = array_filter($available_months, function($m) use ($id) { return $m['id'] == $id; });
                                    $month = reset($month);
                                    return date('F Y', strtotime($month['month_year'] . '-01'));
                                }, $selected_months)) ?>
                            </div>

                            <div class="report-export-options no-print">
                                <h3 class="mb-4">
                                    <i class="bi bi-download me-2"></i>
                                    Export Options
                                </h3>
                                <div class="report-export-buttons">
                                    <a href="?export=word&months=<?= implode(',', $selected_months) ?>" 
                                       class="report-export-btn report-floating" target="_blank">
                                        <i class="bi bi-file-word me-2"></i>Export to Word
                                    </a>
                                    <a href="?export=pdf&months=<?= implode(',', $selected_months) ?>" 
                                       class="report-export-btn report-export-btn-pdf report-floating" style="animation-delay: 0.2s;" target="_blank">
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
                                    <?php if ($categories_displayed == 5): ?>
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
            
            // Add floating effect to export buttons
            const exportButtons = document.querySelectorAll('.report-floating');
            exportButtons.forEach((btn, index) => {
                btn.style.animationDelay = (index * 0.2) + 's';
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>