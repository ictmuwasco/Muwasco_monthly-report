<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'auth_functions.php';

// Check if user is logged in
session_start();
requireLogin();

// MonthlyReport Class
class MonthlyReport {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
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
        $activity = [];
        while ($row = $result->fetch_assoc()) {
            $activity[] = $row;
        }
        return $activity;
    }
    
    public function getChartDataForParameter($parameter_id, $months_limit = 12) {
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
                LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $parameter_id, $months_limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }
}

$report = new MonthlyReport($conn);
$recentMonths = $report->getRecentMonths();
$summaryStats = $report->getSummaryStats();
$userActivity = $report->getUserActivity();

// Get user info
$user_info = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT full_name, role, email, is_active FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_info = $result->fetch_assoc();
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
        'id' => 185,
        'title' => 'Water Production',
        'color' => '#00a8ff',
        'icon' => 'bi-droplet'
    ]
];

// Fetch data for each parameter
$chart_data = [];
foreach ($parameter_charts as $key => $chart_info) {
    $chart_data[$key] = $report->getChartDataForParameter($chart_info['id'], 6);
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
    <!-- Link to your global CSS -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="main-container">
        <!-- Navigation Bar -->
        <?php include 'nav_bar.php'; ?>
        
        <!-- Main Content Area -->
        <div class="main-content">
            <div class="page-content">
                    <!-- Header -->
                    <div class="dashboard-header">
                        <h1>Welcome back, <?php echo htmlspecialchars($user_info['full_name'] ?? 'User'); ?>! 👋</h1>
                        <p class="welcome-text">
                            Manage your water system reporting with powerful analytics and insights. 
                            Track performance, submit reports, and analyze data in real-time.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <span class="badge badge-success">
                                <i class="bi bi-check-circle"></i> System Status: <strong>Operational</strong>
                            </span>
                            <span class="badge badge-info">
                                <i class="bi bi-clock"></i> Last Login: <?php echo date('M d, Y H:i', strtotime($user_info['last_login'] ?? 'now')); ?>
                            </span>
                            <span class="badge badge-info">
                                <i class="bi bi-person-badge"></i> Role: <?php echo htmlspecialchars($user_info['role'] ?? 'User'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <i class="bi bi-calendar-month"></i>
                            </div>
                            <div class="stat-value"><?php echo $summaryStats['total_months']; ?></div>
                            <div class="stat-label">Total Months Tracked</div>
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
                            <div class="stat-value"><?php echo $summaryStats['total_parameters']; ?></div>
                            <div class="stat-label">Monitoring Parameters</div>
                            <div class="progress">
                                <div class="progress-bar" style="width: 100%"></div>
                            </div>
                            <small>Active tracking parameters</small>
                        </div>
                    </div>

                    <!-- Charts Grid -->
                    <div class="charts-grid">
                        <?php foreach ($parameter_charts as $key => $chart_info): 
                            $data = $chart_data[$key];
                            $latest_value = !empty($data) ? $data[0]['value'] : 0;
                            $prev_value = count($data) > 1 ? $data[1]['value'] : $latest_value;
                            $change = $prev_value != 0 ? (($latest_value - $prev_value) / $prev_value) * 100 : 0;
                        ?>
                        <div class="chart-card">
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
                            
                            <div class="chart-value"><?php echo number_format($latest_value); ?></div>
                            <small class="text-muted">Current value</small>
                            
                            <div style="height: 200px; margin-top: 20px;">
                                <canvas id="chart-<?php echo $key; ?>"></canvas>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Combined Chart -->
                    <div class="combined-chart">
                        <h3 class="section-title mb-4">
                            <i class="bi bi-bar-chart-line"></i> Combined Performance Overview
                        </h3>
                        <div style="height: 300px;">
                            <canvas id="combined-chart"></canvas>
                        </div>
                    </div>

                    <!-- Action Grid -->
                    <div class="action-grid">
                        <div class="action-card">
                            <div class="action-icon floating">
                                <i class="bi bi-pencil-square"></i>
                            </div>
                            <h3 class="action-title">Data Entry</h3>
                            <p class="action-description">
                                Enter monthly water system data with our intuitive form. 
                                Track progress and save drafts as you work.
                            </p>
                            <a href="add_data.php" class="btn btn-action pulse">
                                <i class="bi bi-arrow-right me-2"></i>Start Entry
                            </a>
                        </div>

                        <div class="action-card">
                            <div class="action-icon floating" style="animation-delay: 0.5s;">
                                <i class="bi bi-file-earmark-bar-graph"></i>
                            </div>
                            <h3 class="action-title">View Reports</h3>
                            <p class="action-description">
                                Analyze submitted reports with interactive charts and 
                                detailed analytics. Compare data across months.
                            </p>
                            <a href="reports.php" class="btn btn-action">
                                <i class="bi bi-bar-chart me-2"></i>View Reports
                            </a>
                        </div>
                    </div>

                    <!-- Activity & Quick Actions -->
                    <div class="activity-section">
                        <div class="activity-card">
                            <h3 class="section-title">
                                <i class="bi bi-clock-history"></i> Recent Activity
                            </h3>
                            <ul class="activity-list">
                                <?php if (!empty($userActivity)): ?>
                                    <?php foreach ($userActivity as $activity): ?>
                                    <li class="activity-item">
                                        <div class="activity-item-icon">
                                            <i class="bi bi-database"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title"><?php echo htmlspecialchars($activity['name']); ?></div>
                                            <div class="activity-date">
                                                <?php echo date('M d, Y', strtotime($activity['last_updated'])); ?> • 
                                                <?php echo $activity['entries_count']; ?> entries
                                            </div>
                                        </div>
                                        <span class="status-badge <?php echo $activity['status'] === 'submitted' ? 'status-submitted' : 'status-draft'; ?>">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                    </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="activity-item">
                                        <div class="activity-content">
                                            <div class="activity-title">No recent activity</div>
                                            <div class="activity-date">Get started by creating your first report</div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <div class="activity-card">
                            <h3 class="section-title">
                                <i class="bi bi-lightning"></i> Quick Actions
                            </h3>
                            <div class="quick-actions">
                                <a href="add_data.php" class="btn-quick">
                                    <i class="bi bi-plus-circle"></i> New Data Entry
                                </a>
                                <a href="reports.php" class="btn-quick">
                                    <i class="bi bi-eye"></i> Preview Reports
                                </a>
                                <a href="months.php" class="btn-quick">
                                    <i class="bi bi-calendar-check"></i> Manage Months
                                </a>
                                <a href="parameters.php" class="btn-quick">
                                    <i class="bi bi-gear"></i> Configure Parameters
                                </a>
                            </div>

                            <div class="system-info">
                                <h6>System Information</h6>
                                <div class="info-item">
                                    <span class="info-label">Current Version</span>
                                    <span class="info-value">v2.0.1</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Database Status</span>
                                    <span class="info-value">Connected</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Backup</span>
                                    <span class="info-value"><?php echo date('M d, Y'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Storage Used</span>
                                    <span class="info-value">1.2 GB / 10 GB</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="dashboard-footer">
                    <p>
                        <i class="bi bi-c-circle"></i> <?php echo date('Y'); ?> AquaTrack Pro - Water System Reporting Platform 
                        | Version 2.0.1 
                        | <span class="text-success"><i class="bi bi-check-circle"></i> All systems operational</span>
                    </p>
                </footer>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prepare chart data
            const chartConfigs = {
                active_connections: {
                    data: <?php echo json_encode($chart_data['active_connections']); ?>,
                    color: '#00ffff',
                    title: 'Active Connections'
                },
                total_expenditure: {
                    data: <?php echo json_encode($chart_data['total_expenditure']); ?>,
                    color: '#ff6b6b',
                    title: 'Monthly Expenditure'
                },
                total_revenue: {
                    data: <?php echo json_encode($chart_data['total_revenue']); ?>,
                    color: '#4ade80',
                    title: 'Revenue Collected'
                },
                water_production: {
                    data: <?php echo json_encode($chart_data['water_production']); ?>,
                    color: '#00a8ff',
                    title: 'Water Production'
                }
            };

            // Initialize individual charts
            Object.keys(chartConfigs).forEach(chartKey => {
                const config = chartConfigs[chartKey];
                const chartData = config.data.reverse();
                
                if (chartData.length > 0) {
                    const ctx = document.getElementById(`chart-${chartKey}`).getContext('2d');
                    
                    const labels = chartData.map(item => {
                        // Extract month name (first word)
                        return item.month_name.split(' ')[0];
                    });
                    
                    const values = chartData.map(item => parseFloat(item.value));
                    const unit = chartData[0]?.unit || '';
                    
                    const gradient = ctx.createLinearGradient(0, 0, 0, 200);
                    gradient.addColorStop(0, config.color + '40');
                    gradient.addColorStop(1, config.color + '10');
                    
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: config.title,
                                data: values,
                                borderColor: config.color,
                                backgroundColor: gradient,
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: config.color,
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: 'rgba(10, 22, 40, 0.9)',
                                    titleColor: '#fff',
                                    bodyColor: '#fff',
                                    borderColor: config.color,
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
                                            
                                            return `${config.title}: ${formatted} ${unit}`;
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
                            },
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            }
                        }
                    });
                }
            });

            // Combined Chart
            const combinedCtx = document.getElementById('combined-chart').getContext('2d');
            
            // Get all months that have data
            const allMonths = [];
            Object.keys(chartConfigs).forEach(key => {
                chartConfigs[key].data.forEach(item => {
                    if (!allMonths.includes(item.month_name)) {
                        allMonths.push(item.month_name);
                    }
                });
            });
            
            // Sort months by extracting month name
            allMonths.sort((a, b) => {
                const monthOrder = ['January', 'February', 'March', 'April', 'May', 'June', 
                                   'July', 'August', 'September', 'October', 'November', 'December'];
                const getMonthIndex = (name) => {
                    const month = name.split(' ')[0];
                    return monthOrder.indexOf(month);
                };
                return getMonthIndex(a) - getMonthIndex(b);
            });
            
            // Get last 6 months
            const recentMonths = allMonths.slice(-6);
            
            // Create datasets for combined chart
            const combinedDatasets = Object.keys(chartConfigs).map(key => {
                const config = chartConfigs[key];
                const dataMap = {};
                
                config.data.forEach(item => {
                    dataMap[item.month_name] = parseFloat(item.value);
                });
                
                const gradient = combinedCtx.createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, config.color + '80');
                gradient.addColorStop(1, config.color + '20');
                
                return {
                    label: config.title,
                    data: recentMonths.map(month => dataMap[month] || 0),
                    borderColor: config.color,
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: config.color,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                };
            });
            
            if (recentMonths.length > 0) {
                new Chart(combinedCtx, {
                    type: 'line',
                    data: {
                        labels: recentMonths.map(month => month.split(' ')[0]),
                        datasets: combinedDatasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: 'rgba(255, 255, 255, 0.8)',
                                    padding: 20,
                                    usePointStyle: true,
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(10, 22, 40, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                mode: 'index',
                                intersect: false
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
                                    color: 'rgba(255, 255, 255, 0.1)',
                                    drawBorder: false
                                },
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.6)'
                                }
                            }
                        }
                    }
                });
            }

            // Add hover effects
            const cards = document.querySelectorAll('.stat-card, .action-card, .chart-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Add confetti animation for first visit
            const lastVisit = localStorage.getItem('lastDashboardVisit');
            const today = new Date().toDateString();
            
            if (lastVisit !== today) {
                createConfetti();
                localStorage.setItem('lastDashboardVisit', today);
            }

            function createConfetti() {
                const confettiCount = 30;
                const container = document.querySelector('.dashboard-header');
                
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
        });
    </script>
</body>
</html>