<?php
require_once 'auth.php';
require_once 'db_connect.php';

$user_id        = $_SESSION['user_id'];
$username       = $_SESSION['username'];
$user_role      = $_SESSION['role'] ?? 'user';
$allowed_fleet  = strtoupper($_SESSION['allowed_fleet'] ?? '');
$is_fleet_locked = $_SESSION['is_fleet_locked'] ?? 0;

// === FLEET LOADING (UNCHANGED) ===
$fleetGroups = [];

if ($user_role === 'admin' || $allowed_fleet === 'ALL') {
    $tables = [
        'Airbus' => 'airbus_fleet',
        '787'    => 'fleet_787',
        '777'    => 'fleet_777',
        '737'    => 'fleet_737',
        'Cargo'  => 'cargo_fleet',
        'Q400'   => 'q400_fleet'
    ];
} else {
    $map = [
        'AIRBUS' => 'airbus_fleet',
        '787'    => 'fleet_787',
        '777'    => 'fleet_777',
        '737'    => 'fleet_737',
        'CARGO'  => 'cargo_fleet',
        'Q400'   => 'q400_fleet'
    ];
    $tables = $allowed_fleet && isset($map[$allowed_fleet]) ? [$allowed_fleet => $map[$allowed_fleet]] : [];
}

foreach ($tables as $fleetName => $table) {
    $stmt = $pdo->query("SELECT registration FROM `$table` WHERE registration IS NOT NULL AND registration != '' ORDER BY registration");
    $regs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($regs) $fleetGroups[$fleetName] = $regs;
}

// === ACTIVE DEFECTS LIST (Personal - unchanged) ===
if ($user_role === 'admin') {
    $stmt = $pdo->query("SELECT * FROM deferred_defects WHERE status = 'active' ORDER BY deferral_date DESC LIMIT 50");
} else {
    $stmt = $pdo->prepare("SELECT * FROM deferred_defects WHERE deferred_by_name = ? AND status = 'active' ORDER BY deferral_date DESC");
    $stmt->execute([$username]);
}
$active_defects = $stmt->fetchAll();

// === STATISTICS - NOW FLEET-SPECIFIC FOR NON-ADMINS ===
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

$isGlobalView = ($user_role === 'admin' || $allowed_fleet === 'ALL');

$whereFleet = $isGlobalView ? '' : " AND UPPER(fleet) = ?";

// Build stats with fleet filter
$stats = [];

