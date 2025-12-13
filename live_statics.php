<?php
// defect_statistics.php - LIVE DEFECT DASHBOARD (Dedicated Page)
require_once 'db_connect.php';

// === FETCH LIVE STATS ===
function getStats($pdo) {
    $today = date('Y-m-d');
    $in3days = date('Y-m-d', strtotime('+3 days'));
    $in10days = date('Y-m-d', strtotime('+10 days'));

    $stats = [
        'overdue' => 0,
        'due_today' => 0,
        'due_3days' => 0,
        'due_10days' => 0,
        'safe' => 0,
        'total_active' => 0,
        'by_fleet' => []
    ];

    // Active defects breakdown
    $stmt = $pdo->query("
        SELECT 
            due_date,
            fleet
        FROM deferred_defects 
        WHERE status = 'active'
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $due = $row['due_date'];
        $fleet = $row['fleet'] ?: 'Unknown';

        $stats['total_active']++;
        $stats['by_fleet'][$fleet] = ($stats['by_fleet'][$fleet] ?? 0) + 1;

        if ($due < $today) {
            $stats['overdue']++;
        } elseif ($due == $today) {
            $stats['due_today']++;
        } elseif ($due <= $in3days) {
            $stats['due_3days']++;
        } elseif ($due <= $in10days) {
            $stats['due_10days']++;
        } else {
            $stats['safe']++;
        }
    }

    return $stats;
}

$stats = getStats($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Defect Statistics - DefTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: linear-gradient(135deg, #1B3C53 0%, #1B3C53 100%); min-height: 100vh; padding: 20px 0; }
        .dashboard-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden; }
        .card-header { background: #5a67d8; color: white; font-weight: bold; }
        .big-number { font-size: 3.5rem; font-weight: 800; }
        .chart-container { position: relative; height: 400px; }
        .fleet-bar { height: 350px; }
        .refresh-info { position: fixed; bottom: 20px; right: 30px; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 50px; font-size: 0.9rem; z-index: 1000; }
        @media (max-width: 768px) { .big-number { font-size: 2.5rem; } }
    
    </style>
</head>
<body>

<div class="container-fluid">

    <div class="text-center text-white mb-4">
        <h1 class="display-4 fw-bold"><i class="bi bi-graph-up-arrow"></i> Live Defect Statistics</h1>
        <p class="lead class="lead">Real-time overview • Auto-refreshes every 60 seconds</p>
    </div>

    <!-- MAIN STATS CARDS -->
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="card dashboard-card text-center p-4 border-danger border-4 border-opacity-50">
                <div class="card-body">
                    <h5 class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> OVERDUE</h5>
                    <div class="big-number text-danger"><?= number_format($stats['overdue']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card text-center p-4 border-warning border-4">
                <div class="card-body">
                    <h5 class="text-warning fw-bold"><i class="bi bi-calendar-day"></i> DUE TODAY</h5>
                    <div class="big-number text-warning"><?= number_format($stats['due_today']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card text-center p-4 border-primary border-4">
                <div class="card-body">
                    <h5 class="text-primary fw-bold"><i class="bi bi-calendar-week"></i> DUE IN 3 DAYS</h5>
                    <div class="big-number text-primary"><?= number_format($stats['due_3days']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dashboard-card text-center p-4 border-success border-4">
                <div class="card-body">
                    <h5 class="text-success fw-bold"><i class="bi bi-calendar-range"></i> DUE IN 10 DAYS</h5>
                    <div class="big-number text-success"><?= number_format($stats['due_10days']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- PIE CHART -->
        <div class="col-lg-6">
            <div class="card dashboard-card">
                <div class="card-header text-center fs-5">
                    <i class="bi bi-pie-chart-fill"></i> Due Date Distribution
                </div>
                <div class="card-body p-4">
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- BAR CHART BY FLEET -->
        <div class="col-lg-6">
            <div class="card dashboard-card">
                <div class="card-header text-center fs-5">
                    <i class="bi bi-airplane-engines"></i> Active Defects by Fleet
                </div>
                <div class="card-body p-4">
                    <div class="chart-container fleet-bar">
                        <canvas id="barChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-5 text-white">
        <h4>Total Active Defects: <strong><?= number_format($stats['total_active']) ?></strong></h4>
    </div>
</div>

<!-- Refresh Info -->
<div class="refresh-info">
    <i class="bi bi-clock"></i> <span id="clock"><?= date('H:i:s') ?></span> 
    • Next refresh in <span id="countdown">60</span>s
</div>

<script>
// Live clock + countdown
let seconds = 60;
setInterval(() => {
    const now = new Date();
    document.getElementById('clock').textContent = now.toLocaleTimeString();
    seconds--;
    document.getElementById('countdown').textContent = seconds;
    if (seconds <= 0) location.reload();
}, 1000);

// Pie Chart
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Overdue', 'Due Today', 'Due in 3 Days', 'Due in 10 Days', 'Safe (>10 days)'],
        datasets: [{
            data: [<?= $stats['overdue'] ?>, <?= $stats['due_today'] ?>, <?= $stats['due_3days'] ?>, <?= $stats['due_10days'] ?>, <?= $stats['safe'] ?>],
            backgroundColor: ['#e74c3c', '#f39c12', '#f1c40f', '#2ecc71', '#95a5a6'],
            borderWidth: 3,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 20 } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed } }
        }
    }
});

// Bar Chart by Fleet
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($stats['by_fleet'])) ?>,
        datasets: [{
            label: 'Defects',
            data: <?= json_encode(array_values($stats['by_fleet'])) ?>,
            backgroundColor: '#5a67d8',
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { display: false } },
            x: { grid: { display: false } }
        }
    }
});
</script>

</body>
</html>