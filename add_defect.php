<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Get current user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = $user['allowed_fleet'];

// =============== SECURITY: FLEET ACCESS CHECK ===============
$fleet = $_POST['fleet'] ?? '';
if (!$is_admin && $user['is_fleet_locked'] && $fleet !== $user_fleet) {
    die("Access Denied: You can only add defects for your own fleet.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

// =============== COLLECT & VALIDATE DATA ===============
$deferral_date     = $_POST['deferral_date'] ?? '';
$ac_registration   = trim($_POST['ac_registration'] ?? '');
$add_log_no        = trim($_POST['add_log_no'] ?? '');
$mel_category      = $_POST['mel_category'] ?? '';
$due_date          = $_POST['due_date'] ?? '';
$tsfn              = strtoupper(trim($_POST['tsfn'] ?? ''));
$reason            = $_POST['reason'] ?? ''; // Only one allowed
$defect_desc       = trim($_POST['defect_desc'] ?? '');
$deferred_by_name  = trim($_POST['deferred_by_name'] ?? '');
$id_signature      = $_SESSION['username'];

// Required fields check
if (empty($deferral_date) || empty($ac_registration) || empty($mel_category) || empty($defect_desc) || empty($deferred_by_name)) {
    header("Location: dashboard.php?error=Missing required fields");
    exit();
}

// TSFN Validation
if (!str_starts_with($tsfn, 'TSFN800')) {
    header("Location: dashboard.php?error=TSFN must start with TSFN800");
    exit();
}

// Reason validation (must select one)
if (!in_array($reason, ['PART', 'TOOL', 'TIME'])) {
    header("Location: dashboard.php?error=Please select one reason: PART, TOOL or TIME");
    exit();
}

// Conditional fields
$rid = $part_no = $part_qty = $tool_name = $ground_time = null;

if ($reason === 'PART') {
    $rid = strtoupper(trim($_POST['rid'] ?? ''));
    if (!str_starts_with($rid, 'RSV')) {
        header("Location: dashboard.php?error=RID must start with RSV");
        exit();
    }
    $part_no  = trim($_POST['part_no'] ?? '');
    $part_qty  = (int)($_POST['part_qty'] ?? 0);
    if (empty($part_no) || $part_qty <= 0) {
        header("Location: dashboard.php?error=Part Number and Qty required when PART selected");
        exit();
    }
}

if ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    $part_no   = trim($_POST['part_no'] ?? '');
    $part_qty  = (int)($_POST['part_qty'] ?? 0);
    if (empty($tool_name)) {
        header("Location: dashboard.php?error=Tool Name required");
        exit();
    }
}

if ($reason === 'TIME') {
    $ground_time = (float)($_POST['ground_time'] ?? 0);
    if ($ground_time <= 0) {
        header("Location: dashboard.php?error=Ground time must be greater than 0");
        exit();
    }
}

// =============== PREVENT DUPLICATE ACTIVE DEFECT ===============
$stmt = $pdo->prepare("SELECT id FROM deferred_defects 
    WHERE ac_registration = ? AND defect_desc = ? AND status = 'ACTIVE' LIMIT 1");
$stmt->execute([$ac_registration, $defect_desc]);
if ($stmt->fetch()) {
    header("Location: dashboard.php?error=This defect is already active for this aircraft");
    exit();
}

// =============== INSERT INTO DATABASE ===============
try {
    $sql = "INSERT INTO deferred_defects (
        fleet, ac_registration, deferral_date, add_log_no,
        mel_category, due_date, tsfn, reason,
        rid, part_no, part_qty, tool_name, ground_time_hours,
        defect_desc, deferred_by_name, id_signature, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE'
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $fleet,
        $ac_registration,
        $deferral_date,
        $add_log_no,
        $mel_category,
        $due_date,
        $tsfn,
        $reason,
        $rid,
        $part_no,
        $part_qty,
        $tool_name,
        $ground_time,
        $defect_desc,
        $deferred_by_name,
        $id_signature
    ]);

    header("Location: dashboard.php?success=1");
    exit();

} catch (Exception $e) {
    header("Location: dashboard.php?error=" . urlencode("Database error: " . $e->getMessage()));
    exit();
}
?>