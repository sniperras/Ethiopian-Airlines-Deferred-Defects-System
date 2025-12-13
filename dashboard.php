
<?php
require_once 'auth.php';
require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role'] ?? 'user';
$allowed_fleet = strtoupper($_SESSION['allowed_fleet'] ?? '');
$is_fleet_locked = $_SESSION['is_fleet_locked'] ?? 0;

// === FLEET LOADING ===
$fleetGroups = [];

if ($user_role === 'admin' || $allowed_fleet === 'ALL') {
    $tables = [
        'Airbus' => 'airbus_fleet',
        '787' => 'fleet_787',
        '777' => 'fleet_777',
        '737' => 'fleet_737',
        'Cargo' => 'cargo_fleet',
        'Q400' => 'q400_fleet'
    ];
} else {
    $map = [
        'AIRBUS' => 'airbus_fleet',
        '787' => 'fleet_787',
        '777' => 'fleet_777',
        '737' => 'fleet_737',
        'CARGO' => 'cargo_fleet',
        'Q400' => 'q400_fleet'
    ];
    $tables = $allowed_fleet && isset($map[$allowed_fleet]) ? [$allowed_fleet => $map[$allowed_fleet]] : [];
}

foreach ($tables as $fleetName => $table) {
    $stmt = $pdo->query("SELECT registration FROM `$table` WHERE registration IS NOT NULL AND registration != '' ORDER BY registration");
    $regs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($regs)
        $fleetGroups[$fleetName] = $regs;
}

// === ACTIVE DEFECTS ===
if ($user_role === 'admin') {
    $sql = "SELECT * FROM deferred_defects WHERE status = 'active' ORDER BY deferral_date DESC LIMIT 50";
    $stmt = $pdo->query($sql);
} else {
    $sql = "SELECT * FROM deferred_defects WHERE fleet = ? AND status = 'active' ORDER BY deferral_date DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$allowed_fleet]);
}
$active_defects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === STATISTICS ===
$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$isGlobalView = ($user_role === 'admin' || $allowed_fleet === 'ALL');
$whereFleet = $isGlobalView ? '' : " AND UPPER(fleet) = ?";

