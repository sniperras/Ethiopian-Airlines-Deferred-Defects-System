<?php
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$username = $_SESSION['username'];

// === VALIDATION ===
$errors = [];

// Deferral Date: only today or last 2 days
$deferral_date = trim($_POST['deferral_date'] ?? '');
$today = date('Y-m-d');
$twoDaysAgo = date('Y-m-d', strtotime('-2 days'));

if ($deferral_date < $twoDaysAgo || $deferral_date > $today) {
    $errors[] = "Deferral Date must be today or within the last 2 days.";
}

if (empty(trim($_POST['fleet']))) $errors[] = "Please select Fleet.";
if (empty(trim($_POST['ac_registration']))) $errors[] = "Please select A/C Registration.";
if (empty(trim($_POST['add_log_no']))) $errors[] = "ADD Log No is required.";
if (empty(trim($_POST['ata_seq']))) $errors[] = "ATA + Sequence No is required.";
if (empty(trim($_POST['defect_desc']))) $errors[] = "Defect Description is required.";
if (empty(trim($_POST['deferred_by_name']))) $errors[] = "Deferred By (Full Name) is required.";
if (empty(trim($_POST['id_signature']))) $errors[] = "Your ID / Signature is required.";

// Reason validation
$reason = $_POST['reason'] ?? '';
if (!in_array($reason, ['PART', 'TOOL', 'TIME'])) {
    $errors[] = "Please select a Reason for Deferral.";
}

if ($reason === 'PART' && empty(trim($_POST['rid'] ?? ''))) {
    $errors[] = "RID is required for PART deferral.";
}
if (($reason === 'PART' || $reason === 'TOOL') && empty(trim($_POST['part_qty'] ?? ''))) {
    $errors[] = "Quantity is required.";
}

// === IF ERRORS: Show them nicely ===
if (!empty($errors)) {
    $_SESSION['add_defect_errors'] = $errors;
    header("Location: dashboard.php");
    exit();
}

// === PREPARE DATA FOR INSERT ===
$fleet              = $_POST['fleet'];
$ac_registration    = $_POST['ac_registration'];
$deferral_date      = $_POST['deferral_date'];
$add_log_no         = strtoupper(trim($_POST['add_log_no']));
$source             = $_POST['source'] ?? 'MEL';
$ata_seq            = strtoupper(trim($_POST['ata_seq']));
$mel_category       = $_POST['mel_category'] ?? '';
$due_date           = $_POST['due_date'] ?? $deferral_date;
$time_limit_source  = $_POST['time_limit_source'] ?? 'NONE';
$etops_effect       = $_POST['etops_effect'] ?? 0;
$tsfn               = trim($_POST['tsfn'] ?? '');
$defect_desc        = trim($_POST['defect_desc']);
$deferred_by_name   = trim($_POST['deferred_by_name']);
$id_signature       = trim($_POST['id_signature']);
$reason_text        = $reason;

// Autoland restriction
$autoland = $_POST['autoland_restriction'] ?? 'NONE';
$no_cat2 = ($autoland === 'NO CAT II') ? 1 : 0;
$no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
$no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

// Reason-specific fields
$rid = $part_no = $part_qty = $tool_name = $ground_time_hours = null;
$reason_part = $reason_tool = $reason_time = null;

if ($reason === 'PART') {
    $rid = trim($_POST['rid'] ?? '');
    $part_no = trim($_POST['part_no'] ?? '');
    $part_qty = (int)($_POST['part_qty'] ?? 0);
    $reason_part = "RID: $rid | Part No: $part_no | Qty: $part_qty";
}
elseif ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    $part_no = trim($_POST['part_no'] ?? '');
    $part_qty = (int)($_POST['part_qty'] ?? 0);
    $reason_tool = "Tool: $tool_name | Part No: $part with Qty: $part_qty";
}
elseif ($reason === 'TIME') {
    $ground_time_hours = (float)($_POST['ground_time_hours'] ?? 0);
    $reason_time = "Ground Time: $ground_time_hours hrs";
}

// === INSERT INTO DATABASE ===
$sql = "INSERT INTO deferred_defects (
    fleet, ac_registration, deferral_date, add_log_no, source, ata_seq, mel_category,
    due_date, time_limit_source, etops_effect, tsfn, defect_desc,
    reason, reason_part, reason_tool, reason_time,
    rid, part_no, part_qty, tool_name, ground_time_hours,
    no_cat2, no_cat3a, no_cat3b,
    deferred_by_name, id_signature, status
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
)";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $fleet, $ac_registration, $deferral_date, $add_log_no, $source, $ata_seq, $mel_category,
    $due_date, $time_limit_source, $etops_effect, $tsfn, $defect_desc,
    $reason_text, $reason_part, $reason_tool, $reason_time,
    $rid, $part_no, $part_qty, $tool_name, $ground_time_hours,
    $no_cat2, $no_cat3a, $no_cat3b,
    $deferred_by_name, $id_signature
]);

// Success!
$_SESSION['success_message'] = "Deferred defect added successfully! A/C: $ac_registration | ATA: $ata_seq";
header("Location: dashboard.php");
exit();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Success | DefTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=4">
    <style>
        .success-box {
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            background: white;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(27,60,83,0.2);
            border-top: 6px solid #27ae60;
        }
        .success-box i { font-size: 4rem; color: #27ae60; margin-bottom: 20px; }
        .success-box h1 { color: #1B3C53; margin: 20px 0; }
        .success-box p { color: #456882; }
        .btn-home { background: #234C6A; color: white; padding: 12px 30px; border-radius: 10px; text-decoration: none; display: inline-block; margin-top: 20px; }
        .btn-home:hover { background: #1B3C53; }
    </style>
</head>
<body>

    <div class="success-box">
        <i class="fa fa-check-circle"></i>
        <h1>Success!</h1>
        <p><?= htmlspecialchars($_SESSION['success_message'] ?? 'Deferred defect has been added successfully.') ?></p>
        <p>You will be redirected to the dashboard in 3 seconds...</p>
        <a href="dashboard.php" class="btn-home"><i class="fa fa-home"></i> Back to Dashboard</a>
    </div>

    <?php
    // Clear message
    unset($_SESSION['success_message']);
    unset($_SESSION['add_defect_errors']);
    // Auto redirect
    echo '<script>setTimeout(() => { window.location.href = "dashboard.php"; }, 3000);</script>';
    ?>
</body>
</html>