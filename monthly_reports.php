<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'auth_functions.php';
require_once 'role_functions.php';

// Check if user is logged in
session_start();
requireLogin();

// MonthlyReport Class with improved role progress tracking
class MonthlyReport {
    private $conn;
    private $role_id;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->role_id = $_SESSION['role_id'] ?? null;
    }
    
    public function getRecentMonths() {
        $sql = "SELECT id, name, month_year, status, created_at 
                FROM months 
                ORDER BY month_year DESC 
                LIMIT 5";
        
        $result = $this->conn->query($sql);
        $months = [];
        while ($row = $result->fetch_assoc()) {
            $months[] = $row;
        }
        return $months;
    }
    
    public function getSummaryStats() {
        $stats = [];
        
        // Total months count
        $sql = "SELECT COUNT(*) as total_months FROM months";
        $result = $this->conn->query($sql);
        $stats['total_months'] = $result->fetch_assoc()['total_months'];
        
        // Submitted months count
        $sql = "SELECT COUNT(*) as submitted_months FROM months WHERE status = 'submitted'";
        $result = $this->conn->query($sql);
        $stats['submitted_months'] = $result->fetch_assoc()['submitted_months'];
        
        // Pending months count
        $sql = "SELECT COUNT(*) as pending_months FROM months WHERE status = 'draft'";
        $result = $this->conn->query($sql);
        $stats['pending_months'] = $result->fetch_assoc()['pending_months'];
        
        // Total parameters count
        $sql = "SELECT COUNT(*) as total_parameters FROM parameters";
        $result = $this->conn->query($sql);
        $stats['total_parameters'] = $result->fetch_assoc()['total_parameters'];
        
        // User's assigned parameters count
        if (isAdmin()) {
            $stats['user_parameters'] = $stats['total_parameters'];
        } else {
            $sql = "SELECT COUNT(*) as user_parameters 
                    FROM role_parameter_assignments 
                    WHERE role_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $this->role_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats['user_parameters'] = $result->fetch_assoc()['user_parameters'];
        }
        
        // Latest month data
        $sql = "SELECT name, month_year FROM months WHERE status = 'submitted' ORDER BY month_year DESC LIMIT 1";
        $result = $this->conn->query($sql);
        $stats['latest_month'] = $result->fetch_assoc();
        
        return $stats;
    }
    
    public function getUserActivity() {
        if (!isset($_SESSION['user_id'])) {
            return [];
        }
        
        $user_id = $_SESSION['user_id'];
        
        if (isAdmin()) {
            $sql = "SELECT 
                        m.name, 
                        m.month_year, 
                        m.status,
                        COUNT(md.id) as entries_count,
                        MAX(md.created_at) as last_updated
                    FROM monthly_data md
                    JOIN months m ON md.month_id = m.id
                    WHERE md.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY m.id
                    ORDER BY md.created_at DESC
                    LIMIT 5";
            $result = $this->conn->query($sql);
        } else {
            $sql = "SELECT 
                        m.name, 
                        m.month_year, 
                        m.status,
                        COUNT(md.id) as entries_count,
                        MAX(md.created_at) as last_updated
                    FROM monthly_data md
                    JOIN months m ON md.month_id = m.id
                    JOIN role_parameter_assignments rpa ON md.parameter_id = rpa.parameter_id
                    WHERE rpa.role_id = ? AND md.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY m.id
                    ORDER BY md.created_at DESC
                    LIMIT 5";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $this->role_id);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        
        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
        return $activity;
    }
    
    public function getChartDataForParameter($parameter_id) {
        // Check if user has access to this parameter
        if (!isAdmin() && !hasParameterAccess($parameter_id)) {
            return [];
        }
        
        // Get the most recent 12 months with data for this parameter
        $sql = "SELECT 
                    m.name as month_name,
                    m.month_year,
                    md.value,
                    p.label as parameter_name,
                    p.unit
                FROM monthly_data md
                JOIN months m ON md.month_id = m.id
                JOIN parameters p ON md.parameter_id = p.id
                WHERE md.parameter_id = ?
                ORDER BY m.month_year DESC
                LIMIT 12";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $parameter_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Clean the value by removing commas and converting to float
            $row['value'] = floatval(str_replace(',', '', $row['value']));
            $data[] = $row;
        }
        
        // Reverse the array to show oldest to newest (chronological order)
        return array_reverse($data);
    }
    
    public function getUserAccessibleParameters() {
        if (isAdmin()) {
            $sql = "SELECT * FROM parameters ORDER BY code";
            $result = $this->conn->query($sql);
        } else {
            $sql = "SELECT p.* 
                    FROM parameters p
                    JOIN role_parameter_assignments rpa ON p.id = rpa.parameter_id
                    WHERE rpa.role_id = ?
                    ORDER BY p.code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $this->role_id);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        
        $parameters = [];
        while ($row = $result->fetch_assoc()) {
            $parameters[] = $row;
        }
        return $parameters;
    }
    
    // IMPROVED ROLE PROGRESS TRACKING
    public function getRoleProgress($month_id = null) {
        $progress_data = [];
        
        if (isAdmin()) {
            // Get all roles except admin
            $sql = "SELECT r.id, r.name, r.description 
                    FROM roles r 
                    WHERE r.name != 'admin' 
                    ORDER BY r.name";
            $result = $this->conn->query($sql);
            $roles = [];
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row;
            }
            
            // Get current active month if not specified
            if (!$month_id) {
                // First try to get a draft month
                $month_sql = "SELECT id FROM months WHERE status = 'draft' ORDER BY month_year DESC LIMIT 1";
                $month_result = $this->conn->query($month_sql);
                if ($month_result->num_rows > 0) {
                    $month = $month_result->fetch_assoc();
                    $month_id = $month['id'];
                } else {
                    // If no draft, get the most recent submitted month
                    $month_sql = "SELECT id FROM months WHERE status = 'submitted' ORDER BY month_year DESC LIMIT 1";
                    $month_result = $this->conn->query($month_sql);
                    if ($month_result->num_rows > 0) {
                        $month = $month_result->fetch_assoc();
                        $month_id = $month['id'];
                    }
                }
            }
            
            // If no month found, return empty
            if (!$month_id) {
                return $progress_data;
            }
            
            // Calculate progress for each role
            foreach ($roles as $role) {
                // Get total parameters assigned to this role
                $param_sql = "SELECT COUNT(*) as total_params 
                              FROM role_parameter_assignments 
                              WHERE role_id = ?";
                $stmt = $this->conn->prepare($param_sql);
                $stmt->bind_param("i", $role['id']);
                $stmt->execute();
                $param_result = $stmt->get_result();
                $total_params = $param_result->fetch_assoc()['total_params'];
                
                if ($total_params > 0) {
                    // Get parameters that have been saved by this role for the current month
                    $saved_sql = "SELECT COUNT(DISTINCT md.parameter_id) as saved_params
                                 FROM monthly_data md
                                 JOIN role_parameter_assignments rpa ON md.parameter_id = rpa.parameter_id
                                 WHERE md.month_id = ? 
                                 AND rpa.role_id = ?";
                    $stmt2 = $this->conn->prepare($saved_sql);
                    $stmt2->bind_param("ii", $month_id, $role['id']);
                    $stmt2->execute();
                    $saved_result = $stmt2->get_result();
                    $saved_params = $saved_result->fetch_assoc()['saved_params'];
                    
                    $percentage = $total_params > 0 ? round(($saved_params / $total_params) * 100, 1) : 0;
                    
                    // Get last update time for this role
                    $last_update_sql = "SELECT MAX(md.created_at) as last_updated
                                       FROM monthly_data md
                                       JOIN role_parameter_assignments rpa ON md.parameter_id = rpa.parameter_id
                                       WHERE md.month_id = ? AND rpa.role_id = ?";
                    $stmt3 = $this->conn->prepare($last_update_sql);
                    $stmt3->bind_param("ii", $month_id, $role['id']);
                    $stmt3->execute();
                    $update_result = $stmt3->get_result();
                    $last_updated_row = $update_result->fetch_assoc();
                    $last_updated = $last_updated_row ? $last_updated_row['last_updated'] : null;
                    
                    // Determine status based on percentage
                    if ($percentage == 100) {
                        $status = 'completed';
                        $status_badge = 'success';
                    } elseif ($percentage > 0) {
                        $status = 'in_progress';
                        $status_badge = 'warning';
                    } else {
                        $status = 'not_started';
                        $status_badge = 'danger';
                    }
                    
                    // Get users with this role
                    $user_sql = "SELECT u.id, u.username, u.first_name, u.last_name, u.email 
                                FROM users u 
                                WHERE u.role_id = ? AND u.is_active = 1";
                    $stmt4 = $this->conn->prepare($user_sql);
                    $stmt4->bind_param("i", $role['id']);
                    $stmt4->execute();
                    $user_result = $stmt4->get_result();
                    
                    $users = [];
                    while ($user = $user_result->fetch_assoc()) {
                        $users[] = $user;
                    }
                    
                    $progress_data[] = [
                        'role_id' => $role['id'],
                        'role_name' => $role['name'],
                        'role_description' => $role['description'],
                        'total_params' => $total_params,
                        'saved_params' => $saved_params,
                        'percentage' => $percentage,
                        'last_updated' => $last_updated,
                        'status' => $status,
                        'status_badge' => $status_badge,
                        'users' => $users,
                        'month_id' => $month_id
                    ];
                }
            }
            
            // Sort by percentage (highest first)
            usort($progress_data, function($a, $b) {
                return $b['percentage'] <=> $a['percentage'];
            });
        }
        
        return $progress_data;
    }
    
    public function getOverallProgressStats($month_id = null) {
        $stats = [
            'total_roles' => 0,
            'completed_roles' => 0,
            'in_progress_roles' => 0,
            'not_started_roles' => 0,
            'overall_percentage' => 0,
            'total_parameters' => 0,
            'saved_parameters' => 0
        ];
        
        if (isAdmin()) {
            // Get current month if not specified
            if (!$month_id) {
                $month_sql = "SELECT id FROM months WHERE status = 'draft' ORDER BY month_year DESC LIMIT 1";
                $month_result = $this->conn->query($month_sql);
                if ($month_result->num_rows > 0) {
                    $month = $month_result->fetch_assoc();
                    $month_id = $month['id'];
                }
            }
            
            if ($month_id) {
                // Get all non-admin roles
                $role_sql = "SELECT COUNT(*) as total_roles 
                            FROM roles 
                            WHERE name != 'admin'";
                $result = $this->conn->query($role_sql);
                $stats['total_roles'] = $result->fetch_assoc()['total_roles'];
                
                // Get total parameters across all roles
                $param_sql = "SELECT COUNT(*) as total_parameters 
                             FROM role_parameter_assignments rpa
                             JOIN roles r ON rpa.role_id = r.id
                             WHERE r.name != 'admin'";
                $result = $this->conn->query($param_sql);
                $stats['total_parameters'] = $result->fetch_assoc()['total_parameters'];
                
                // Get saved parameters for this month
                $saved_sql = "SELECT COUNT(DISTINCT md.parameter_id) as saved_parameters
                             FROM monthly_data md
                             JOIN role_parameter_assignments rpa ON md.parameter_id = rpa.parameter_id
                             JOIN roles r ON rpa.role_id = r.id
                             WHERE md.month_id = ? AND r.name != 'admin'";
                $stmt = $this->conn->prepare($saved_sql);
                $stmt->bind_param("i", $month_id);
                $stmt->execute();
                $saved_result = $stmt->get_result();
                $stats['saved_parameters'] = $saved_result->fetch_assoc()['saved_parameters'];
                
                // Get role progress data
                $role_progress = $this->getRoleProgress($month_id);
                
                foreach ($role_progress as $role) {
                    if ($role['percentage'] == 100) {
                        $stats['completed_roles']++;
                    } elseif ($role['percentage'] > 0) {
                        $stats['in_progress_roles']++;
                    } else {
                        $stats['not_started_roles']++;
                    }
                }
                
                // Calculate overall percentage
                if ($stats['total_roles'] > 0) {
                    $stats['overall_percentage'] = round(($stats['completed_roles'] / $stats['total_roles']) * 100, 1);
                }
                
                // Calculate parameter completion percentage
                if ($stats['total_parameters'] > 0) {
                    $stats['parameter_percentage'] = round(($stats['saved_parameters'] / $stats['total_parameters']) * 100, 1);
                } else {
                    $stats['parameter_percentage'] = 0;
                }
            }
        }
        
        return $stats;
    }
    
    // Get months for charts (12 most recent)
    public function getRecentMonthsForCharts() {
        $sql = "SELECT DISTINCT m.id, m.name, m.month_year
                FROM monthly_data md
                JOIN months m ON md.month_id = m.id
                ORDER BY m.month_year DESC
                LIMIT 12";
        
        $result = $this->conn->query($sql);
        $months = [];
        while ($row = $result->fetch_assoc()) {
            $months[] = $row;
        }
        
        return array_reverse($months);
    }
    
    // Get combined chart data
    public function getCombinedChartData($parameter_ids, $month_ids) {
        if (empty($parameter_ids) || empty($month_ids)) {
            return [];
        }
        
        // Create placeholders for IN clauses
        $param_placeholders = implode(',', array_fill(0, count($parameter_ids), '?'));
        $month_placeholders = implode(',', array_fill(0, count($month_ids), '?'));
        
        $sql = "SELECT 
                    md.parameter_id,
                    m.id as month_id,
                    m.name as month_name,
                    m.month_year,
                    md.value,
                    p.label as parameter_name,
                    p.unit
                FROM monthly_data md
                JOIN months m ON md.month_id = m.id
                JOIN parameters p ON md.parameter_id = p.id
                WHERE md.parameter_id IN ($param_placeholders)
                AND md.month_id IN ($month_placeholders)
                ORDER BY m.month_year, md.parameter_id";
        
        $stmt = $this->conn->prepare($sql);
        
        // Bind parameters: first the parameter_ids, then the month_ids
        $types = str_repeat('i', count($parameter_ids) + count($month_ids));
        $params = array_merge($parameter_ids, $month_ids);
        $stmt->bind_param($types, ...$params);
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Clean the value by removing commas and converting to float
            $row['value'] = floatval(str_replace(',', '', $row['value']));
            $data[] = $row;
        }
        
        return $data;
    }
    
    // Get current active month ID for progress tracking
    public function getCurrentMonthId() {
        // First try to get a draft month
        $sql = "SELECT id FROM months WHERE status = 'draft' ORDER BY month_year DESC LIMIT 1";
        $result = $this->conn->query($sql);
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['id'];
        }
        
        // If no draft, get the most recent submitted month
        $sql = "SELECT id FROM months WHERE status = 'submitted' ORDER BY month_year DESC LIMIT 1";
        $result = $this->conn->query($sql);
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['id'];
        }
        
        return null;
    }
}

