<?php
require_once 'auth.php';
require_once 'db_connect.php';

$user_id = $_SESSION['user_id'];

// Get user permissions (same logic as view_all_defects.php)
$stmt = $pdo->prepare("SELECT role, allowed_fleet, is_fleet_locked FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = strtoupper($user['allowed_fleet'] ?? '');
$is_fleet_locked = $user['is_fleet_locked'] ?? 0;

// Apply same filters as view_all_defects.php
$status_filter = $_GET['status'] ?? 'ALL';
$fleet_filter  = $_GET['fleet']  ?? 'ALL';
$search        = trim($_GET['search'] ?? '');

if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $fleet_filter = $user_fleet;
}

// Same SQL as in view_all_defects.php → Latest 10 active per fleet
$where_conditions = ["status = 'active'"];
$params = [];

if (!$is_admin && $is_fleet_locked && $user_fleet !== '') {
    $where_conditions[] = "UPPER(fleet) = ?";
    $params[] = $user_fleet;
} elseif ($fleet_filter !== 'ALL') {
    $where_conditions[] = "UPPER(fleet) = ?";
    $params[] = strtoupper($fleet_filter);
}

if ($search !== '') {
    $where_conditions[] = "(ac_registration LIKE ? OR defect_desc LIKE ? OR tsfn LIKE ? OR ata_seq LIKE ? OR rid LIKE ? OR add_log_no LIKE ?)";
    $like = "%$search%";
    for ($i = 0; $i < 6; $i++) $params[] = $like;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$sql = "
    SELECT fleet, ac_registration, status, source, mel_category, ata_seq, 
           due_date, reason, tsfn, rid, defect_desc, deferred_by_name, deferral_date
    FROM (
        SELECT d.*, ROW_NUMBER() OVER (PARTITION BY fleet ORDER BY id DESC) as rn
        FROM deferred_defects d
        $where_clause
    ) ranked
    WHERE rn <= 10
    ORDER BY fleet, id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$defects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filename
$filename = "DefTrack_Latest_Defects_" . date('Y-m-d_His') . ".csv";

// CRITICAL: Proper headers for Excel to read UTF-8 correctly
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Add BOM → This is the magic fix for Excel
echo "\xEF\xBB\xBF"; // UTF-8 BOM

// Open output stream
$output = fopen('php://output', 'w');

// CSV Header
fputcsv($output, [
    'Fleet',
    'A/C Registration',
    'Status',
    'Source',
    'MEL Category',
    'ATA',
    'Due Date',
    'Reason',
    'TSFN',
    'RID',
    'Defect Description',
    'Deferred By',
    'Deferral Date'
]);

// Data rows
foreach ($defects as $d) {
    fputcsv($output, [
        $d['fleet'] ?? '',
        $d['ac_registration'] ?? '',
        $d['status'] ?? '',
        $d['source'] ?? '',
        $d['mel_category'] ?? '',
        $d['ata_seq'] ?? '',
        $d['due_date'] ? date('d/m/Y', strtotime($d['due_date'])) : '',
        $d['reason'] ?? '',
        $d['tsfn'] ?? '',
        $d['rid'] ?? '',
        $d['defect_desc'] ?? '',
        $d['deferred_by_name'] ?? '',
        date('d/m/Y', strtotime($d['deferral_date']))
    ]);
}

fclose($output);
exit();
?>