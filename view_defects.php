<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = $user['allowed_fleet'];
$forced_fleet = (!$is_admin && $user['is_fleet_locked']) ? $user_fleet : null;

// Filters
$status_filter = $_GET['status'] ?? 'ALL';
$fleet_filter = $_GET['fleet'] ?? 'ALL';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM deferred_defects WHERE 1=1";
$params = [];

if ($forced_fleet) {
    $sql .= " AND fleet = ?";
    $params[] = $user_fleet;
}
if ($status_filter !== 'ALL') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}
if ($fleet_filter !== 'ALL') {
    $sql .= " AND fleet = ?";
    $params[] = $fleet_filter;
}
if ($search !== '') {
    $sql .= " AND (ac_registration LIKE ? OR defect_desc LIKE ? OR tsfn LIKE ? OR ata_seq LIKE ? OR rid LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$defects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>All Deferred Defects | Ethiopian Airlines</title>

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Custom site-wide style -->
    <link rel="stylesheet" href="style.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar-et fixed top-0 w-full z-50">
    <div class="container-full flex justify-between items-center py-4">
        <a href="dashboard.php" class="text-title text-xl font-semibold">
            Ethiopian Airlines — Deferred Defects
        </a>
        <div class="flex gap-3 items-center">
            <a href="dashboard.php" class="formal-btn">Dashboard</a>
            <a href="logout.php" class="formal-btn formal-btn--export">Logout</a>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main-full pt-24 pb-12">
    <div class="container-full text-primary">

        <!-- HEADER -->
        <header class="formal-header mb-8">
            <h1 class="text-3xl font-semibold text-title">All Deferred Defects</h1>
            <p class="mt-2 text-sm text-primary">
                Total records: <strong class="text-accent"><?= count($defects) ?></strong>
            </p>
        </header>

        <!-- FILTERS -->
        <section class="mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                <div class="md:col-span-2">
                    <input id="search" name="search" type="text"
                           placeholder="Search Reg, TSFN, ATA, RID..."
                           value="<?= htmlspecialchars($search) ?>"
                           class="formal-input" />
                </div>

                <div>
                    <select id="status" name="status" class="formal-input">
                        <option value="ALL" <?= $status_filter==='ALL'?'selected':'' ?>>All Status</option>
                        <option value="ACTIVE" <?= $status_filter==='ACTIVE'?'selected':'' ?>>ACTIVE</option>
                        <option value="CLEARED" <?= $status_filter==='CLEARED'?'selected':'' ?>>CLEARED</option>
                    </select>
                </div>

                <div>
                    <select id="fleet" name="fleet" class="formal-input">
                        <option value="ALL" <?= $fleet_filter==='ALL'?'selected':'' ?>>All Fleets</option>
                        <option value="A350" <?= $fleet_filter==='A350'?'selected':'' ?>>A350</option>
                        <option value="737" <?= $fleet_filter==='737'?'selected':'' ?>>737</option>
                        <option value="787" <?= $fleet_filter==='787'?'selected':'' ?>>787</option>
                        <option value="777" <?= $fleet_filter==='777'?'selected':'' ?>>777</option>
                        <option value="Q400" <?= $fleet_filter==='Q400'?'selected':'' ?>>Q400</option>
                        <option value="CARGO" <?= $fleet_filter==='CARGO'?'selected':'' ?>>CARGO</option>
                    </select>
                </div>

                <div class="md:col-span-1 flex gap-3">
                    <button type="submit" class="formal-btn w-full">Filter</button>
                    <a href="export_defects.php" class="formal-btn formal-btn--export w-full inline-flex gap-2">
                        <i class="fa-solid fa-file-excel"></i> Export Excel
                    </a>
                </div>
            </form>
        </section>

        <!-- DEFECTS TABLE -->
        <section>
            <div class="formal-card overflow-hidden">
                <div class="table-wrapper">
                    <table class="table-modern">
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($defects as $d):
                                $due = $d['due_date'] ? new DateTime($d['due_date']) : null;
                                $now = new DateTime();
                                $dueClass = '';
                                if ($due && $d['status'] === 'ACTIVE') {
                                    if ($now > $due) $dueClass = 'due-overdue';
                                    elseif ($now->diff($due)->days <= 2) $dueClass = 'due-soon';
                                }
                            ?>
                            <tr class="<?= $d['status']==='CLEARED' ? 'opacity-70' : '' ?>">
                                <td><?= $i++ ?></td>
                                <td class="text-accent"><?= htmlspecialchars($d['fleet']) ?></td>
                                <td class="text-title font-semibold"><?= htmlspecialchars($d['ac_registration']) ?></td>
                                <td>
                                    <?php if ($d['status']==='ACTIVE'): ?>
                                        <span class="badge-active">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge-cleared">CLEARED</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($d['source'] ?? '-') ?></td>
                                <td>
                                    <?php if ($d['mel_category']): ?>
                                        <div class="text-accent text-xs font-semibold">MEL <?= htmlspecialchars($d['mel_category']) ?></div>
                                        <div class="text-accent text-xs"><?= htmlspecialchars($d['ata_seq'] ?? '') ?></div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="<?= $dueClass ?>">
                                    <?= $d['due_date'] ? date('d/m/Y', strtotime($d['due_date'])) : '-' ?>
                                </td>
                                <td><?= htmlspecialchars($d['reason'] ?: '—') ?></td>
                                <td class="font-mono"><?= htmlspecialchars($d['tsfn'] ?? '-') ?></td>
                                <td class="font-mono"><?= htmlspecialchars($d['rid'] ?? '-') ?></td>
                                <td class="desc-compact text-title">
                                    <?= htmlspecialchars(strlen($d['defect_desc']) > 120 ? substr($d['defect_desc'],0,120).'...' : $d['defect_desc']) ?>
                                </td>
                                <td><?= htmlspecialchars($d['deferred_by_name']) ?></td>
                                <td class="text-accent"><?= date('d/m/Y', strtotime($d['deferral_date'])) ?></td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if (empty($defects)): ?>
                            <tr>
                                <td colspan="13" class="p-12 text-center text-accent text-lg">
                                    No defects found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="mt-12 text-center text-sm text-accent">
            <p>© 2025 Ethiopian Airlines • Deferred Defects Management System</p>
        </footer>
    </div>
</main>

</body>
</html>
