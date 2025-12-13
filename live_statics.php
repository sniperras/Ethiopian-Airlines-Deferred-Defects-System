<?php
// defect_statistics.php - LIVE DEFECT DASHBOARD (Dedicated Page)
require_once 'db_connect.php';

function getStats($pdo) {
    $today = date('Y-m-d');
    $in3days = date('Y-m-d', strtotime('+3 days'));
    $in10days = date('Y-m-d', strtotime('+10 days'));

    $cache_dir = sys_get_temp_dir();
    $cache_file = $cache_dir . '/deftrack_stats_cache.json';
    $cache_time = 60;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        return json_decode(file_get_contents($cache_file), true);
    }

    $stats = [
        'overdue' => 0, 'due_today' => 0, 'due_3days' => 0, 'due_10days' => 0, 'safe' => 0,
        'total_active' => 0,
        'by_fleet' => [], 'by_fleet_aircraft' => [],
        'by_time_limit_source' => [], 'by_fleet_time_limit_source' => [],
        'by_mel_category' => [], 'by_fleet_mel_category' => []
    ];

    // Due date buckets
    $bucket_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_active,
            SUM(CASE WHEN due_date < :today THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN due_date = :today THEN 1 ELSE 0 END) AS due_today,
            SUM(CASE WHEN due_date > :today AND due_date <= :in3days THEN 1 ELSE 0 END) AS due_3days,
            SUM(CASE WHEN due_date > :in3days AND due_date <= :in10days THEN 1 ELSE 0 END) AS due_10days,
            SUM(CASE WHEN due_date > :in10days THEN 1 ELSE 0 END) AS safe
        FROM deferred_defects WHERE status = 'active'
    ");
    $bucket_stmt->execute(['today' => $today, 'in3days' => $in3days, 'in10days' => $in10days]);
    $buckets = $bucket_stmt->fetch(PDO::FETCH_ASSOC);
    foreach ($buckets as $k => $v) $stats[$k] = (int)$v;

    // By fleet
    $fleet_stmt = $pdo->prepare("SELECT COALESCE(fleet, 'Unknown') AS fleet, COUNT(*) AS cnt FROM deferred_defects WHERE status = 'active' GROUP BY fleet");
    $fleet_stmt->execute();
    while ($row = $fleet_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_fleet'][$row['fleet']] = $row['cnt'];
    }

    // By aircraft per fleet
    $aircraft_stmt = $pdo->prepare("SELECT COALESCE(fleet, 'Unknown') AS fleet, COALESCE(ac_registration, 'Unknown') AS ac, COUNT(*) AS cnt FROM deferred_defects WHERE status = 'active' GROUP BY fleet, ac_registration");
    $aircraft_stmt->execute();
    while ($row = $aircraft_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_fleet_aircraft'][$row['fleet']][$row['ac']] = $row['cnt'];
    }

    // Time Limit Source
    $tls_stmt = $pdo->prepare("SELECT COALESCE(fleet, 'Unknown') AS fleet, COALESCE(time_limit_source, 'Unknown') AS tls, COUNT(*) AS cnt FROM deferred_defects WHERE status = 'active' GROUP BY fleet, time_limit_source");
    $tls_stmt->execute();
    while ($row = $tls_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_fleet_time_limit_source'][$row['fleet']][$row['tls']] = $row['cnt'];
        $stats['by_time_limit_source'][$row['tls']] = ($stats['by_time_limit_source'][$row['tls']] ?? 0) + $row['cnt'];
    }

    // MEL Category
    $mel_stmt = $pdo->prepare("SELECT COALESCE(fleet, 'Unknown') AS fleet, COALESCE(mel_category, 'Unknown') AS mel, COUNT(*) AS cnt FROM deferred_defects WHERE status = 'active' GROUP BY fleet, mel_category");
    $mel_stmt->execute();
    while ($row = $mel_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['by_fleet_mel_category'][$row['fleet']][$row['mel']] = $row['cnt'];
        $stats['by_mel_category'][$row['mel']] = ($stats['by_mel_category'][$row['mel']] ?? 0) + $row['cnt'];
    }

    file_put_contents($cache_file, json_encode($stats));
    return $stats;
}

$stats = getStats($pdo);

$fleets = array_keys($stats['by_fleet']);
sort($fleets);

$color_palette = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#FF5733', '#C70039', '#900C3F', '#581845', '#2ECC71', '#3498DB', '#9B59B6', '#F1C40F', '#E67E22', '#E74C3C', '#95A5A6', '#BDC3C7', '#7F8C8D'];

