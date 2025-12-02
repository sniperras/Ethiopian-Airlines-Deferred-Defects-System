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
    <link rel="stylesheet" href="assets/css/dashboard.css?v=11">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Layout helpers and design tokens used across the page */
        :root {
            --bg: #1B3C53;
            --card-bg: #ffffff;
            --brand: #234C6A;
            --muted: #456882;
            --accent: #e67e22;
            --danger: #c0392b;
            --success: #27ae60;
            --soft: rgba(0, 0, 0, 0.06);
        }

        html,
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial;
            background: var(--bg);
            margin: 0;
            padding: 0;
            color: #0f1724;
        }

        nav.top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--card-bg);
            padding: 14px 24px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
            border-bottom: 4px solid var(--brand);
        }

        nav .nav-brand h2 {
            margin: 0;
            color: var(--brand);
        }

        nav .nav-user {
            font-size: 0.95rem;
            display: flex;
            gap: 12px;
            align-items: center;
            color: #1B3C53;
        }

        .container-compact {
            max-width: 1180px;
            margin: 26px auto;
            padding: 0 16px;
        }

        .card-compact {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
            margin-bottom: 28px;
        }

        .card-title {
            margin: 0 0 18px;
            color: var(--brand);
        }

        /* Form grid and small components */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-weight: 600;
            color: var(--muted);
            font-size: 0.95rem;
        }

        input[type="text"],
        input[type="date"],
        input[type="number"],
        select,
        textarea {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e3eef6;
            background: #fcfeff;
            font-size: 0.95rem;
            box-shadow: none;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .radio-inline {
            display: flex;
            gap: 18px;
            align-items: center;
            flex-wrap: wrap;
        }

        .radio-inline label {
            font-weight: 600;
            color: #314a5a;
        }

        .text-center {
            text-align: center;
        }

        .btn-submit-centered {
            background: var(--brand);
            color: #fff;
            border: none;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 8px 22px rgba(35, 76, 106, 0.12);
        }

        /* Stats styling (re-used from original) */
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
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            border-top: 6px solid var(--brand);
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

        .stat-item.active {
            border-color: #e74c3c;
        }

        .stat-item.overdue {
            border-color: #c0392b;
        }

        .stat-item.cleared-w {
            border-color: #27ae60;
        }

        .stat-item.cleared-m {
            border-color: #2ecc71;
        }

        .stat-item.total {
            border-color: var(--brand);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #1a1a1a;
        }

        .stat-label {
            color: var(--muted);
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 380px;
            width: 100%;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .table th,
        .table td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #eef6fb;
            font-size: 0.95rem;
        }

        .table thead th {
            font-weight: 700;
            color: #2d4452;
        }

        .actions-cell {
            text-align: center;
        }

        .btn-clear,
        .btn-edit {
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .btn-clear {
            background: #08CB00;
            color: #ffffff;
            margin-right: 8px;
        }

        .btn-edit {
            background: #d9edf7;
            color: #135b73;
        }

        .no-data {
            color: #5b7079;
            padding: 14px;
        }

        /* Responsive adjustments */
        @media (max-width: 980px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 680px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                flex-direction: column;
            }

            .stats-right {
                order: -1;
                margin-bottom: 20px;
            }
        }
    </style>
</head>

<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <h2>DefTrack</h2>
        </div>
        <div class="nav-user">
            <span>User: <?= htmlspecialchars($username) ?>
                <?php if ($user_role === 'admin'): ?> <small style="color:#1B3C53;">(Admin)</small><?php endif; ?>
                <?php if (!$isGlobalView && $allowed_fleet): ?> <small style="color:#f39c12;">(<?= $allowed_fleet ?> Fleet)</small><?php endif; ?>
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
                <?php foreach ($_SESSION['add_defect_errors'] as $e): ?>• <?= htmlspecialchars($e) ?><br><?php endforeach;
                                                                                                        unset($_SESSION['add_defect_errors']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div style="background:#d4edda; color:#155724; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #28a745;">
                <?= htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <!-- ADD DEFECT FORM — MODIFIED: hides & disables non-selected reason inputs -->
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
                            <?php foreach (array_keys($fleetGroups) as $f): ?>
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
                            <option value="MEL">MEL</option>
                            <option value="CDL">CDL</option>
                            <option value="NON-MEL">NON-MEL</option>
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
                            <option>NONE</option>
                            <option>AMM</option>
                            <option>SRM</option>
                            <option>OTHER</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ETOPS Effect</label>
                        <select name="etops_effect">
                            <option value="0">NONE</option>
                            <option value="1">NON ETOPS</option>
                            <option value="2">LIMITED ETOPS</option>
                        </select>
                    </div>
                </div>

                <div class="form-group full">
                    <label>Autoland Restrictions (Select one only)</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="autoland_restriction" value="NONE" checked> NONE</label>
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

                <!-- PART Fields -->
                <div id="part_fields" class="reason-fields" aria-hidden="true">
                    <div class="form-grid">
                        <div class="form-group"><label>RID</label><input type="text" name="rid" id="rid" placeholder="e.g. RSV0500xxxxx"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1" step="1"></div>
                    </div>
                </div>

                <!-- TOOL Fields -->
                <div id="tool_fields" class="reason-fields" aria-hidden="true">
                    <div class="form-grid">
                        <div class="form-group"><label>Tool Name</label><input type="text" name="tool_name"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1" step="1"></div>
                    </div>
                </div>

                <!-- TIME Fields -->
                <div id="time_fields" class="reason-fields" aria-hidden="true">
                    <div class="form-group"><label>Ground Time (hrs)</label><input type="number" step="0.1" name="ground_time_hours" min="0" placeholder="e.g. 1.5"></div>
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
                    <table class="table" style="border-collapse:separate; border-spacing:0;">
                        <thead>
                            <tr style="background:#234C6A; color:#ffffff; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                                <th style="padding:14px 16px; border-top-left-radius:10px; color: #ffffff;">Date</th>
                                <th style="padding:14px 16px; color: #ffffff">A/C</th>
                                <th style="padding:14px 16px; color: #ffffff">Fleet</th>
                                <th style="padding:14px 16px; color: #ffffff">ATA</th>
                                <th style="padding:14px 16px; color: #ffffff">Description</th>
                                <th style="padding:14px 16px; color: #ffffff">Status</th>
                                <th style="padding:14px 16px; color: #ffffff">Due</th>
                                <th style="padding:14px 16px; color: #ffffff">Reason</th>
                                <th style="padding:14px 16px; border-top-right-radius:10px; text-align:center; color: #ffffff">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_defects as $d): ?>
                                <tr style="background:#ffffff; border-bottom:1px solid #eef6fb;">
                                    <td style="padding:14px 16px;"><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>
                                    <td style="padding:14px 16px; font-weight:600; color:#234C6A;"><strong><?= htmlspecialchars($d['ac_registration']) ?></strong></td>
                                    <td style="padding:14px 16px;"><?= htmlspecialchars($d['fleet']) ?></td>
                                    <td style="padding:14px 16px;"><?= htmlspecialchars($d['ata_seq']) ?></td>
                                    <td style="padding:14px 16px;">
                                        <?= htmlspecialchars(substr($d['defect_desc'], 0, floor(strlen($d['defect_desc'])))) ?>...
                                    </td>


                                    <!-- RED "ACTIVE" BADGE — SAME STYLE AS PART -->
                                    <td style="padding:14px 16px;">
                                        <span style="background:#e74c3c; color:#ffffff; padding:6px 14px; border-radius:20px; font-size:0.85rem; font-weight:700; text-transform:uppercase;">
                                            Active
                                        </span>
                                    </td>

                                    <td style="padding:14px 16px;"><?= date('d/m/Y', strtotime($d['due_date'])) ?></td>

                                    <!-- REASON BADGE (PART=red, TOOL=orange, TIME=green) -->
                                    <td style="padding:14px 16px;">
                                        <?php
                                        $reason = strtoupper($d['reason'] ?? 'N/A');
                                        $reasonColor = $reason === 'PART' ? '#e74c3c' : ($reason === 'TOOL' ? '#e67e22' : '#27ae60');
                                        ?>
                                        <span style="background:<?= $reasonColor ?>; color:#fff; padding:6px 14px; border-radius:20px; font-size:0.85rem; font-weight:700;">
                                            <?= $reason ?>
                                        </span>
                                    </td>

                                    <td class="actions-cell" style="padding:14px 16px; text-align:center;">
                                        <a href="clear_defect.php?id=<?= $d['id'] ?>"
                                            style="background:#27ae60; color:white; padding:8px 16px; border-radius:8px; text-decoration:none; font-weight:600; margin:0 4px; display:inline-block;">
                                            Clear
                                        </a>
                                        <a href="edit_defect.php?id=<?= $d['id'] ?>"
                                            style="background:#d1ecf1; color:#0c5460; padding:8px 16px; border-radius:8px; text-decoration:none; font-weight:600; margin:0 4px; display:inline-block;">
                                            Edit
                                        </a>
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

    <!-- CLIENT-SIDE JAVASCRIPT: validation + improved reason handling
         Important behavior:
         - Hidden reason panels are disabled (their inputs won't be submitted)
         - Visible panel inputs are enabled and marked required where appropriate
         - Client-side validation mirrors important server-side checks (RID, TSFN, qty > 0, ground time > 0)
    -->
    <script>
        // Helper to enable/disable and toggle required attributes inside a container.
        function setDisabledForContainer(container, disabled) {
            if (!container) return;
            const inputs = container.querySelectorAll('input, select, textarea');
            inputs.forEach(el => {
                // We only disable input controls. Buttons or other elements are not inside these containers.
                el.disabled = disabled;
                // Maintain ARIA-hidden for accessibility
                if (disabled) {
                    container.setAttribute('aria-hidden', 'true');
                    el.removeAttribute('required');
                } else {
                    container.removeAttribute('aria-hidden');
                }
            });
        }

        // Show only the selected reason container, hide & disable the others
        function showReason(type) {
            const reasonContainers = [{
                    id: 'part_fields',
                    typeKey: 'part'
                },
                {
                    id: 'tool_fields',
                    typeKey: 'tool'
                },
                {
                    id: 'time_fields',
                    typeKey: 'time'
                }
            ];

            reasonContainers.forEach(rc => {
                const el = document.getElementById(rc.id);
                if (!el) return;
                if (rc.typeKey === type) {
                    if (rc.id === 'time_fields') {
                        el.style.display = 'block';
                    } else {
                        el.style.display = 'grid';
                    }
                    setDisabledForContainer(el, false);

                    // Set required for key inputs inside visible container
                    el.querySelectorAll('input, textarea, select').forEach(inp => {
                        // Decide which fields should be required in visible panel
                        if (inp.name === 'part_qty' || inp.name === 'ground_time_hours' || inp.name === 'rid' || inp.name === 'tool_name') {
                            // mark the important ones as required for UX
                            inp.setAttribute('required', 'required');
                        }
                    });
                } else {
                    el.style.display = 'none';
                    setDisabledForContainer(el, true);
                }
            });

            // update radio selection (useful when called programmatically)
            document.querySelectorAll("input[name='reason']").forEach(r => {
                if ((type === 'part' && r.value === 'PART') ||
                    (type === 'tool' && r.value === 'TOOL') ||
                    (type === 'time' && r.value === 'TIME')) {
                    r.checked = true;
                } else {
                    r.checked = false;
                }
            });
        }

        // Client-side overall form validation before submit
        function validateForm() {
            // Basic RID/TSFN checks (mirrors server)
            const ridEl = document.getElementById('rid');
            const tsfnEl = document.getElementById('tsfn');

            const rid = ridEl ? ridEl.value.trim().toUpperCase() : '';
            const tsfn = tsfnEl ? tsfnEl.value.trim().toUpperCase() : '';

            if (rid && !rid.startsWith('RSV0500')) {
                alert("RID must start with 'RSV0500'.");
                if (ridEl) ridEl.focus();
                return false;
            }
            if (tsfn && !tsfn.startsWith('TSFN800')) {
                alert("TSFN must start with 'TSFN800'.");
                if (tsfnEl) tsfnEl.focus();
                return false;
            }

            // Check which reason is selected and validate corresponding visible inputs
            const reason = (document.querySelector("input[name='reason']:checked") || {}).value || '';
            if (!reason) {
                alert("Please select a Reason for Deferral (PART / TOOL / TIME).");
                return false;
            }

            if (reason === 'PART') {
                // Find the visible qty input inside part_fields
                const partContainer = document.getElementById('part_fields');
                if (!partContainer) {
                    alert("Part details container missing.");
                    return false;
                }
                const qtyInput = partContainer.querySelector("input[name='part_qty']");
                const ridInput = partContainer.querySelector("input[name='rid']");
                if (ridInput && ridInput.value.trim() === '') {
                    alert("RID is required when Reason is PART.");
                    ridInput.focus();
                    return false;
                }
                if (!qtyInput || qtyInput.value.trim() === '') {
                    alert("Quantity is required when Reason is PART.");
                    if (qtyInput) qtyInput.focus();
                    return false;
                }
                const qtyVal = Number(qtyInput.value);
                if (!isFinite(qtyVal) || qtyVal <= 0) {
                    alert("Quantity must be a number greater than 0 when Reason is PART.");
                    qtyInput.focus();
                    return false;
                }
            } else if (reason === 'TOOL') {
                const toolContainer = document.getElementById('tool_fields');
                if (!toolContainer) {
                    alert("Tool details container missing.");
                    return false;
                }
                const toolNameInput = toolContainer.querySelector("input[name='tool_name']");
                const qtyInput = toolContainer.querySelector("input[name='part_qty']");
                if (toolNameInput && toolNameInput.value.trim() === '') {
                    alert("Tool name is required when Reason is TOOL.");
                    toolNameInput.focus();
                    return false;
                }
                if (!qtyInput || qtyInput.value.trim() === '') {
                    alert("Quantity is required when Reason is TOOL.");
                    if (qtyInput) qtyInput.focus();
                    return false;
                }
                const qtyVal = Number(qtyInput.value);
                if (!isFinite(qtyVal) || qtyVal <= 0) {
                    alert("Quantity must be a number greater than 0 when Reason is TOOL.");
                    qtyInput.focus();
                    return false;
                }
            } else if (reason === 'TIME') {
                const timeContainer = document.getElementById('time_fields');
                if (!timeContainer) {
                    alert("Time details container missing.");
                    return false;
                }
                const hoursInput = timeContainer.querySelector("input[name='ground_time_hours']");
                if (!hoursInput || hoursInput.value.trim() === '') {
                    alert("Ground Time (hours) is required when Reason is TIME.");
                    if (hoursInput) hoursInput.focus();
                    return false;
                }
                const hoursVal = Number(hoursInput.value);
                if (!isFinite(hoursVal) || hoursVal <= 0) {
                    alert("Ground Time must be a number greater than 0 when Reason is TIME.");
                    hoursInput.focus();
                    return false;
                }
            }

            // All checks passed — return true to allow submission
            return true;
        }

        // Chart initialisation (uses server-side $stats)
        const ctx = document.getElementById('defectPieChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Active', 'Overdue', 'Cleared This Week', 'Cleared This Month'],
                datasets: [{
                    data: [
                        <?= (int)$stats['active'] ?>,
                        <?= (int)$stats['overdue'] ?>,
                        <?= (int)$stats['cleared_week'] ?>,
                        <?= (int)$stats['cleared_month'] ?>
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
                        labels: {
                            padding: 20,
                            font: {
                                size: 13
                            }
                        }
                    }
                }
            }
        });

        // Fleet / aircraft filtering data (from server)
        const fleetData = <?= json_encode($fleetGroups) ?>;

        function filterAircraft() {
            const fleet = document.getElementById('fleet').value;
            const acSelect = document.getElementById('ac_registration');
            acSelect.innerHTML = '<option value="">Select Aircraft</option>';
            if (fleet && fleetData[fleet]) {
                fleetData[fleet].forEach(reg => {
                    const opt = document.createElement('option');
                    opt.value = reg;
                    opt.textContent = reg;
                    acSelect.appendChild(opt);
                });
            }
        }

        // Due date calculation helper (keeps UX consistent with server)
        function calculateDueDate() {
            const cat = document.getElementById('mel_category').value;
            const dueInput = document.getElementById('due_date');
            if (!cat) {
                dueInput.value = '';
                return;
            }
            const days = {
                A: 1,
                B: 3,
                C: 10,
                D: 120
            };
            const d = new Date();
            d.setDate(d.getDate() + (days[cat] || 0));
            dueInput.value = d.toISOString().split('T')[0];
        }

        // On DOM ready: hide all reason panels and disable their inputs
        document.addEventListener('DOMContentLoaded', function() {
            ['part_fields', 'tool_fields', 'time_fields'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.style.display = 'none';
                setDisabledForContainer(el, true);
            });

            // If a reason radio is pre-checked (e.g. due to server-side validation error),
            // reveal the correct panel. This ensures the user sees the previously-entered values.
            const preSelected = document.querySelector("input[name='reason']:checked");
            if (preSelected) {
                const map = {
                    'PART': 'part',
                    'TOOL': 'tool',
                    'TIME': 'time'
                };
                showReason(map[preSelected.value] || 'part');
            }
        });

        // Small accessibility: allow toggling via keyboard on radios
        document.querySelectorAll("input[name='reason']").forEach(r => {
            r.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    r.click();
                }
            });
        });

        // Extra defensive helpers (debug only). You can remove these in production.
        function _debugEnabledInputs() {
            const enabled = [];
            document.querySelectorAll('#part_fields input, #tool_fields input, #time_fields input').forEach(i => {
                if (!i.disabled) enabled.push({
                    name: i.name,
                    value: i.value
                });
            });
            console.info('Enabled inputs snapshot:', enabled);
            return enabled;
        }
    </script>

    <!-- End of document -->
</body>

</html>