$stats = [];
if ($isGlobalView) {
    $stats['active'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE'")->fetchColumn();
    $stats['overdue'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'ACTIVE' AND due_date < '$today'")->fetchColumn();
    $stats['cleared_week'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= '$weekStart'")->fetchColumn();
    $stats['cleared_month'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects WHERE UPPER(status) = 'CLEARED' AND cleared_date >= '$monthStart'")->fetchColumn();
    $stats['total'] = $pdo->query("SELECT COUNT(*) FROM deferred_defects")->fetchColumn();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --bg: #1B3C53;
            --brand: #234C6A;
            --muted: #456882;
            --danger: #c0392b;
            --success: #27ae60;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #1B3C53;
        }

        /* 200px LEFT & RIGHT SPACE + FULL WIDTH CONTENT */
        .dashboard-wrapper {
            margin: 40px 200px;
            max-width: calc(100% - 400px);
            width: 100%;
            box-sizing: border-box;
        }

        .dashboard-wrapper>* {
            width: 100% !important;
            max-width: 100% !important;
        }

        .dashboard-wrapper .table {
            width: 100% !important;
            min-width: 1300px;
            border-collapse: separate;
            border-spacing: 0;
        }

        /* Top Navigation - Same 200px gap */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 200px;
            background: var(--bg);
            color: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-brand h2 {
            margin: 0;
            font-size: 2rem;
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 1.05rem;
        }

        .btn-view,
        .btn-logout {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-view {
            background: #3498db;
            color: white;
        }

        .btn-view:hover {
            background: #2980b9;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.4);
        }

        .btn-stats {
            background: #9b59b6;
        }

        .btn-logout {
            background: #e74c3c;
            color: white;
        }

        .btn-logout:hover {
            background: #c0392b;
            transform: translateY(-3px);
        }

        /* Cards */
        .card,
        .card-compact {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .card h3,
        .card-compact h3 {
            margin-top: 0;
            color: var(--brand);
            font-size: 1.5rem;
        }

        /* Stats Row */
        .stats-row {
            display: flex;
            gap: 40px;
            margin: 50px 0;
            flex-wrap: wrap;
        }

        .stats-left,
        .stats-right {
            flex: 1;
            min-width: 340px;
        }

        .stats-right {
            background: white;
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
            border-top: 6px solid var(--brand);
        }

        .stat-item {
            background: #f8fbff;
            padding: 22px 28px;
            border-radius: 14px;
            margin-bottom: 20px;
            border-left: 6px solid;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            font-size: 2.6rem;
            font-weight: 800;
            color: #1a1a1a;
        }

        .stat-label {
            color: #456882;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .chart-container {
            position: relative;
            height: 420px;
            width: 100%;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 1rem;
        }

        .radio-inline {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        /* Responsive */
        @media (max-width: 1600px) {

            .dashboard-wrapper,
            .top-nav {
                margin: 30px 150px;
                padding: 18px 150px;
                max-width: calc(100% - 300px);
            }
        }

        @media (max-width: 1400px) {

            .dashboard-wrapper,
            .top-nav {
                margin: 30px 100px;
                padding: 18px 100px;
                max-width: calc(100% - 200px);
            }
        }

        @media (max-width: 1200px) {

            .dashboard-wrapper,
            .top-nav {
                margin: 20px 80px;
                padding: 15px 80px;
                max-width: calc(100% - 160px);
            }
        }

        @media (max-width: 992px) {

            .dashboard-wrapper,
            .top-nav {
                margin: 20px 40px;
                padding: 15px 40px;
                max-width: calc(100% - 80px);
            }

            .stats-row {
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {

            .dashboard-wrapper,
            .top-nav {
                margin: 15px 20px;
                padding: 12px 20px;
                max-width: calc(100% - 40px);
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
            <span>
                <i class="fa-solid fa-user"></i> <?= htmlspecialchars($username) ?>
                <?php if ($user_role === 'admin'): ?> <small style="color:#a8e6cf;">(Admin)</small><?php endif; ?>
                <?php if (!$isGlobalView && $allowed_fleet): ?> <small
                        style="color:#f39c12;">(<?= htmlspecialchars($allowed_fleet) ?> Fleet)</small><?php endif; ?>
            </span>
            <a href="view_all_defects.php" class="btn-view">View All Defects</a>
            <a href="live_statics.php" target="_blank" class="btn-view btn-stats">
                Live Stats
            </a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="dashboard-wrapper">

        <!-- Messages -->
        <?php if (isset($_SESSION['add_defect_errors'])): ?>
            <div
                style="background:#ffe6e6;color:#c33;padding:20px;border-radius:12px;margin-bottom:30px;border-left:6px solid #c33;">
                <strong>Please fix:</strong><br>
                <?php foreach ($_SESSION['add_defect_errors'] as $e): ?>•
                    <?= htmlspecialchars($e) ?><br><?php endforeach;
                unset($_SESSION['add_defect_errors']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div
                style="background:#d4edda;color:#155724;padding:20px;border-radius:12px;margin-bottom:30px;border-left:6px solid #28a745;">
                <?= $_SESSION['success_message'] ?>    <?php unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Import Card -->
        <div class="card">
            <h3>Scrape & Import Deferred Defects</h3>
            <p>Select an aircraft and import only its latest open faults from Maintenix</p>
            <div class="mt-4">
                <a href="add_defect_soap.php?import_all=1" class="btn btn-lg btn-outline-primary shadow"
                    onclick="return confirm('Import defects from all 161 aircraft?\nThis may take 2-5 minutes.')">
                    Import Full Fleet
                </a>
            </div>
            <?php if (isset($_SESSION['soap_success'])): ?>
                <div class="alert alert-success mt-3"><?= htmlspecialchars($_SESSION['soap_success']);
                unset($_SESSION['soap_success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['soap_error'])): ?>
                <div class="alert alert-danger mt-3"><?= htmlspecialchars($_SESSION['soap_error']);
                unset($_SESSION['soap_error']); ?></div>
            <?php endif; ?>
        </div>

        <!-- Add New Defect Form -->
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
                        <input type="date" name="deferral_date" required min="<?= $twoDaysAgo ?>" max="<?= $today ?>"
                            value="<?= $today ?>">
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

                <hr style="margin:30px 0; border-color:#eee;">

                <div class="form-group full">
                    <label>Reason for Deferral *</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="reason" value="PART" onclick="showReason('part')"> PART</label>
                        <label><input type="radio" name="reason" value="TOOL" onclick="showReason('tool')"> TOOL</label>
                        <label><input type="radio" name="reason" value="TIME" onclick="showReason('time')"> TIME</label>
                    </div>
                </div>

                <div id="part_fields" class="reason-fields" style="display:none;">
                    <div class="form-grid">
                        <div class="form-group"><label>RID</label><input type="text" name="rid" id="rid"
                                placeholder="e.g. RSV0500xxxxx"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1"></div>
                    </div>
                </div>

                <div id="tool_fields" class="reason-fields" style="display:none;">
                    <div class="form-grid">
                        <div class="form-group"><label>Tool Name</label><input type="text" name="tool_name"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" min="1"></div>
                    </div>
                </div>

                <div id="time_fields" class="reason-fields" style="display:none;">
                    <div class="form-group"><label>Ground Time (hrs)</label><input type="number" step="0.1"
                            name="ground_time_hours" min="0" placeholder="e.g. 1.5"></div>
                </div>

                <div class="form-group full">
                    <label>Defect Description *</label>
                    <textarea name="defect_desc" rows="4" required
                        placeholder="Describe the defect clearly..."></textarea>
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

                <div class="text-center" style="margin-top:40px;">
                    <button type="submit"
                        style="padding:14px 40px;background:#27ae60;color:white;border:none;border-radius:12px;font-size:1.1rem;font-weight:600;cursor:pointer;">
                        Submit Deferred Defect
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistics + Chart -->
        <div class="stats-row">
            <div class="stats-left">
                <h3 style="color:#fff;margin-bottom:30px;">
                    Defect Statistics
                    <?php if (!$isGlobalView): ?> — <?= htmlspecialchars($allowed_fleet) ?> Fleet<?php endif; ?>
                </h3>
                <div class="stat-item active"><span class="stat-label">Active Defects</span><span
                        class="stat-number"><?= $stats['active'] ?></span></div>
                <div class="stat-item overdue"><span class="stat-label">Overdue Defects</span><span
                        class="stat-number"><?= $stats['overdue'] ?></span></div>
                <div class="stat-item cleared-w"><span class="stat-label">Cleared This Week</span><span
                        class="stat-number"><?= $stats['cleared_week'] ?></span></div>
                <div class="stat-item cleared-m"><span class="stat-label">Cleared This Month</span><span
                        class="stat-number"><?= $stats['cleared_month'] ?></span></div>
                <div class="stat-item total"><span class="stat-label">Total Defects (All Time)</span><span
                        class="stat-number"><?= $stats['total'] ?></span></div>
            </div>

            <div class="stats-right">
                <h3 style="text-align:center;color:#1B3C53;margin-bottom:30px;">Status Distribution</h3>
                <div class="chart-container">
                    <canvas id="defectPieChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Active Defects Table -->
        <div class="card-compact">
            <h3 class="card-title">Your Active Deferred Defects (<?= count($active_defects) ?>)</h3>
            <?php if ($active_defects): ?>
                <div class="table-responsive">
                    <table class="table w-100">
                        <thead>
                            <tr style="background:#234C6A;color:#fff;font-weight:700;text-transform:uppercase;">
                                <th style="padding:16px;border-top-left-radius:12px;">Date</th>
                                <th style="padding:16px;">A/C</th>
                                <th style="padding:16px;">Fleet</th>
                                <th style="padding:16px;">ATA</th>
                                <th style="padding:16px;">Description</th>
                                <th style="padding:16px;">Status</th>
                                <th style="padding:16px;">Due</th>
                                <th style="padding:16px;">Reason</th>
                                <th style="padding:16px; border-top-right-radius:12px; text-align:center; min-width:180px;">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_defects as $d): ?>
                                <tr style="background:#fff;border-bottom:1px solid #eef6fb;">
                                    <td style="padding:16px;"><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>
                                    <td style="padding:16px;font-weight:600;color:#234C6A;">
                                        <strong><?= htmlspecialchars($d['ac_registration']) ?></strong></td>
                                    <td style="padding:16px;"><?= htmlspecialchars($d['fleet']) ?></td>
                                    <td style="padding:16px;"><?= htmlspecialchars($d['ata_seq']) ?></td>
                                    <td style="padding:16px;">
                                        <?= htmlspecialchars(mb_substr($d['defect_desc'], 0, 80, 'UTF-8')) ?>...</td>
                                    <td style="padding:16px;">
                                        <span
                                            style="background:#e74c3c;color:#fff;padding:8px 16px;border-radius:20px;font-weight:700;font-size:0.9rem;">Active</span>
                                    </td>
                                    <td style="padding:16px;"><?= date('d/m/Y', strtotime($d['due_date'])) ?></td>
                                    <td style="padding:16px;">
                                        <?php
                                        $reason = strtoupper($d['reason'] ?? 'N/A');
                                        $color = $reason === 'PART' ? '#e74c3c' : ($reason === 'TOOL' ? '#e67e22' : '#27ae60');
                                        ?>
                                        <span
                                            style="background:<?= $color ?>;color:#fff;padding:8px 16px;border-radius:20px;font-weight:700;font-size:0.9rem;"><?= $reason ?></span>
                                    </td>
                                    <td style="padding:16px; text-align:center; vertical-align:middle;">
                                        <div class="action-buttons">
                                            <a href="clear_defect.php?id=<?= $d['id'] ?>" class="action-btn clear-btn">
                                                <i class="fas fa-check-circle"></i> Clear
                                            </a>
                                            <a>  / </a>
                                            <a href="edit_defect.php?id=<?= $d['id'] ?>" class="action-btn edit-btn">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <p>No active deferred defects found.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // Full JavaScript with all your original functionality
        function setDisabledForContainer(container, disabled) {
            if (!container) return;
            const inputs = container.querySelectorAll('input, select, textarea');
            inputs.forEach(el => {
                el.disabled = disabled;
                if (disabled) {
                    container.setAttribute('aria-hidden', 'true');
                    el.removeAttribute('required');
                } else {
                    container.removeAttribute('aria-hidden');
                }
            });
        }

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
                    el.style.display = (rc.id === 'time_fields') ? 'block' : 'grid';
                    setDisabledForContainer(el, false);
                    el.querySelectorAll('input, textarea, select').forEach(inp => {
                        if (inp.name === 'part_qty' || inp.name === 'ground_time_hours' || inp.name === 'rid' || inp.name === 'tool_name') {
                            inp.setAttribute('required', 'required');
                        }
                    });
                } else {
                    el.style.display = 'none';
                    setDisabledForContainer(el, true);
                }
            });

            document.querySelectorAll("input[name='reason']").forEach(r => {
                r.checked = (type === 'part' && r.value === 'PART') ||
                    (type === 'tool' && r.value === 'TOOL') ||
                    (type === 'time' && r.value === 'TIME');
            });
        }

        function validateForm() {
            const ridEl = document.getElementById('rid');
            const tsfnEl = document.getElementById('tsfn');
            const rid = ridEl ? ridEl.value.trim().toUpperCase() : '';
            const tsfn = tsfnEl ? tsfnEl.value.trim().toUpperCase() : '';

            if (rid && !rid.startsWith('RSV0500')) {
                alert("RID must start with 'RSV0500'.");
                ridEl.focus();
                return false;
            }
            if (tsfn && !tsfn.startsWith('TSFN800')) {
                alert("TSFN must start with 'TSFN800'.");
                tsfnEl.focus();
                return false;
            }

            const reason = (document.querySelector("input[name='reason']:checked") || {}).value || '';
            if (!reason) {
                alert("Please select a Reason for Deferral.");
                return false;
            }

            if (reason === 'PART') {
                const partContainer = document.getElementById('part_fields');
                const qtyInput = partContainer.querySelector("input[name='part_qty']");
                const ridInput = partContainer.querySelector("input[name='rid']");
                if (ridInput && ridInput.value.trim() === '') {
                    alert("RID is required when Reason is PART.");
                    ridInput.focus();
                    return false;
                }
                if (!qtyInput || qtyInput.value.trim() === '' || Number(qtyInput.value) <= 0) {
                    alert("Valid Quantity is required when Reason is PART.");
                    qtyInput.focus();
                    return false;
                }
            } else if (reason === 'TOOL') {
                const toolContainer = document.getElementById('tool_fields');
                const toolNameInput = toolContainer.querySelector("input[name='tool_name']");
                const qtyInput = toolContainer.querySelector("input[name='part_qty']");
                if (toolNameInput && toolNameInput.value.trim() === '') {
                    alert("Tool name is required when Reason is TOOL.");
                    toolNameInput.focus();
                    return false;
                }
                if (!qtyInput || qtyInput.value.trim() === '' || Number(qtyInput.value) <= 0) {
                    alert("Valid Quantity is required when Reason is TOOL.");
                    qtyInput.focus();
                    return false;
                }
            } else if (reason === 'TIME') {
                const hoursInput = document.querySelector("input[name='ground_time_hours']");
                if (!hoursInput || hoursInput.value.trim() === '' || Number(hoursInput.value) <= 0) {
                    alert("Valid Ground Time is required when Reason is TIME.");
                    hoursInput.focus();
                    return false;
                }
            }
            return true;
        }

        // Chart
        const ctx = document.getElementById('defectPieChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Active', 'Overdue', 'Cleared This Week', 'Cleared This Month'],
                datasets: [{
                    data: [<?= $stats['active'] ?>, <?= $stats['overdue'] ?>, <?= $stats['cleared_week'] ?>, <?= $stats['cleared_month'] ?>],
                    backgroundColor: ['#e74c3c', '#c0392b', '#27ae60', '#2ecc71'],
                    borderWidth: 3,
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
                            padding: 25,
                            font: {
                                size: 14
                            }
                        }
                    }
                }
            }
        });

        // Aircraft filter
        const fleetData = <?= json_encode($fleetGroups) ?>;

        function filterAircraft() {
            const fleet = document.getElementById('fleet').value;
            const acSelect = document.getElementById('ac_registration');
            acSelect.innerHTML = '<option value="">Select Aircraft</option>';
            if (fleet && fleetData[fleet]) {
                fleetData[fleet].forEach(reg => {
                    const opt = document.createElement('option');
                    opt.value = opt.textContent = reg;
                    acSelect.appendChild(opt);
                });
            }
        }

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

        // Hide reason panels on load
        document.addEventListener('DOMContentLoaded', () => {
            ['part_fields', 'tool_fields', 'time_fields'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.style.display = 'none';
                    setDisabledForContainer(el, true);
                }
            });
        });
    </script>

</body>

</html>