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
// Render through shared Tailwind layout
require_once __DIR__ . "/resources/views/reports/dashboard.php";
?>