$report = new MonthlyReport($conn);
$recentMonths = $report->getRecentMonths();
$summaryStats = $report->getSummaryStats();
$userActivity = $report->getUserActivity();

// Get user info
$user_info = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT u.*, r.name as role_name, r.description as role_description 
                           FROM users u 
                           LEFT JOIN roles r ON u.role_id = r.id 
                           WHERE u.id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_info = $result->fetch_assoc();
}

// Store user info in session for quick access
if ($user_info) {
    $_SESSION['user_full_name'] = trim(($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? ''));
    if (empty($_SESSION['user_full_name'])) {
        $_SESSION['user_full_name'] = $user_info['username'];
    }
    $_SESSION['user_role'] = $user_info['role_name'] ?? 'User';
}

// Get user's accessible parameters to determine which charts to show
$accessible_parameters = $report->getUserAccessibleParameters();
$accessible_parameter_ids = array_column($accessible_parameters, 'id');

// Get user's role display name
$role_display = $user_info['role_name'] ?? 'User';
if (isset($user_info['role_description'])) {
    $role_display .= ' (' . $user_info['role_description'] . ')';
}

// Get chart data for key parameters
$parameter_charts = [
    'active_connections' => [
        'id' => 190,
        'title' => 'Active Connections',
        'color' => '#00ffff',
        'icon' => 'bi-people'
    ],
    'total_expenditure' => [
        'id' => 295,
        'title' => 'Monthly Expenditure',
        'color' => '#ff6b6b',
        'icon' => 'bi-cash-stack'
    ],
    'total_revenue' => [
        'id' => 294,
        'title' => 'Revenue Collected',
        'color' => '#4ade80',
        'icon' => 'bi-graph-up-arrow'
    ],
    'water_production' => [
        'id' => 211,
        'title' => 'Water Production',
        'color' => '#00a8ff',
        'icon' => 'bi-droplet'
    ]
];

