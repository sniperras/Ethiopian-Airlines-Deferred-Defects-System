<?php
require_once 'auth.php';
require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get user role & fleet lock
$stmt = $pdo->prepare("SELECT role, allowed_fleet, is_fleet_locked FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = strtoupper($user['allowed_fleet'] ?? '');
$is_fleet_locked = $user['is_fleet_locked'] ?? 0;

// Filters
$status_filter = $_GET['status'] ?? 'ALL';
$fleet_filter  = $_GET['fleet']  ?? 'ALL';
$search        = trim($_GET['search'] ?? '');

// Force fleet filter for non-admin locked users (security + UX)
if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $fleet_filter = $user_fleet;
}

$sql = "SELECT * FROM deferred_defects WHERE 1=1";
$params = [];

if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $sql .= " AND UPPER(fleet) = ?";
    $params[] = $user_fleet;
}
if ($status_filter !== 'ALL') {
    $sql .= " AND UPPER(status) = ?";
    $params[] = strtoupper($status_filter);
}
if ($fleet_filter !== 'ALL') {
    $sql .= " AND UPPER(fleet) = ?";
    $params[] = $fleet_filter;
}
if ($search !== '') {
    $sql .= " AND (ac_registration LIKE ? OR defect_desc LIKE ? OR tsfn LIKE ? OR ata_seq LIKE ? OR rid LIKE ? OR add_log_no LIKE ?)";
    $like = "%$search%";
    for ($i = 0; $i < 6; $i++) $params[] = $like;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$defects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Deferred Defects | DefTrack</title>
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

    <div class="container-compact">

        <!-- Success / Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div style="background:#d4edda; color:#155724; padding:18px; border-radius:12px; margin:20px 0; border-left:6px solid #28a745; text-align:center; font-weight:600;">
                <i class="fa fa-check-circle"></i> <?= $_SESSION['success'] ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div style="background:#f8d7da; color:#721c24; padding:18px; border-radius:12px; margin:20px 0; border-left:6px solid #dc3545; text-align:center; font-weight:600;">
                <?= $_SESSION['error'] ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>All Deferred Defects</h1>
            <p>Total Records: <strong><?= count($defects) ?></strong></p>
        </div>

        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <input type="text" name="search" placeholder="Search Reg, TSFN, ATA, RID..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-group">
                    <select name="status">
                        <option value="ALL" <?= $status_filter==='ALL'?'selected':'' ?>>All Status</option>
                        <option value="ACTIVE" <?= $status_filter==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
                        <option value="CLEARED" <?= $status_filter==='CLEARED'?'selected':'' ?>>CLEARED</option>
                    </select>
                </div>

                <!-- FLEET FILTER: Admin sees dropdown | Normal user sees locked info -->
                <?php if ($is_admin): ?>
                    <div class="filter-group">
                        <select name="fleet">
                            <option value="ALL" <?= $fleet_filter==='ALL'?'selected':'' ?>>All Fleets</option>
                            <option value="Airbus" <?= $fleet_filter==='Airbus'?'selected':'' ?>>Airbus</option>
                            <option value="787" <?= $fleet_filter==='787'?'selected':'' ?>>787</option>
                            <option value="777" <?= $fleet_filter==='777'?'selected':'' ?>>777</option>
                            <option value="737" <?= $fleet_filter==='737'?'selected':'' ?>>737</option>
                            <option value="Cargo" <?= $fleet_filter==='Cargo'?'selected':'' ?>>Cargo</option>
                            <option value="Q400" <?= $fleet_filter==='Q400'?'selected':'' ?>>Q400</option>
                        </select>
                    </div>
                <?php else: ?>
                    <!-- Hidden input + visual lock indicator for normal users -->
                   <input type="hidden" name="fleet" value="<?= htmlspecialchars($user_fleet) ?>">
                    <div class="fleet-locked-info">
                        <i class="fa fa-lock"></i> Viewing: <strong><?= htmlspecialchars($user_fleet) ?: 'Restricted' ?> Fleet</strong>
                    </div>
                <?php endif; ?>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Filter</button>
                    <a href="export_defects.php?<?= http_build_query($_GET) ?>" class="btn-export">
                        Export Excel
                    </a>
                </div>
            </form>
        </div>

        <div class="card-compact">
            <?php if ($defects): ?>
            <div class="table-responsive">
                <table class="defects-table">
                    <thead>
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
                            <th style="width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($defects as $d):
                            $isActive = strtoupper($d['status']) === 'ACTIVE';
                            $dueClass = '';
                            if ($isActive && $d['due_date']) {
                                $dueDate = strtotime($d['due_date']);
                                if (time() > $dueDate) $dueClass = 'due-overdue';
                                elseif (time() > $dueDate - (2*86400)) $dueClass = 'due-soon';
                            }
                        ?>
                        <tr class="<?= !$isActive ? 'row-cleared' : '' ?>">
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($d['fleet']) ?></strong></td>
                            <td class="highlight-reg"><?= htmlspecialchars($d['ac_registration']) ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="status-active">ACTIVE</span>
                                <?php else: ?>
                                    <span class="status-cleared">CLEARED</span>
                                <?php endif; ?>
                            </td>
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
                            <td class="desc-cell">
                                <!-- Full description with vertical wrap -->
                                <?= nl2br(htmlspecialchars($d['defect_desc'])) ?>
                            </td>
                            <td><?= htmlspecialchars($d['deferred_by_name']) ?></td>
                            <td><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>

                            <!-- ACTIONS COLUMN -->
                            <td class="actions-cell">
                                <?php if ($isActive): ?>
                                    <a href="clear_defect.php?id=<?= $d['id'] ?>" class="btn-clear" title="Clear Defect">
                                        Clear
                                    </a>
                                    <a href="edit_defect.php?id=<?= $d['id'] ?>" class="btn-edit" title="Edit Defect">
                                        Edit
                                    </a>
                                <?php else: ?>
                                    <span class="text-success">
                                        Cleared
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="no-data">
                    <p>No deferred defects found.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="page-footer">
            <p>© 2025 Ethiopian Airlines • DefTrack ADD Tracking System</p>
        </div>
    </div>

</body>
</html>