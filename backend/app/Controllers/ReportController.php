<?php
// report.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../../vendor/autoload.php';
} else {
    die('TCPDF library not found. Please install via composer: composer require tecnickcom/tcpdf');
}

require_once __DIR__ . '/../../config/database.php';

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
    $user   = $result->fetch_assoc();
    $stmt->close();
    if ($user) {
        $user['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if (empty($user['full_name'])) $user['full_name'] = $user['username'];
    }
    return $user;
}

$user_info = getUserInfo($conn, $_SESSION['user_id']);
if (!isset($_SESSION['role']) && isset($user_info['role'])) {
    $_SESSION['role'] = $user_info['role'];
}

$months_query  = "SELECT * FROM months WHERE status = 'submitted' ORDER BY month_year ASC";
$months_result = $conn->query($months_query);
$available_months = [];
while ($row = $months_result->fetch_assoc()) {
    $available_months[] = $row;
}

$selected_months = [];
$report_data     = [];
$preview_data    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $selected_months = $_POST['months'] ?? [];
    if (count($selected_months) >= 3) {
        usort($selected_months, function ($a, $b) use ($available_months) {
            $dateA = $dateB = null;
            foreach ($available_months as $m) {
                if ($m['id'] == $a) $dateA = strtotime($m['month_year'] . '-01');
                if ($m['id'] == $b) $dateB = strtotime($m['month_year'] . '-01');
            }
            return $dateA - $dateB;
        });

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
        $preview_data = formatPreviewData($report_data, $selected_months, $available_months, $conn);
    }
}

