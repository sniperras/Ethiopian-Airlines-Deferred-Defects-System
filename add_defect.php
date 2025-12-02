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

if (empty(trim($_POST['fleet'] ?? ''))) $errors[] = "Please select Fleet.";
if (empty(trim($_POST['ac_registration'] ?? ''))) $errors[] = "Please select A/C Registration.";
if (empty(trim($_POST['add_log_no'] ?? ''))) $errors[] = "ADD Log No is required.";
if (empty(trim($_POST['ata_seq'] ?? ''))) $errors[] = "ATA + Sequence No is required.";
if (empty(trim($_POST['defect_desc'] ?? ''))) $errors[] = "Defect Description is required.";
if (empty(trim($_POST['deferred_by_name'] ?? ''))) $errors[] = "Deferred By (Full Name) is required.";
if (empty(trim($_POST['id_signature'] ?? ''))) $errors[] = "Your ID / Signature is required.";

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

// Return errors if any
if (!empty($errors)) {
    $_SESSION['add_defect_errors'] = $errors;
    header("Location: dashboard.php");
    exit();
}

// === PREPARE DATA ===
$fleet                     = $_POST['fleet'];
$ac_registration           = $_POST['ac_registration'];
$deferral_date             = $_POST['deferral_date'];
$add_log_no                = strtoupper(trim($_POST['add_log_no']));
$transferred_from_mnt_logbook = trim($_POST['transferred_from_mnt_logbook'] ?? '');
$source                    = $_POST['source'] ?? 'MEL';
$ata_seq                   = strtoupper(trim($_POST['ata_seq']));
$mel_category              = $_POST['mel_category'] ?? '';
$due_date                  = $_POST['due_date'] ?? $deferral_date;
$time_limit_source         = $_POST['time_limit_source'] ?? 'NONE';
$etops_effect              = $_POST['etops_effect'] ?? 0;
$tsfn                      = trim($_POST['tsfn'] ?? '');
$defect_desc               = trim($_POST['defect_desc']);
$deferred_by_name          = trim($_POST['deferred_by_name']);
$id_signature              = trim($_POST['id_signature']);
$reason_text               = $reason;

// Autoland restrictions
$autoland = $_POST['autoland_restriction'] ?? 'NONE';
$no_cat2  = ($autoland === 'NO CAT II')  ? 1 : 0;
$no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
$no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

// Reason-specific fields
$rid = $part_no = $part_qty = $tool_name = $ground_time_hours = null;
$reason_part = $reason_tool = $reason_time = null;

if ($reason === 'PART') {
    $rid      = trim($_POST['rid'] ?? '');
    $part_no  = trim($_POST['part_no'] ?? '');
    $part_qty = (int)($_POST['part_qty'] ?? 0);
    $reason_part = "RID: $rid | Part No: $part_no | Qty: $part_qty";
} elseif ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    $part_no   = trim($_POST['part_no'] ?? '');
    $part_qty  = (int)($_POST['part_qty'] ?? 0);
    $reason_tool = "Tool: $tool_name | Part No: $part_no | Qty: $part_qty";
} elseif ($reason === 'TIME') {
    $ground_time_hours = (float)($_POST['ground_time_hours'] ?? 0);
    $reason_time = "Ground Time: $ground_time_hours hrs";
}

// === INSERT INTO DATABASE ===
$sql = "INSERT INTO deferred_defects (
    fleet, ac_registration, deferral_date, add_log_no, transferred_from_mnt_logbook,
    source, ata_seq, mel_category, due_date, time_limit_source, etops_effect, tsfn,
    defect_desc, reason, reason_part, reason_tool, reason_time,
    rid, part_no, part_qty, tool_name, ground_time_hours,
    no_cat2, no_cat3a, no_cat3b,
    deferred_by_name, id_signature, status
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
)";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    $fleet, $ac_registration, $deferral_date, $add_log_no, $transferred_from_mnt_logbook,
    $source, $ata_seq, $mel_category, $due_date, $time_limit_source, $etops_effect, $tsfn,
    $defect_desc, $reason_text, $reason_part, $reason_tool, $reason_time,
    $rid, $part_no, $part_qty, $tool_name, $ground_time_hours,
    $no_cat2, $no_cat3a, $no_cat3b,
    $deferred_by_name, $id_signature
]);

// === SUCCESS ===
$_SESSION['success_message'] = "Deferred defect added successfully! A/C: $ac_registration | ATA: $ata_seq";
header("Location: dashboard.php");
exit();