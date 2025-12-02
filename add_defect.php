<?php
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$errors = [];

// === REQUIRED FIELDS ===
if (empty(trim($_POST['fleet'] ?? '')))           $errors[] = "Please select Fleet.";
if (empty(trim($_POST['ac_registration'] ?? ''))) $errors[] = "Please select A/C Registration.";
if (empty(trim($_POST['add_log_no'] ?? '')))      $errors[] = "ADD Log No is required.";
if (empty(trim($_POST['ata_seq'] ?? '')))         $errors[] = "ATA + Sequence No is required.";
if (empty(trim($_POST['defect_desc'] ?? '')))     $errors[] = "Defect Description is required.";
if (empty(trim($_POST['deferred_by_name'] ?? ''))) $errors[] = "Deferred By (Full Name) is required.";
if (empty(trim($_POST['id_signature'] ?? '')))    $errors[] = "Your ID / Signature is required.";

// Date check
$deferral_date = trim($_POST['deferral_date'] ?? '');
$today = date('Y-m-d');
$twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
if ($deferral_date < $twoDaysAgo || $deferral_date > $today) {
    $errors[] = "Deferral Date must be today or within the last 2 days.";
}

// Reason
$reason = trim($_POST['reason'] ?? '');
if (!in_array($reason, ['PART', 'TOOL', 'TIME'])) {
    $errors[] = "Please select a Reason for Deferral.";
}

// RID & TSFN validation (PHP 5.6+ safe)
$rid  = strtoupper(trim($_POST['rid'] ?? ''));
$tsfn = strtoupper(trim($_POST['tsfn'] ?? ''));

if ($reason === 'PART') {
    if ($rid === '') {
        $errors[] = "RID is required when Reason is PART.";
    } elseif (substr($rid, 0, 7) !== 'RSV0500') {
        $errors[] = "RID must start with RSV0500";
    }
}
if ($tsfn !== '' && substr($tsfn, 0, 7) !== 'TSFN800') {
    $errors[] = "TSFN must start with TSFN800";
}
if (($reason === 'PART' || $reason === 'TOOL') && empty(trim($_POST['part_qty'] ?? ''))) {
    $errors[] = "Quantity is required.";
}

// If any error → go back
if (!empty($errors)) {
    $_SESSION['add_defect_errors'] = $errors;
    header("Location: dashboard.php");
    exit();
}

// === ALL GOOD – PREPARE CLEAN DATA ===
$fleet                     = trim($_POST['fleet']);
$ac_registration           = trim($_POST['ac_registration']);
$add_log_no                = strtoupper(trim($_POST['add_log_no']));
$transferred_from_mnt_logbook = trim($_POST['transferred_from_mnt_logbook'] ?? '');
$source                    = trim($_POST['source'] ?? 'MEL');
$ata_seq                   = strtoupper(trim($_POST['ata_seq']));
$mel_category              = trim($_POST['mel_category'] ?? '');
$due_date                  = trim($_POST['due_date'] ?? $deferral_date);
$time_limit_source         = trim($_POST['time_limit_source'] ?? 'NONE');
$etops_effect              = in_array($_POST['etops_effect'] ?? '', ['1']) ? 1 : 0;
$tsfn                      = $tsfn;
$defect_desc               = trim($_POST['defect_desc']);
$deferred_by_name          = trim($_POST['deferred_by_name']);
$id_signature              = trim($_POST['id_signature']);

$autoland = trim($_POST['autoland_restriction'] ?? 'NONE');
$no_cat2  = ($autoland === 'NO CAT II')  ? 1 : 0;
$no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
$no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

// Reason-specific fields
$reason_part = $reason_tool = $reason_time = null;
$rid_final = $part_no = $part_qty = $tool_name = $ground_time_hours = null;

if ($reason === 'PART') {
    $rid_final = $rid;
    $part_no   = trim($_POST['part_no'] ?? '');
    $part_qty  = (int)$_POST['part_qty'];
    $reason_part = "RID: $rid_final | Part No: $part_no | Qty: $part_qty";
} elseif ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    $part_no   = trim($_POST['part_no'] ?? '');
    $part_qty  = (int)$_POST['part_qty'];
    $reason_tool = "Tool: $tool_name | Part No: $part_no | Qty: $part_qty";
} elseif ($reason === 'TIME') {
    $ground_time_hours = (float)($_POST['ground_time_hours'] ?? 0);
    $reason_time = "Ground Time: $ground_time_hours hrs";
}

// === INSERT – 100% MATCHES YOUR TABLE ===
try {
    $sql = "INSERT INTO deferred_defects (
        fleet, ac_registration, deferral_date, add_log_no, transferred_from_mnt_logbook,
        source, ata_seq, mel_category, due_date, time_limit_source, etops_effect, tsfn,
        defect_desc, reason, reason_part, reason_tool, reason_time,
        rid, part_no, part_qty, tool_name, ground_time_hours,
        no_cat2, no_cat3a, no_cat3b,
        deferred_by_name, id_signature, status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $fleet, $ac_registration, $deferral_date, $add_log_no, $transferred_from_mnt_logbook,
        $source, $ata_seq, $mel_category, $due_date, $time_limit_source, $etops_effect, $tsfn,
        $defect_desc, $reason, $reason_part, $reason_tool, $reason_time,
        $rid_final, $part_no, $part_qty, $tool_name, $ground_time_hours,
        $no_cat2, $no_cat3a, $no_cat3b,
        $deferred_by_name, $id_signature
    ]);

    $_SESSION['success_message'] = "Deferred defect added successfully! A/C: $ac_registration | ATA: $ata_seq";

} catch (Exception $e) {
    error_log("DefTrack Insert Error: " . $e->getMessage());
    $_SESSION['add_defect_errors'] = ["Failed to save defect. Please try again."];
}

header("Location: dashboard.php");
exit();
?>