if (isset($_GET['export'])) {
    $export_type  = $_GET['export'];
    $months_param = $_GET['months'] ?? '';
    $month_ids    = array_filter(explode(',', $months_param), 'strlen');

    if (count($month_ids) >= 3) {
        $month_details = [];
        foreach ($month_ids as $id) {
            $stmt = $conn->prepare("SELECT id, month_year FROM months WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) $month_details[] = $row;
        }
        usort($month_details, fn ($a, $b) => strtotime($a['month_year'] . '-01') - strtotime($b['month_year'] . '-01'));
        $sorted_month_ids = array_column($month_details, 'id');

        if ($export_type === 'word')     exportToWord($conn, $sorted_month_ids);
        elseif ($export_type === 'pdf') exportToPDF($conn, $sorted_month_ids);
    }
    exit;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatPreviewData($report_data, $selected_month_ids, $available_months, $conn) {
    $preview     = [];
    $cats_result = $conn->query("SELECT name, display_order FROM parameter_categories ORDER BY display_order");
    $categories  = [];
    while ($row = $cats_result->fetch_assoc()) $categories[$row['name']] = $row['display_order'];

    uksort($report_data, fn ($a, $b) => ($categories[$a] ?? 999) - ($categories[$b] ?? 999));

    foreach ($report_data as $category => $parameters) {
        $preview[$category] = [];
        foreach ($parameters as $code => $month_data) {
            $first    = reset($month_data);
            $row_data = [
                'label'     => $first['label']     ?? '',
                'unit'      => $first['unit']      ?? '',
                'data_type' => $first['data_type'] ?? 'text',
            ];
            foreach ($selected_month_ids as $month_id) {
                $row_data[$month_id] = $month_data[$month_id]['value'] ?? '-';
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
    $data   = [];
    while ($row = $result->fetch_assoc()) {
        $data[$row['category_name']][$row['code']][$row['month_id']] = $row;
    }
    return $data;
}

function getMonthLabels($conn, $month_ids) {
    $labels = [];
    $result = $conn->query(
        "SELECT id, month_year FROM months WHERE id IN (" . implode(',', $month_ids) . ") ORDER BY month_year ASC"
    );
    while ($row = $result->fetch_assoc()) {
        $labels[$row['id']] = date('F Y', strtotime($row['month_year'] . '-01'));
    }
    return $labels;
}

// ─── Word Export ──────────────────────────────────────────────────────────────

function exportToWord($conn, $month_ids) {
    $data         = getExportData($conn, $month_ids);
    $month_labels = getMonthLabels($conn, $month_ids);

    header("Content-Type: application/vnd.ms-word");
    header("Content-Disposition: attachment; filename=muwasco_monitoring_report.doc");
    header("Pragma: no-cache");
    header("Expires: 0");
    echo generateWordContent($data, $month_ids, $month_labels);
    exit;
}

function generateWordContent($data, $month_ids, $month_labels) {
    $month_col_w = count($month_ids) > 0 ? round(55 / count($month_ids)) : 18;

    $out  = "<html><head><meta charset='UTF-8'><style>
        body  { font-family: Arial, sans-serif; margin: 20px; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .sig    { margin-top: 30px; margin-bottom: 30px; }
    </style></head><body>";

    // Document heading
    $out .= "<div class='header'>
        <h2>ATHI WATER WORKS DEVELOPMENT AGENCY</h2>
        <h3>MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h3>
        <h4>(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h4>
        <p><strong>WSP'S NAME: MUWASCO &nbsp;&nbsp; DATE OF MONITORING: " . date('F Y') . "</strong></p>
    </div>";

    // ── ONE header row at the top ─────────────────────────────────────────────
    $out .= "<table><tr>
        <th width='10%'>NUMBER</th>
        <th width='35%'>PARAMETERS</th>";
    foreach ($month_ids as $mid) {
        $out .= "<th width='{$month_col_w}%'>" . strtoupper($month_labels[$mid]) . "</th>";
    }
    $out .= "</tr>";

    // ── All data rows — no section titles, no repeated headers ───────────────
    $cat_count = 0;
    foreach ($data as $category => $parameters) {
        if (empty($parameters)) continue;
        $cat_count++;

        foreach ($parameters as $code => $month_data) {
            $first = reset($month_data);
            $out  .= "<tr>
                <td><strong>" . htmlspecialchars($code) . "</strong></td>
                <td>" . htmlspecialchars($first['label']) . "</td>";
            foreach ($month_ids as $mid) {
                $out .= "<td>" . htmlspecialchars($month_data[$mid]['value'] ?? '-') . "</td>";
            }
            $out .= "</tr>";
        }

        // Technical Manager signature break after the 5th category (Water Quality)
        if ($cat_count == 5) {
            $out .= "</table>
            <div class='sig'>
                <p><strong>Above data verified by:</strong></p>
                <p><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                <p>SIGN...............................................................DATE....................................................................</p>
            </div>
            <table><tr>
                <th width='10%'>NUMBER</th>
                <th width='35%'>PARAMETERS</th>";
            foreach ($month_ids as $mid) {
                $out .= "<th width='{$month_col_w}%'>" . strtoupper($month_labels[$mid]) . "</th>";
            }
            $out .= "</tr>";
        }
    }

    $out .= "</table>";

    // Final signatures
    $out .= "<div class='sig'>
        <p><strong>Data verified by:</strong></p>
        <p><strong>JOSEPH MAINA (CMT) - COMMERCIAL MANAGER</strong></p>
        <p>Sign.......................................................................Date....................................................................</p>
        <br>
        <p><strong>ENG. D. NG'ANG'A - MANAGING DIRECTOR</strong></p>
        <p>Sign.......................................................................Date....................................................................</p>
    </div></body></html>";

    return $out;
}

// ─── PDF Export ───────────────────────────────────────────────────────────────

function exportToPDF($conn, $month_ids) {
    $data         = getExportData($conn, $month_ids);
    $month_labels = getMonthLabels($conn, $month_ids);
    try {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('MUWASCO Monitoring System');
        $pdf->SetAuthor('MUWASCO');
        $pdf->SetTitle('Water Monitoring Report');
        $pdf->SetSubject('Monthly Water Monitoring Data');
        $pdf->SetMargins(10, 15, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        $pdf->writeHTML(generatePDFContent($data, $month_ids, $month_labels), true, false, true, false, '');
        $pdf->Output('muwasco_monitoring_report.pdf', 'D');
        exit;
    } catch (Exception $e) {
        die("Error generating PDF: " . $e->getMessage());
    }
}

function generatePDFContent($data, $month_ids, $month_labels) {
    $month_col_pct = count($month_ids) > 0 ? round(55 / count($month_ids)) : 18;

    $html  = '<div style="text-align:center;">
        <h2 style="margin-bottom:5px;">ATHI WATER WORKS DEVELOPMENT AGENCY</h2>
        <h3 style="margin-bottom:5px;">MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO</h3>
        <h4 style="margin-bottom:5px;">(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)</h4>
        <p style="margin-bottom:15px;"><strong>WSP\'S NAME: MUWASCO &nbsp;&nbsp; DATE OF MONITORING: ' . date('F Y') . '</strong></p>
    </div>';

    // ── ONE header row at the top ─────────────────────────────────────────────
    $html .= '<table border="1" cellpadding="4" style="border-collapse:collapse;width:100%;font-size:8pt;">
        <tr style="background-color:#f2f2f2;">
            <th width="10%"><strong>NUMBER</strong></th>
            <th width="35%"><strong>PARAMETERS</strong></th>';
    foreach ($month_ids as $mid) {
        $html .= '<th width="' . $month_col_pct . '%"><strong>' . strtoupper($month_labels[$mid]) . '</strong></th>';
    }
    $html .= '</tr>';

    // ── All data rows — no section titles, no repeated headers ───────────────
    $cat_count = 0;
    foreach ($data as $category => $parameters) {
        if (empty($parameters)) continue;
        $cat_count++;

        foreach ($parameters as $code => $month_data) {
            $first  = reset($month_data);
            $html  .= '<tr>
                <td><strong style="color:#0066cc;">' . htmlspecialchars($code) . '</strong></td>
                <td>' . htmlspecialchars($first['label']) . '</td>';
            foreach ($month_ids as $mid) {
                $html .= '<td>' . htmlspecialchars($month_data[$mid]['value'] ?? '-') . '</td>';
            }
            $html .= '</tr>';
        }

        // Technical Manager signature break after 5th category (Water Quality)
        if ($cat_count == 5) {
            $html .= '</table>
            <div style="margin:20px 0;">
                <p><strong>Above data verified by:</strong></p>
                <p><strong>PETER KARENJU - TECHNICAL MANAGER</strong></p>
                <p>SIGN..............................................DATE....................................................</p>
            </div>
            <table border="1" cellpadding="4" style="border-collapse:collapse;width:100%;font-size:8pt;">
            <tr style="background-color:#f2f2f2;">
                <th width="10%"><strong>NUMBER</strong></th>
                <th width="35%"><strong>PARAMETERS</strong></th>';
            foreach ($month_ids as $mid) {
                $html .= '<th width="' . $month_col_pct . '%"><strong>' . strtoupper($month_labels[$mid]) . '</strong></th>';
            }
            $html .= '</tr>';
        }
    }

    $html .= '</table>';

    // Final signatures
    $html .= '<div style="margin-top:20px;">
        <p><strong>Data verified by:</strong></p>
        <p><strong>JOSEPH MAINA (CMT) - COMMERCIAL MANAGER</strong></p>
        <p>Sign..............................................Date....................................................</p>
        <br>
        <p><strong>ENG. D. NG\'ANG\'A - MANAGING DIRECTOR</strong></p>
        <p>Sign..............................................Date....................................................</p>
    </div>';

    return $html;
}

/* Render the report tools view. */
require __DIR__ . '/../../../frontend/src/pages/report.php';