// Time Limit Source datasets
$all_tls = array_keys($stats['by_time_limit_source']);
sort($all_tls);
$tls_colors = [];
$i = 0;
foreach ($all_tls as $tls) $tls_colors[$tls] = $color_palette[$i++ % count($color_palette)];
$tls_pie_colors = array_values(array_map(fn($tls) => $tls_colors[$tls], $all_tls));

$tls_datasets = [];
foreach ($all_tls as $tls) {
    $data = [];
    foreach ($fleets as $fleet) $data[] = $stats['by_fleet_time_limit_source'][$fleet][$tls] ?? 0;
    $tls_datasets[] = ['label' => $tls, 'data' => $data, 'backgroundColor' => $tls_colors[$tls]];
}

// MEL Category datasets
$all_mel = array_keys($stats['by_mel_category']);
sort($all_mel);
$mel_colors = [];
$i = 0;
foreach ($all_mel as $mel) $mel_colors[$mel] = $color_palette[$i++ % count($color_palette)];
$mel_pie_colors = array_values(array_map(fn($mel) => $mel_colors[$mel], $all_mel));

$mel_datasets = [];
foreach ($all_mel as $mel) {
    $data = [];
    foreach ($fleets as $fleet) $data[] = $stats['by_fleet_mel_category'][$fleet][$mel] ?? 0;
    $mel_datasets[] = ['label' => $mel, 'data' => $data, 'backgroundColor' => $mel_colors[$mel]];
}
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
        body { background: linear-gradient(135deg, #1B3C53 0%, #1B3C53 100%); min-height: 100vh; padding: 10px 0; }
        .dashboard-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); overflow: hidden; }
        .card-header { background: #5a67d8; color: white; font-weight: bold; }
        .big-number { font-size: 2.5rem; font-weight: 700; }
        .refresh-info { position: fixed; bottom: 20px; right: 30px; background: rgba(0,0,0,0.7); color: white; padding: 10px 20px; border-radius: 50px; font-size: 0.9rem; z-index: 1000; }
        @media (max-width: 768px) { .big-number { font-size: 1.8rem; } }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="text-center text-white mb-4">
        <h1 class="display-5 fw-bold"><i class="bi bi-graph-up-arrow"></i> Live Defect Statistics</h1>
        <p class="lead">Real-time overview • Auto-refreshes every 60 seconds</p>
    </div>

    <!-- MAIN STATS CARDS -->
    <div class="row g-4 mb-5">
        <div class="col-md-3"><div class="card dashboard-card text-center p-4 border-danger border-4 border-opacity-50"><div class="card-body"><h5 class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> OVERDUE</h5><div class="big-number text-danger"><?= number_format($stats['overdue']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card dashboard-card text-center p-4 border-warning border-4"><div class="card-body"><h5 class="text-warning fw-bold"><i class="bi bi-calendar-day"></i> DUE TODAY</h5><div class="big-number text-warning"><?= number_format($stats['due_today']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card dashboard-card text-center p-4 border-primary border-4"><div class="card-body"><h5 class="text-primary fw-bold"><i class="bi bi-calendar-week"></i> DUE IN 3 DAYS</h5><div class="big-number text-primary"><?= number_format($stats['due_3days']) ?></div></div></div></div>
        <div class="col-md-3"><div class="card dashboard-card text-center p-4 border-success border-4"><div class="card-body"><h5 class="text-success fw-bold"><i class="bi bi-calendar-range"></i> DUE IN 10 DAYS</h5><div class="big-number text-success"><?= number_format($stats['due_10days']) ?></div></div></div></div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-6"><div class="card dashboard-card"><div class="card-header text-center fs-5"><i class="bi bi-pie-chart-fill"></i> Due Date Distribution</div><div class="card-body p-4"><div style="height:300px"><canvas id="pieChart"></canvas></div></div></div></div>
        <div class="col-lg-6"><div class="card dashboard-card"><div class="card-header text-center fs-5"><i class="bi bi-airplane-engines"></i> Active Defects by Fleet</div><div class="card-body p-4"><div style="height:300px"><canvas id="barChart"></canvas></div></div></div></div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-12"><div class="card dashboard-card"><div class="card-header text-center fs-5"><i class="bi bi-airplane-fill"></i> Aircraft with Most Defects per Fleet</div><div class="card-body p-4">
            <table class="table table-striped"><thead><tr><th>Fleet</th><th>Aircraft</th><th>Defect Count</th></tr></thead><tbody>
                <?php foreach ($fleets as $fleet): ?>
                    <?php if (!empty($stats['by_fleet_aircraft'][$fleet] ?? [])): 
                        $counts = $stats['by_fleet_aircraft'][$fleet];
                        $max_count = max($counts);
                        $max_ac = array_search($max_count, $counts);
                    ?>
                        <tr><td><?= htmlspecialchars($fleet) ?></td><td><?= htmlspecialchars($max_ac) ?></td><td><?= number_format($max_count) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody></table>
        </div></div></div>
    </div>

    <!-- TIME LIMIT SOURCE -->
    <div class="row g-4 mb-5">
        <div class="col-lg-6"><div class="card dashboard-card h-100"><div class="card-header text-center fs-5"><i class="bi bi-pie-chart-fill"></i> Overall Time Limit Source Distribution</div><div class="card-body p-4"><div style="position:relative;height:420px"><canvas id="tlsPieChart"></canvas></div></div></div></div>
        <div class="col-lg-6"><div class="card dashboard-card h-100"><div class="card-header text-center fs-5"><i class="bi bi-bar-chart-fill"></i> Time Limit Source per Fleet (Proportions)</div><div class="card-body p-4"><div style="position:relative;height:420px"><canvas id="tlsBarChart"></canvas></div></div></div></div>
    </div>

    <!-- MEL CATEGORY -->
    <div class="row g-4 mb-5">
        <div class="col-lg-6"><div class="card dashboard-card h-100"><div class="card-header text-center fs-5"><i class="bi bi-pie-chart-fill"></i> Overall MEL Category Distribution</div><div class="card-body p-4"><div style="position:relative;height:420px"><canvas id="melPieChart"></canvas></div></div></div></div>
        <div class="col-lg-6"><div class="card dashboard-card h-100"><div class="card-header text-center fs-5"><i class="bi bi-bar-chart-fill"></i> MEL Category per Fleet</div><div class="card-body p-4"><div style="position:relative;height:420px"><canvas id="melBarChart"></canvas></div></div></div></div>
    </div>

    <div class="text-center mt-5 text-white">
        <h4>Total Active Defects: <strong><?= number_format($stats['total_active']) ?></strong></h4>
    </div>
</div>

<div class="refresh-info">
    <i class="bi bi-clock"></i> <span id="clock"><?= date('H:i:s') ?></span> • Next refresh in <span id="countdown">60</span>s
</div>

<script>
// Clock & auto-refresh
let seconds = 60;
setInterval(() => {
    const now = new Date();
    document.getElementById('clock').textContent = now.toLocaleTimeString();
    seconds--;
    document.getElementById('countdown').textContent = seconds;
    if (seconds <= 0) location.reload();
}, 1000);

// Due Date Pie
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: { labels: ['Overdue', 'Due Today', 'Due in 3 Days', 'Due in 10 Days', 'Safe (>10 days)'],
        datasets: [{ data: [<?= $stats['overdue'] ?>, <?= $stats['due_today'] ?>, <?= $stats['due_3days'] ?>, <?= $stats['due_10days'] ?>, <?= $stats['safe'] ?>],
            backgroundColor: ['#e74c3c', '#f39c12', '#f1c40f', '#2ecc71', '#95a5a6'], borderWidth: 3, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { padding: 20 } } }
    }
});

