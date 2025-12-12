<?php
require_once 'auth.php';
require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// User role & fleet lock
$stmt = $pdo->prepare("SELECT role, allowed_fleet, is_fleet_locked FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = strtoupper($user['allowed_fleet'] ?? '');
$is_fleet_locked = $user['is_fleet_locked'] ?? 0;

// Filters from URL
$status_filter = $_GET['status'] ?? 'ALL';
$fleet_filter  = $_GET['fleet']  ?? 'ALL';
$search        = trim($_GET['search'] ?? '');

// Force fleet for locked non-admin users
if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $fleet_filter = $user_fleet;
}

// Build query conditions
$where = ["d.status = 'active'"];
$params = [];

if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $where[] = "UPPER(d.fleet) = ?";
    $params[] = $user_fleet;
} elseif ($fleet_filter !== 'ALL') {
    $where[] = "UPPER(d.fleet) = ?";
    $params[] = strtoupper($fleet_filter);
}

if ($search !== '') {
    $where[] = "(d.ac_registration LIKE ? OR d.defect_desc LIKE ? OR d.tsfn LIKE ? OR d.ata_seq LIKE ? OR d.rid LIKE ? OR d.add_log_no LIKE ?)";
    $like = "%$search%";
    foreach (range(1,6) as $i) $params[] = $like;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Final query: 10 latest active defects per fleet
$sql = "
    SELECT d.*,
           ROW_NUMBER() OVER (PARTITION BY d.fleet ORDER BY d.id DESC) as rn
    FROM deferred_defects d
    $where_clause
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$all_defects = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['rn'] <= 10) {
        $all_defects[] = $row;
    }
}