if ($isGlobalView) {
    $stats['active']        = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE'")->fetchColumn();
    $stats['overdue']       = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE' AND due_date < '$today'")->fetchColumn();
    $stats['cleared_week']  = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= '$weekStart'")->fetchColumn();
    $stats['cleared_month'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= '$monthStart'")->fetchColumn();
    $stats['total']         = $pdo->query("SELECT COUNT(*) FROM deferred_defects")->fetchColumn();
} else {
    $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE' $whereFleet");
    $stmtActive->execute([$allowed_fleet]);
    $stats['active'] = $stmtActive->fetchColumn();

    $stmtOverdue = $pdo->prepare("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE' AND due_date < ? $whereFleet");
    $stmtOverdue->execute([$today, $allowed_fleet]);
    $stats['overdue'] = $stmtOverdue->fetchColumn();

    $stmtWeek = $pdo->prepare("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= ? $whereFleet");
    $stmtWeek->execute([$weekStart, $allowed_fleet]);
    $stats['cleared_week'] = $stmtWeek->fetchColumn();

    $stmtMonth = $pdo->prepare("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= ? $whereFleet");
    $stmtMonth->execute([$monthStart, $allowed_fleet]);
    $stats['cleared_month'] = $stmtMonth->fetchColumn();

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(fleet) = ?");
    $stmtTotal->execute([$allowed_fleet]);
    $stats['total'] = $stmtTotal->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DefTrack | Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.15.4/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            margin: 40px 0;
            align-items: stretch;
        }
        .stats-left {
            flex: 1;
            min-width: 300px;
        }
        .stats-right {
            flex: 1;
            min-width: 300px;
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border-top: 6px solid #234C6A;
        }
        .stat-item {
            background: #f8fbff;
            padding: 18px 22px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 5px solid;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.05rem;
        }
        .stat-item.active    { border-color: #e74c3c; }
        .stat-item.overdue   { border-color: #c0392b; }
        .stat-item.cleared-w { border-color: #27ae60; }
        .stat-item.cleared-m{ border-color: #2ecc71; }
        .stat-item.total     { border-color: #234C6A; }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #1a1a1a;
        }
        .stat-label {
            color: #456882;
            font-weight: 600;
        }
        .chart-container {
            position: relative;
            height: 380px;
            width: 100%;
        }
        .fleet-note {
            font-size: 0.9rem;
            color: #e67e22;
            font-style: italic;
            margin-top: 8px;
        }
        @media (max-width: 768px) {
            .stats-row { flex-direction: column; }
            .stats-right { order: -1; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><h2>DefTrack</h2></div>
        <div class="nav-user">
            <span><i class="fa fa-user"></i> <?= htmlspecialchars($username) ?>
                <?php if($user_role === 'admin'): ?> <small style="color:#a8e6cf;">(Admin)</small><?php endif; ?>
                <?php if(!$isGlobalView && $allowed_fleet): ?> <small style="color:#f39c12;">(<?= $allowed_fleet ?> Fleet)</small><?php endif; ?>
            </span>
            <a href="view_all_defects.php" class="btn-view">View All Defects</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container-compact">

        <!-- Messages -->
        <?php if (isset($_SESSION['add_defect_errors'])): ?>
            <div style="background:#ffe6e6; color:#c33; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #c33;">
                <strong>Please fix:</strong><br>
                <?php foreach ($_SESSION['add_defect_errors'] as $e): ?>• <?= htmlspecialchars($e) ?><br><?php endforeach; unset($_SESSION['add_defect_errors']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #28a745;">
                <?= htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <!-- ADD DEFECT FORM — ONLY MODIFIED AS REQUESTED -->
        <div class="card-compact">
            <h3 class="card-title">Add New Deferred Defect</h3>
            <form action="add_defect.php" method="POST" class="defect-form" onsubmit="return validateForm()">
                <?php
                $today = date('Y-m-d');
                $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
                ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Deferral Date <small style="color:#e74c3c;">(Today or last 2 days only)</small></label>
                        <input type="date" name="deferral_date" required min="<?= $twoDaysAgo ?>" max="<?= $today ?>" value="<?= $today ?>">
                    </div>
                    <div class="form-group">
                        <label>Fleet</label>
                        <select name="fleet" id="fleet" required onchange="filterAircraft()">
                            <option value="">Select Fleet</option>
                            <?php foreach(array_keys($fleetGroups) as $f): ?>
                                <option value="<?= $f ?>"><?= $f ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>A/C Registration</label>
                        <select name="ac_registration" id="ac_registration" required>
                            <option value="">Select Aircraft</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ADD Log No</label>
                        <input type="text" name="add_log_no" required>
                    </div>
                    <div class="form-group">
                        <label>Transferred from MNT Logbook</label>
                        <input type="text" name="transferred_from_mnt_logbook" placeholder="e.g. MNT-2025-001">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Source</label>
                        <select name="source" required>
                            <option>MEL</option><option>CDL</option><option>NON-MEL</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ATA + Seq No</label>
                        <input type="text" name="ata_seq" placeholder="e.g. 27-11-00-001" required>
                    </div>
                    <div class="form-group">
                        <label>MEL Category</label>
                        <select name="mel_category" id="mel_category" onchange="calculateDueDate()">
                            <option value="">-- Select --</option>
                            <option value="A">A → 24 Hours</option>
                            <option value="B">B → 3 Days</option>
                            <option value="C">C → 10 Days</option>
                            <option value="D">D → 120 Days</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="due_date" readonly style="background:#f0f8ff;">
                    </div>
                    <div class="form-group">
                        <label>Time Limit Source</label>
                        <select name="time_limit_source">
                            < option>NONE</option><option>AMM</option><option>SRM</option><option>OTHER</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ETOPS Effect</label>
                        <select name="etops_effect">
                            <option value="0">NONE</option>
                            <option value="1">NON ETOPS</option>
                            <option value="1">LIMITED ETOPS</option>
                        </select>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Autoland Restrictions (Select one only)</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="autoland_restriction" value="NONE"> NONE</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT II"> NO CAT II</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT IIIA"> NO CAT IIIA</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT IIIB"> NO CAT IIIB</label>
                    </div>
                </div>

                <div class="form-group" style="max-width: 300px;">
                    <label>TSFN</label>
                    <input type="text" name="tsfn" id="tsfn" placeholder="e.g. TSFN800xxxxx" maxlength="20">
                </div>

                <hr style="margin:20px 0; border-color:#E3E3E3;">

                <div class="form-group full">
                    <label>Reason for Deferral *</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="reason" value="PART" onclick="showReason('part')"> PART</label>
                        <label><input type="radio" name="reason" value="TOOL" onclick="showReason('tool')"> TOOL</label>
                        <label><input type="radio" name="reason" value="TIME" onclick="showReason('time')"> TIME</label>
                    </div>
                </div>

                <div id="part_fields" class="reason-fields">
                    <div class="form-grid">
                        <div class="form-group"><label>RID</label><input type="text" name="rid" id="rid" placeholder="e.g. RSV0500xxxxx"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1"></div>
                    </div>
                </div>

                <div id="tool_fields" class="reason-fields">
                    <div class="form-grid">
                        <div class="form-group"><label>Tool Name</label><input type="text" name="tool_name"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1"></div>
                    </div>
                </div>

                <div id="time_fields" class="reason-fields">
                    <div class="form-group"><label>Ground Time (hrs)</label><input type="number" step="0.1" name="ground_time_hours"></div>
                </div>

                <div class="form-group full">
                    <label>Defect Description *</label>
                    <textarea name="defect_desc" rows="3" required placeholder="Describe the defect clearly..."></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Deferred By</label>
                        <input type="text" name="deferred_by_name">
                    </div>
                    <div class="form-group">
                        <label>Your ID / Signature *</label>
                        <input type="text" name="id_signature" required placeholder="Your ID">
                    </div>
                </div>

                <div class="text-center" style="margin-top: 30px;">
                    <button type="submit" class="btn-submit-centered">
                        Submit Deferred Defect
                    </button>
                </div>
            </form>
        </div>

        <!-- FLEET-SPECIFIC STATS + PIE CHART -->
        <div class="stats-row">
            <div class="stats-left">
                <h3 style="margin:0 0 20px; color:#1B3C53;">
                    Defect Statistics 
                    <?php if (!$isGlobalView): ?>
                        <small class="fleet-note">— <?= htmlspecialchars($allowed_fleet) ?> Fleet Only</small>
                    <?php else: ?>
                        <small class="fleet-note">— All Fleets</small>
                    <?php endif; ?>
                </h3>
                <div class="stat-item active">
                    <span class="stat-label">Active Defects</span>
                    <span class="stat-number"><?= $stats['active'] ?></span>
                </div>
                <div class="stat-item overdue">
                    <span class="stat-label">Overdue Defects</span>
                    <span class="stat-number"><?= $stats['overdue'] ?></span>
                </div>
                <div class="stat-item cleared-w">
                    <span class="stat-label">Cleared This Week</span>
                    <span class="stat-number"><?= $stats['cleared_week'] ?></span>
                </div>
                <div class="stat-item cleared-m">
                    <span class="stat-label">Cleared This Month</span>
                    <span class="stat-number"><?= $stats['cleared_month'] ?></span>
                </div>
                <div class="stat-item total">
                    <span class="stat-label">Total Defects (All Time)</span>
                    <span class="stat-number"><?= $stats['total'] ?></span>
                </div>
            </div>

            <div class="stats-right">
                <h3 style="text-align:center; margin:0 0 20px; color:#1B3C53;">Status Distribution</h3>
                <div class="chart-container">
                    <canvas id="defectPieChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ACTIVE DEFECTS TABLE (Personal) -->
        <div class="card-compact">
            <h3 class="card-title">Your Active Deferred Defects (<?= count($active_defects) ?>)</h3>
            <?php if ($active_defects): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>A/C</th>
                            <th>Fleet</th>
                            <th>ATA</th>
                            <th>Description</th>
                            <th>Due</th>
                            <th>Reason</th>
                            <th style="width:150px; text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_defects as $d): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>
                            <td><strong><?= htmlspecialchars($d['ac_registration']) ?></strong></td>
                            <td><?= htmlspecialchars($d['fleet']) ?></td>
                            <td><?= htmlspecialchars($d['ata_seq']) ?></td>
                            <td><?= htmlspecialchars(substr($d['defect_desc'], 0, 50)) ?>...</td>
                            <td><?= date('d/m/Y', strtotime($d['due_date'])) ?></td>
                            <td><span class="status-active"><?= strtoupper($d['reason'] ?? 'N/A') ?></span></td>
                            <td class="actions-cell">
                                <a href="clear_defect.php?id=<?= $d['id'] ?>" class="btn-clear" title="Clear Defect">Clear</a>
                                <a href="edit_defect.php?id=<?= $d['id'] ?>" class="btn-edit" title="Edit Defect">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p class="no-data">No active deferred defects found.</p>
            <?php endif; ?>
        </div>

    </div>

    <!-- Client-side validation for RID and TSFN -->
    <script>
        function validateForm() {
            const rid = document.getElementById('rid').value.trim();
            const tsfn = document.getElementById('tsfn').value.trim();

            if (rid && !rid.startsWith('RSV0500')) {
                alert("RID must start with 'RSV0500'");
                return false;
            }
            if (tsfn && !tsfn.toUpperCase().startsWith('TSFN800')) {
                alert("TSFN must start with 'TSFN800'");
                return false;
            }
            return true;
        }

        const ctx = document.getElementById('defectPieChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Active', 'Overdue', 'Cleared This Week', 'Cleared This Month'],
                datasets: [{
                    data: [
                        <?= $stats['active'] ?>,
                        <?= $stats['overdue'] ?>,
                        <?= $stats['cleared_week'] ?>,
                        <?= $stats['cleared_month'] ?>
                    ],
                    backgroundColor: ['#e74c3c', '#c0392b', '#27ae60', '#2ecc71'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 20, font: { size: 13 } }
                    }
                }
            }
        });

        const fleetData = <?= json_encode($fleetGroups) ?>;

        function filterAircraft() {
            const fleet = document.getElementById('fleet').value;
            const acSelect = document.getElementById('ac_registration');
            acSelect.innerHTML = '<option value="">Select Aircraft</option>';
            if (fleet && fleetData[fleet]) {
                fleetData[fleet].forEach(reg => {
                    acSelect.innerHTML += `<option value="${reg}">${reg}</option>`;
                });
            }
        }

        function calculateDueDate() {
            const cat = document.getElementById('mel_category').value;
            const dueInput = document.getElementById('due_date');
            if (!cat) { dueInput.value = ''; return; }
            const days = {A:1, B:3, C:10, D:120};
            const d = new Date();
            d.setDate(d.getDate() + days[cat]);
            dueInput.value = d.toISOString().split('T')[0];
        }

        function showReason(type) {
            document.querySelectorAll('.reason-fields').forEach(el => el.style.display = 'none');
            if (type === 'part') document.getElementById('part_fields').style.display = 'grid';
            if (type === 'tool') document.getElementById('tool_fields').style.display = 'grid';
            if (type === 'time') document.getElementById('time_fields').style.display = 'block';
        }

        document.querySelectorAll('.reason-fields').forEach(el => el.style.display = 'none');
    </script>
</body>
</html>