// Fleet Bar
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($fleets) ?>, datasets: [{ label: 'Defects', data: <?= json_encode(array_values($stats['by_fleet'])) ?>, backgroundColor: '#5a67d8', borderRadius: 8 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true }, x: { grid: { display: false } } }
    }
});

// TLS Pie - Legend on right
new Chart(document.getElementById('tlsPieChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($all_tls) ?>, datasets: [{ data: <?= json_encode(array_values($stats['by_time_limit_source'])) ?>, backgroundColor: <?= json_encode($tls_pie_colors) ?>, borderWidth: 3, borderColor: '#fff' }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', align: 'center', labels: { padding: 20, usePointStyle: true, pointStyle: 'circle' } } }
    }
});

// TLS Stacked Bar
new Chart(document.getElementById('tlsBarChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($fleets) ?>, datasets: <?= json_encode($tls_datasets) ?> },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
    }
});

// MEL Pie - Legend on right
new Chart(document.getElementById('melPieChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($all_mel) ?>, datasets: [{ data: <?= json_encode(array_values($stats['by_mel_category'])) ?>, backgroundColor: <?= json_encode($mel_pie_colors) ?>, borderWidth: 3, borderColor: '#fff' }] },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', align: 'center', labels: { padding: 20, usePointStyle: true, pointStyle: 'circle' } } }
    }
});

// MEL Stacked Bar
new Chart(document.getElementById('melBarChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($fleets) ?>, datasets: <?= json_encode($mel_datasets) ?> },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
    }
});
</script>
</body>
</html>