// Filter charts to only show those the user has access to
$filtered_charts = [];
foreach ($parameter_charts as $key => $chart_info) {
    if (isAdmin() || in_array($chart_info['id'], $accessible_parameter_ids)) {
        $filtered_charts[$key] = $chart_info;
    }
}

// Fetch data for each accessible parameter (most recent 12 months)
$chart_data = [];
foreach ($filtered_charts as $key => $chart_info) {
    $chart_data[$key] = $report->getChartDataForParameter($chart_info['id']);
}

// Get the most recent 12 months for combined chart
$recent_chart_months = $report->getRecentMonthsForCharts();
$recent_month_ids = array_column($recent_chart_months, 'id');

// Get combined chart data
$combined_chart_data = [];
if (!empty($filtered_charts) && !empty($recent_month_ids)) {
    $filtered_param_ids = array_column($filtered_charts, 'id');
    $combined_chart_data = $report->getCombinedChartData($filtered_param_ids, $recent_month_ids);
}

// Get current month ID for progress tracking
$current_month_id = $report->getCurrentMonthId();

// Get role progress data (for admin only)
$role_progress = [];
$overall_stats = [];
if (isAdmin() && $current_month_id) {
    $role_progress = $report->getRoleProgress($current_month_id);
    $overall_stats = $report->getOverallProgressStats($current_month_id);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AquaTrack Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Add Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Add ApexCharts for better visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <!-- Link to your global CSS -->
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --chart-blue: #3b82f6;
            --chart-green: #10b981;
            --chart-red: #ef4444;
            --chart-yellow: #f59e0b;
            --chart-purple: #8b5cf6;
            --chart-cyan: #06b6d4;
            --chart-pink: #ec4899;
            --chart-orange: #f97316;
        }

        .role-progress-section {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .role-progress-section .section-header {
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .role-progress-section .section-title {
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .role-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }

        .role-progress-table .table th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 15px;
        }

        .role-progress-table .table td {
            padding: 15px;
            vertical-align: middle;
        }

        .role-progress-table .progress {
            height: 20px;
            background: var(--light-bg);
        }

        .role-progress-table .progress-bar {
            font-size: 12px;
            font-weight: 600;
            line-height: 20px;
        }

        .role-progress-table tbody tr {
            transition: all 0.3s ease;
        }

        .role-progress-table tbody tr:hover {
            background: rgba(var(--primary-rgb), 0.05);
            transform: translateY(-2px);
        }
        
        .chart-info-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            z-index: 10;
        }
        
        .month-range {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Enhanced Chart Cards */
        .chart-card {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .chart-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .chart-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .chart-value {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--text-primary);
        }
        
        .chart-change {
            font-size: 14px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        .chart-change.positive {
            background: rgba(16, 185, 129, 0.1);
            color: var(--chart-green);
        }
        
        .chart-change.negative {
            background: rgba(239, 68, 68, 0.1);
            color: var(--chart-red);
        }
        
        .chart-canvas-container {
            position: relative;
            height: 200px;
            width: 100%;
        }
        
        /* Combined Chart Styles */
        .combined-chart {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .combined-chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-controls {
            display: flex;
            gap: 10px;
        }
        
        .chart-timeframe-btn {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .chart-timeframe-btn.active {
            background: var(--primary);
            color: white;
        }
        
        .chart-timeframe-btn:hover:not(.active) {
            background: rgba(var(--primary-rgb), 0.1);
        }
        
        /* Role Progress Cards */
        .role-progress-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .role-progress-card.completed {
            border-left-color: var(--chart-green);
        }
        
        .role-progress-card.in_progress {
            border-left-color: var(--chart-yellow);
        }
        
        .role-progress-card.not_started {
            border-left-color: var(--chart-red);
        }
        
        .role-progress-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .role-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .role-name {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-primary);
        }
        
        .role-description {
            font-size: 12px;
            color: var(--text-tertiary);
            margin-bottom: 10px;
        }
        
        .role-progress-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .progress-percentage {
            font-size: 24px;
            font-weight: 700;
        }
        
        .role-parameters {
            font-size: 12px;
            color: var(--text-tertiary);
        }
        
        .role-users {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }
        
        .user-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 600;
            color: white;
        }
        
        /* Progress Visualization */
        .progress-visualization {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
        }
        
        @media (max-width: 768px) {
            .progress-visualization {
                grid-template-columns: 1fr;
            }
        }
        
        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(var(--primary-rgb), 0.3);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-tertiary);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        /* Stats Grid Enhancement */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
        }
        
        .stat-icon.blue { background: rgba(59, 130, 246, 0.1); color: var(--chart-blue); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--chart-green); }
        .stat-icon.orange { background: rgba(249, 115, 22, 0.1); color: var(--chart-orange); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.1); color: var(--chart-purple); }
        .stat-icon.teal { background: rgba(6, 182, 212, 0.1); color: var(--chart-cyan); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); color: var(--chart-red); }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--text-primary);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Navigation Bar -->
        <?php include 'nav_bar.php'; ?>
        
        <!-- Main Content Area -->
        <div class="main-content">
            <div class="page-content">
                <!-- Header -->
                <div class="dashboard-header animate-fade-in-up">
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_full_name'] ?? 'User'); ?>! 👋</h1>
                    <p class="welcome-text">
                        Manage your water system reporting with powerful analytics and insights. 
                        Track performance, submit reports, and analyze data in real-time.
                        <?php if (isset($role_display)): ?>
                        <span class="role-badge">Role: <?php echo htmlspecialchars($role_display); ?></span>
                        <?php endif; ?>
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <span class="badge badge-success">
                            <i class="bi bi-check-circle"></i> System Status: <strong>Operational</strong>
                        </span>
                        <span class="badge badge-info">
                            <i class="bi bi-clock"></i> Last Login: <?php echo date('M d, Y H:i'); ?>
                        </span>
                        <span class="badge badge-info">
                            <i class="bi bi-person-badge"></i> Assigned Parameters: <?php echo $summaryStats['user_parameters']; ?>
                        </span>
                        <?php if (isAdmin()): ?>
                        <span class="badge badge-warning">
                            <i class="bi bi-shield-check"></i> Administrator Access
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid animate-fade-in-up" style="animation-delay: 0.1s">
                    <div class="stat-card">
                        <div class="stat-icon blue">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                        <div class="stat-value"><?php echo $summaryStats['total_months']; ?></div>
                        <div class="stat-label">Total Months</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo ($summaryStats['submitted_months'] / max(1, $summaryStats['total_months'])) * 100; ?>%"></div>
                        </div>
                        <small><?php echo $summaryStats['submitted_months']; ?> submitted, <?php echo $summaryStats['pending_months']; ?> pending</small>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon green">
                            <i class="bi bi-check-square"></i>
                        </div>
                        <div class="stat-value"><?php echo $summaryStats['submitted_months']; ?></div>
                        <div class="stat-label">Submitted Reports</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo ($summaryStats['submitted_months'] / max(1, $summaryStats['total_months'])) * 100; ?>%"></div>
                        </div>
                        <small>Latest: <?php echo htmlspecialchars($summaryStats['latest_month']['name'] ?? 'No reports'); ?></small>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon orange">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div class="stat-value"><?php echo $summaryStats['pending_months']; ?></div>
                        <div class="stat-label">Draft Reports</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo ($summaryStats['pending_months'] / max(1, $summaryStats['total_months'])) * 100; ?>%"></div>
                        </div>
                        <small>Awaiting submission</small>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon purple">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="stat-value"><?php echo $summaryStats['user_parameters']; ?></div>
                        <div class="stat-label">My Parameters</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: 100%"></div>
                        </div>
                        <small>Assigned tracking parameters</small>
                    </div>
                    
                    <?php if (isAdmin() && isset($overall_stats['overall_percentage'])): ?>
                    <div class="stat-card">
                        <div class="stat-icon teal">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $overall_stats['overall_percentage']; ?>%</div>
                        <div class="stat-label">Role Progress</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $overall_stats['overall_percentage']; ?>%"></div>
                        </div>
                        <small><?php echo $overall_stats['completed_roles']; ?> of <?php echo $overall_stats['total_roles']; ?> roles complete</small>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon red">
                            <i class="bi bi-bar-chart"></i>
                        </div>
                        <div class="stat-value"><?php echo $overall_stats['parameter_percentage'] ?? 0; ?>%</div>
                        <div class="stat-label">Parameters Saved</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $overall_stats['parameter_percentage'] ?? 0; ?>%"></div>
                        </div>
                        <small><?php echo $overall_stats['saved_parameters'] ?? 0; ?> / <?php echo $overall_stats['total_parameters'] ?? 0; ?> parameters</small>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Charts Grid -->
                <?php if (!empty($filtered_charts)): ?>
                <div class="charts-grid animate-fade-in-up" style="animation-delay: 0.2s">
                    <?php foreach ($filtered_charts as $key => $chart_info): 
                        $data = $chart_data[$key];
                        $month_count = count($data);
                        
                        if (!empty($data)) {
                            // Get the latest (last in array since it's in chronological order)
                            $latest_value = $data[$month_count-1]['value'];
                            
                            // Get the previous month's value (second last)
                            $prev_value = $month_count > 1 ? $data[$month_count-2]['value'] : $latest_value;
                            $change = $prev_value != 0 ? (($latest_value - $prev_value) / $prev_value) * 100 : 0;
                            
                            // Get month range
                            $first_month = $data[0]['month_name'] ?? '';
                            $last_month = $data[$month_count-1]['month_name'] ?? '';
                        } else {
                            $latest_value = 0;
                            $change = 0;
                            $first_month = '';
                            $last_month = '';
                            $month_count = 0;
                        }
                    ?>
                    <div class="chart-card">
                        <span class="chart-info-badge"><?php echo $month_count; ?>-Month Trend</span>
                        <div class="chart-header">
                            <div class="chart-title">
                                <div class="chart-icon" style="background: <?php echo $chart_info['color'] . '20'; ?>; color: <?php echo $chart_info['color']; ?>">
                                    <i class="bi <?php echo $chart_info['icon']; ?>"></i>
                                </div>
                                <?php echo $chart_info['title']; ?>
                            </div>
                            <span class="chart-change <?php echo $change >= 0 ? 'positive' : 'negative'; ?>">
                                <i class="bi bi-arrow-<?php echo $change >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo number_format(abs($change), 1); ?>%
                            </span>
                        </div>
                        
                        <div class="chart-value">
                            <?php 
                                if ($latest_value >= 1000000) {
                                    echo number_format($latest_value / 1000000, 1) . 'M';
                                } elseif ($latest_value >= 1000) {
                                    echo number_format($latest_value / 1000, 1) . 'K';
                                } else {
                                    echo number_format($latest_value);
                                }
                            ?>
                        </div>
                        <small class="text-muted">Current value</small>
                        
                        <?php if ($first_month && $last_month): ?>
                        <div class="month-range">
                            <i class="bi bi-calendar-range"></i> 
                            <?php echo htmlspecialchars($first_month); ?> to <?php echo htmlspecialchars($last_month); ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="chart-canvas-container">
                            <canvas id="chart-<?php echo $key; ?>"></canvas>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info animate-fade-in-up">
                    <i class="bi bi-info-circle"></i> You don't have access to any chart parameters yet. Contact your administrator for access.
                </div>
                <?php endif; ?>

                <!-- Combined Chart -->
                <?php if (count($filtered_charts) > 1 && !empty($recent_chart_months)): ?>
                <div class="combined-chart animate-fade-in-up" style="animation-delay: 0.3s">
                    <div class="combined-chart-header">
                        <h3 class="section-title mb-0">
                            <i class="bi bi-bar-chart-line"></i> Combined Performance Overview
                            <span class="badge badge-info ms-2"><?php echo count($recent_chart_months); ?>-Month Trend</span>
                        </h3>
                        <div class="chart-controls">
                            <button class="chart-timeframe-btn active" onclick="setChartTimeframe('12m')">12M</button>
                            <button class="chart-timeframe-btn" onclick="setChartTimeframe('6m')">6M</button>
                            <button class="chart-timeframe-btn" onclick="setChartTimeframe('3m')">3M</button>
                        </div>
                    </div>
                    
                    <?php if (!empty($recent_chart_months)): ?>
                    <div class="month-range mb-3">
                        <i class="bi bi-calendar-range"></i> 
                        <?php echo htmlspecialchars($recent_chart_months[0]['name']); ?> to <?php echo htmlspecialchars($recent_chart_months[count($recent_chart_months)-1]['name']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="chart-canvas-container" style="height: 300px;">
                        <canvas id="combined-chart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Role Progress Section (Admin Only) -->
                <?php if (isAdmin() && !empty($role_progress)): ?>
                <div class="role-progress-section animate-fade-in-up" style="animation-delay: 0.4s">
                    <div class="section-header mb-4">
                        <div>
                            <h3 class="section-title">
                                <i class="bi bi-people-fill"></i> Role Progress - Current Month
                                <span class="badge badge-info ms-2">
                                    Overall: <?php echo $overall_stats['overall_percentage']; ?>% Complete
                                </span>
                            </h3>
                            <p class="text-muted mb-0">Track data entry progress by department/role. Progress updates automatically when users save data.</p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success">Completed</span>
                            <span class="badge bg-warning">In Progress</span>
                            <span class="badge bg-danger">Not Started</span>
                        </div>
                    </div>

                    <!-- Overall Stats Cards -->
                    <div class="stats-grid mb-4">
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-value"><?php echo $overall_stats['total_roles']; ?></div>
                            <div class="stat-label">Total Roles</div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $overall_stats['overall_percentage']; ?>%"></div>
                            </div>
                            <small><?php echo $overall_stats['completed_roles']; ?> completed, <?php echo $overall_stats['in_progress_roles']; ?> in progress</small>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon green">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-value"><?php echo $overall_stats['completed_roles']; ?></div>
                            <div class="stat-label">Completed Roles</div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $overall_stats['completed_roles'] > 0 ? ($overall_stats['completed_roles'] / $overall_stats['total_roles'] * 100) : 0; ?>%"></div>
                            </div>
                            <small>100% data submitted</small>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon orange">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-value"><?php echo $overall_stats['in_progress_roles']; ?></div>
                            <div class="stat-label">In Progress</div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $overall_stats['in_progress_roles'] > 0 ? ($overall_stats['in_progress_roles'] / $overall_stats['total_roles'] * 100) : 0; ?>%"></div>
                            </div>
                            <small>Partial data submitted</small>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon red">
                                <i class="bi bi-exclamation-circle"></i>
                            </div>
                            <div class="stat-value"><?php echo $overall_stats['not_started_roles']; ?></div>
                            <div class="stat-label">Not Started</div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $overall_stats['not_started_roles'] > 0 ? ($overall_stats['not_started_roles'] / $overall_stats['total_roles'] * 100) : 0; ?>%"></div>
                            </div>
                            <small>Awaiting data entry</small>
                        </div>
                    </div>

                    <!-- Role Progress Cards Grid -->
                    <div class="row mb-4">
                        <?php foreach ($role_progress as $role): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="role-progress-card <?php echo $role['status']; ?>">
                                <div class="role-progress-header">
                                    <div class="role-name">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </div>
                                    <span class="badge bg-<?php echo $role['status_badge']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $role['status'])); ?>
                                    </span>
                                </div>
                                
                                <?php if ($role['role_description']): ?>
                                <div class="role-description">
                                    <?php echo htmlspecialchars($role['role_description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="role-progress-details">
                                    <div>
                                        <div class="progress-percentage"><?php echo $role['percentage']; ?>%</div>
                                        <div class="role-parameters">
                                            <?php echo $role['saved_params']; ?> / <?php echo $role['total_params']; ?> parameters
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($role['last_updated']): ?>
                                            <div class="small text-muted">
                                                <i class="bi bi-clock"></i> <?php echo date('M d, H:i', strtotime($role['last_updated'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted">Not started</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-<?php echo $role['status_badge']; ?>" 
                                         style="width: <?php echo $role['percentage']; ?>%"></div>
                                </div>
                                
                                <?php if (!empty($role['users'])): ?>
                                <div class="role-users mt-3">
                                    <?php foreach ($role['users'] as $user): ?>
                                        <div class="user-avatar" title="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>">
                                            <?php echo strtoupper(substr($user['first_name'] ?? $user['username'], 0, 1)); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Progress Visualization -->
                    <div class="progress-visualization">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Progress by Role</h5>
                                <div style="height: 300px;">
                                    <canvas id="role-progress-chart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Parameters Completion</h5>
                                <div style="height: 300px;">
                                    <canvas id="parameter-progress-chart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php elseif (isAdmin()): ?>
                <div class="alert alert-info animate-fade-in-up">
                    <i class="bi bi-info-circle"></i> 
                    No active month found for progress tracking. Please create a new month in "Manage Months" to start tracking role progress.
                </div>
                <?php endif; ?>

                <!-- User Activity -->
                <?php if (!empty($userActivity)): ?>
                <div class="card animate-fade-in-up" style="animation-delay: 0.5s">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-clock-history"></i> Recent Activity</h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Status</th>
                                        <th>Entries</th>
                                        <th>Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userActivity as $activity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($activity['name']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $activity['status'] == 'submitted' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $activity['entries_count']; ?> entries</td>
                                        <td><?php echo $activity['last_updated'] ? date('M d, Y H:i', strtotime($activity['last_updated'])) : 'N/A'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <footer class="dashboard-footer mt-4">
                    <p>
                        <i class="bi bi-c-circle"></i> <?php echo date('Y'); ?> AquaTrack Pro - Water System Reporting Platform 
                        | Version 2.1.0 (Role-Based System)
                        | <span class="text-success"><i class="bi bi-check-circle"></i> All systems operational</span>
                        | Logged in as: <?php echo htmlspecialchars($user_info['username'] ?? 'User'); ?>
                    </p>
                </footer>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize individual parameter charts
            <?php foreach ($filtered_charts as $key => $chart_info): ?>
                <?php if (!empty($chart_data[$key])): ?>
                    initParameterChart('<?php echo $key; ?>', <?php echo json_encode($chart_data[$key]); ?>, '<?php echo $chart_info['color']; ?>', '<?php echo $chart_info['title']; ?>');
                <?php endif; ?>
            <?php endforeach; ?>

            // Initialize combined chart
            <?php if (count($filtered_charts) > 1 && !empty($recent_chart_months) && !empty($combined_chart_data)): ?>
                initCombinedChart(
                    <?php echo json_encode($recent_chart_months); ?>,
                    <?php echo json_encode($filtered_charts); ?>,
                    <?php echo json_encode($combined_chart_data); ?>
                );
            <?php endif; ?>

            // Initialize role progress charts (admin only)
            <?php if (isAdmin() && !empty($role_progress)): ?>
                initRoleProgressCharts(
                    <?php echo json_encode($role_progress); ?>,
                    <?php echo json_encode($overall_stats); ?>
                );
            <?php endif; ?>

            // Add animations and interactions
            initAnimations();
        });

        // Initialize parameter chart
        function initParameterChart(chartKey, chartData, chartColor, chartTitle) {
            const ctx = document.getElementById(`chart-${chartKey}`).getContext('2d');
            
            const labels = chartData.map(item => item.month_name.split(' ')[0]);
            const values = chartData.map(item => item.value);
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 200);
            gradient.addColorStop(0, `${chartColor}40`);
            gradient.addColorStop(1, `${chartColor}10`);
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: chartTitle,
                        data: values,
                        borderColor: chartColor,
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: chartColor,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: getChartOptions(chartTitle)
            });
        }

        // Initialize combined chart
        function initCombinedChart(months, charts, data) {
            const ctx = document.getElementById('combined-chart').getContext('2d');
            const monthNames = months.map(m => m.name.split(' ')[0]);
            
            // Organize data by parameter
            const datasets = [];
            const colors = {
                'active_connections': '#00ffff',
                'total_expenditure': '#ff6b6b',
                'total_revenue': '#4ade80',
                'water_production': '#00a8ff'
            };
            
            Object.keys(charts).forEach(chartKey => {
                const chart = charts[chartKey];
                const paramData = data.filter(d => d.parameter_id == chart.id);
                
                // Map values to months
                const values = months.map(month => {
                    const dataPoint = paramData.find(d => d.month_name === month.name);
                    return dataPoint ? dataPoint.value : 0;
                });
                
                const gradient = ctx.createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, `${colors[chartKey]}80`);
                gradient.addColorStop(1, `${colors[chartKey]}20`);
                
                datasets.push({
                    label: chart.title,
                    data: values,
                    borderColor: colors[chartKey],
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: colors[chartKey],
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                });
            });
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: monthNames,
                    datasets: datasets
                },
                options: {
                    ...getChartOptions('Combined Performance'),
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: 'rgba(255, 255, 255, 0.8)',
                                padding: 20,
                                usePointStyle: true,
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        // Initialize role progress charts
        function initRoleProgressCharts(roleProgress, overallStats) {
            // Role progress bar chart
            const roleCtx = document.getElementById('role-progress-chart')?.getContext('2d');
            if (roleCtx) {
                const roleNames = roleProgress.map(r => r.role_name);
                const percentages = roleProgress.map(r => r.percentage);
                const statusColors = roleProgress.map(r => {
                    switch(r.status) {
                        case 'completed': return '#10b981';
                        case 'in_progress': return '#f59e0b';
                        default: return '#ef4444';
                    }
                });
                
                new Chart(roleCtx, {
                    type: 'bar',
                    data: {
                        labels: roleNames,
                        datasets: [{
                            label: 'Completion %',
                            data: percentages,
                            backgroundColor: statusColors,
                            borderColor: statusColors,
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const role = roleProgress[context.dataIndex];
                                        return `${role.role_name}: ${role.percentage}% (${role.saved_params}/${role.total_params} parameters)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                grid: { color: 'rgba(255, 255, 255, 0.1)' },
                                ticks: { 
                                    color: 'rgba(255, 255, 255, 0.6)',
                                    callback: value => value + '%'
                                }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { 
                                    color: 'rgba(255, 255, 255, 0.6)',
                                    maxRotation: 45
                                }
                            }
                        }
                    }
                });
            }
            
            // Parameter progress doughnut chart
            const paramCtx = document.getElementById('parameter-progress-chart')?.getContext('2d');
            if (paramCtx && overallStats.total_parameters > 0) {
                const saved = overallStats.saved_parameters || 0;
                const remaining = overallStats.total_parameters - saved;
                
                new Chart(paramCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Saved Parameters', 'Remaining Parameters'],
                        datasets: [{
                            data: [saved, remaining],
                            backgroundColor: ['#10b981', '#6b7280'],
                            borderColor: ['#0f9a71', '#4b5563'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: 'rgba(255, 255, 255, 0.8)',
                                    padding: 20
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const percentage = overallStats.total_parameters > 0 ? 
                                            Math.round((value / overallStats.total_parameters) * 100) : 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        cutout: '70%'
                    }
                });
            }
        }

        // Get common chart options
        function getChartOptions(title) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(10, 22, 40, 0.9)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(255, 255, 255, 0.1)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                let value = context.parsed.y;
                                let formatted = value.toLocaleString();
                                
                                if (value >= 1000000) {
                                    formatted = (value / 1000000).toFixed(1) + 'M';
                                } else if (value >= 1000) {
                                    formatted = (value / 1000).toFixed(1) + 'K';
                                }
                                
                                return `${title}: ${formatted}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.6)',
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                }
                                if (value >= 1000) {
                                    return (value / 1000).toFixed(1) + 'K';
                                }
                                return value;
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.6)'
                        }
                    }
                }
            };
        }

        // Set chart timeframe
        function setChartTimeframe(timeframe) {
            const buttons = document.querySelectorAll('.chart-timeframe-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Here you would typically reload chart data for the selected timeframe
            console.log(`Switching to ${timeframe} timeframe`);
        }

        // Initialize animations
        function initAnimations() {
            // Add hover effects to cards
            const cards = document.querySelectorAll('.stat-card, .chart-card, .role-progress-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Auto-refresh role progress (every 30 seconds)
            <?php if (isAdmin()): ?>
            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    refreshRoleProgress();
                }
            }, 30000);
            <?php endif; ?>
        }

        // Refresh role progress data
        async function refreshRoleProgress() {
            try {
                const response = await fetch('?refresh=progress&nocache=' + Date.now());
                const html = await response.text();
                
                // Parse the HTML to extract progress data
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                // Update role progress cards
                const newCards = tempDiv.querySelectorAll('.role-progress-card');
                const currentCards = document.querySelectorAll('.role-progress-card');
                
                if (newCards.length === currentCards.length) {
                    currentCards.forEach((card, index) => {
                        const newCard = newCards[index];
                        // Update percentage
                        const newPercentage = newCard.querySelector('.progress-percentage').textContent;
                        const currentPercentage = card.querySelector('.progress-percentage');
                        if (currentPercentage.textContent !== newPercentage) {
                            currentPercentage.textContent = newPercentage;
                            currentPercentage.style.animation = 'pulse 1s';
                            setTimeout(() => {
                                currentPercentage.style.animation = '';
                            }, 1000);
                        }
                        
                        // Update progress bar
                        const newProgress = newCard.querySelector('.progress-bar').style.width;
                        card.querySelector('.progress-bar').style.width = newProgress;
                        
                        // Update status badge if needed
                        const newStatus = newCard.querySelector('.badge').textContent;
                        const currentStatus = card.querySelector('.badge');
                        if (currentStatus.textContent !== newStatus) {
                            currentStatus.textContent = newStatus;
                        }
                    });
                    
                    console.log('Role progress updated');
                }
            } catch (error) {
                console.log('Failed to refresh role progress:', error);
            }
        }

        // Confetti animation for achievements
        function createConfetti() {
            const confettiCount = 30;
            const container = document.querySelector('.dashboard-header');
            if (!container) return;
            
            for (let i = 0; i < confettiCount; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.innerHTML = ['🎉', '✨', '🌟', '💧', '💎'][Math.floor(Math.random() * 5)];
                    confetti.style.position = 'absolute';
                    confetti.style.fontSize = '20px';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.top = '0';
                    confetti.style.opacity = '0.8';
                    confetti.style.animation = `confettiFall ${1 + Math.random() * 2}s linear forwards`;
                    confetti.style.setProperty('--rotation', `${Math.random() * 360}deg`);
                    confetti.style.zIndex = '1';
                    confetti.style.pointerEvents = 'none';
                    
                    container.appendChild(confetti);
                    
                    setTimeout(() => confetti.remove(), 3000);
                }, i * 100);
            }
        }

        // Check for achievements
        function checkAchievements() {
            const lastVisit = localStorage.getItem('lastDashboardVisit');
            const today = new Date().toDateString();
            
            if (lastVisit !== today && <?php echo count($accessible_parameters) > 0 ? 'true' : 'false'; ?>) {
                createConfetti();
                localStorage.setItem('lastDashboardVisit', today);
            }
            
            // Check for 100% completion achievement
            <?php if (isAdmin() && isset($overall_stats['overall_percentage']) && $overall_stats['overall_percentage'] == 100): ?>
                if (!localStorage.getItem('100percentAchievement')) {
                    showAchievementToast('🏆 All Roles Completed!', 'All departments have submitted 100% of their data for this month!');
                    localStorage.setItem('100percentAchievement', 'true');
                }
            <?php endif; ?>
        }

        // Show achievement toast
        function showAchievementToast(title, message) {
            const toast = document.createElement('div');
            toast.className = 'toast-notification toast-success';
            toast.style.animation = 'slideInDown 0.5s ease-out, bounce 2s infinite';
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-trophy"></i>
                </div>
                <div>
                    <div class="toast-message" style="font-weight: bold;">${title}</div>
                    <div style="font-size: 12px; color: #666;">${message}</div>
                </div>
                <button class="toast-close">&times;</button>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
            
            toast.querySelector('.toast-close').addEventListener('click', () => {
                toast.remove();
            });
            
            return toast;
        }

        // Initialize achievements check
        checkAchievements();
    </script>
</body>
</html>