// Sort by fleet then ID DESC for clean display
usort($all_defects, fn($a,$b) => [$a['fleet'], $b['id']] <=> [$b['fleet'], $a['id']]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Latest Defects by Fleet | DefTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/view_defects.css">
</head>
<body>

<nav class="top-nav">
    <div class="nav-brand"><h2>DefTrack</h2></div>
    <div class="nav-user">
        <span><i class="fa fa-user"></i> <?= htmlspecialchars($username) ?>
            <?php if($is_admin): ?> <small style="color:#a8e6cf;">(Admin)</small><?php endif; ?>
        </span>
        <a href="dashboard.php" class="btn-back">Dashboard</a>
        <a href="logout.php" class="btn-logout">Logout</a>
    </div>
</nav>

<div class="container-fluid" style="padding-left:70px; padding-right:70px; max-width:100%;">

    <!-- Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div style="background:#d4edda; color:#155724; padding:18px; border-radius:12px; margin:20px 0; border-left:6px solid #28a745; text-align:center; font-weight:600;">
            <i class="fa fa-check-circle"></i> <?= $_SESSION['success'] ?><?php unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div style="background:#f8d7da; color:#721c24; padding:18px; border-radius:12px; margin:20px 0; border-left:6px solid #dc3545; text-align:center; font-weight:600;">
            <?= $_SESSION['error'] ?><?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="page-header">
        <h1>Latest 10 Active Defects per Fleet</h1>
        <p>Showing most recent active defects • Total: <strong><?= count($all_defects) ?></strong> records</p>
    </div>

    <div class="filter-bar">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <input type="text" name="search" placeholder="Search Reg, TSFN, ATA, RID..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group">
                <select name="status">
                    <option value="ALL" <?= $status_filter==='ALL'?'selected':'' ?>>All Status</option>
                    <option value="ACTIVE" <?= $status_filter==='ACTIVE'?'selected':'' ?>>ACTIVE Only</option>
                    <option value="CLEARED" <?= $status_filter==='CLEARED'?'selected':'' ?>>CLEARED</option>
                </select>
            </div>

            <?php if ($is_admin): ?>
                <div class="filter-group">
                    <select name="fleet">
                        <option value="ALL" <?= $fleet_filter==='ALL'?'selected':'' ?>>All Fleets</option>
                        <option value="Airbus" <?= $fleet_filter==='AIRBUS'?'selected':'' ?>>Airbus</option>
                        <option value="Boeing 737" <?= $fleet_filter==='BOEING 737'?'selected':'' ?>>Boeing 737</option>
                        <option value="787" <?= $fleet_filter==='787'?'selected':'' ?>>787</option>
                        <option value="777" <?= $fleet_filter==='777'?'selected':'' ?>>777</option>
                        <option value="777F" <?= $fleet_filter==='777F'?'selected':'' ?>>777F</option>
                        <option value="767" <?= $fleet_filter==='767'?'selected':'' ?>>767</option>
                        <option value="Q400" <?= $fleet_filter==='Q400'?'selected':'' ?>>Q400</option>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="fleet" value="<?= htmlspecialchars($user_fleet) ?>">
                <div class="fleet-locked-info">
                    <i class="fa fa-lock"></i> Viewing: <strong><?= htmlspecialchars($user_fleet) ?></strong>
                </div>
            <?php endif; ?>

            <div class="filter-actions">
                <button type="submit" class="btn-filter">Apply</button>
                <a href="export_defects.php?<?= http_build_query($_GET) ?>" class="btn-export">
                    Export Excel
                </a>
            </div>
        </form>
    </div>

    <!-- TABLE -->
    <div class="card-compact shadow-sm border-0 rounded-3 overflow-hidden">
        <?php if ($all_defects): ?>
            <div class="table-responsive">
                <table class="defects-table w-100">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Fleet</th>
                            <th>A/C Reg</th>
                            <th>Status</th>
                            <th>Source</th>
                            <th>MEL / ATA</th>
                            <th>Due Date</th>
                            <th>Reason</th>
                            <th>TSFN</th>
                            <th>RID</th>
                            <th>Description</th>
                            <th>Deferred By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        $current_fleet = '';
                        foreach ($all_defects as $d):
                            $dueClass = '';
                            if ($d['due_date']) {
                                $dueDate = strtotime($d['due_date']);
                                if (time() > $dueDate) $dueClass = 'due-overdue';
                                elseif (time() > $dueDate - (2*86400)) $dueClass = 'due-soon';
                            }

                            // Fleet header row
                            if ($d['fleet'] !== $current_fleet) {
                                $current_fleet = $d['fleet'];
                                echo "<tr class='fleet-header'><td colspan='14' class='bg-primary text-white py-2 px-3 fw-bold'>$current_fleet Fleet</td></tr>";
                                $i = 1;
                            }
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($d['fleet']) ?></strong></td>
                            <td class="highlight-reg"><?= htmlspecialchars($d['ac_registration']) ?></td>
                            <td><span class="status-active">ACTIVE</span></td>
                            <td><?= htmlspecialchars($d['source'] ?? '-') ?></td>
                            <td>
                                <?php if ($d['mel_category']): ?>
                                    <div class="mel-cat">MEL <?= htmlspecialchars($d['mel_category']) ?></div>
                                <?php endif; ?>
                                <div class="ata-seq"><?= htmlspecialchars($d['ata_seq'] ?? '') ?></div>
                            </td>
                            <td class="<?= $dueClass ?>">
                                <?= $d['due_date'] ? date('d/m/Y', strtotime($d['due_date'])) : '-' ?>
                            </td>
                            <td><?= htmlspecialchars($d['reason'] ?? '-') ?></td>
                            <td class="mono"><?= htmlspecialchars($d['tsfn'] ?? '-') ?></td>
                            <td class="mono"><?= htmlspecialchars($d['rid'] ?? '-') ?></td>
                            <td class="desc-cell"><?= nl2br(htmlspecialchars($d['defect_desc'])) ?></td>
                            <td><?= htmlspecialchars($d['deferred_by_name']) ?></td>
                            <td><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>
                            <td class="actions-cell">
                                <a href="clear_defect.php?id=<?= $d['id'] ?>" class="btn-clear btn-sm">Clear</a>
                                <a href="edit_defect.php?id=<?= $d['id'] ?>" class="btn-edit btn-sm">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fa fa-inbox fa-3x mb-3"></i>
                <p>No active defects found.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="page-footer">
        <p>© 2025 Ethiopian Airlines • DefTrack ADD Tracking System</p>
    </div>
</div>

</body>
</html>