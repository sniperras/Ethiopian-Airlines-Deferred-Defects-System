<?php
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$errors = [];

// === BASIC REQUIRED FIELDS ===
if (empty(trim($_POST['fleet'] ?? '')))           $errors[] = "Please select Fleet.";
if (empty(trim($_POST['ac_registration'] ?? ''))) $errors[] = "Please select A/C Registration.";
if (empty(trim($_POST['add_log_no'] ?? '')))      $errors[] = "ADD Log No is required.";
if (empty(trim($_POST['ata_seq'] ?? '')))         $errors[] = "ATA + Sequence No is required.";
if (empty(trim($_POST['defect_desc'] ?? '')))     $errors[] = "Defect Description is required.";
if (empty(trim($_POST['deferred_by_name'] ?? ''))) $errors[] = "Deferred By (Full Name) is required.";
if (empty(trim($_POST['id_signature'] ?? '')))    $errors[] = "Your ID / Signature is required.";

// === Date check (ensure value provided and within last 2 days inclusive) ===
$deferral_date = trim($_POST['deferral_date'] ?? '');
if ($deferral_date === '') {
    $errors[] = "Deferral Date is required.";
} else {
    $today = date('Y-m-d');
    $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
    // compare as strings in Y-m-d is safe
    if ($deferral_date < $twoDaysAgo || $deferral_date > $today) {
        $errors[] = "Deferral Date must be today or within the last 2 days.";
    }
}

// === Reason radio ===
$reason = strtoupper(trim($_POST['reason'] ?? ''));
if (!in_array($reason, ['PART', 'TOOL', 'TIME'])) {
    $errors[] = "Please select a Reason for Deferral.";
}

// === RID & TSFN validation (case-insensitive -> uppercase used) ===
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

// === Reason-specific validations ===
// PART => require RID (already above) and part_qty > 0
// TOOL => require tool_name and part_qty > 0 (if your UI supplies qty for tools)
// TIME => require ground_time_hours > 0

if ($reason === 'PART') {
    $raw_qty = trim($_POST['part_qty'] ?? '');
    if ($raw_qty === '') {
        $errors[] = "Quantity is required when Reason is PART.";
    } elseif (!is_numeric($raw_qty) || (float)$raw_qty <= 0) {
        $errors[] = "Quantity must be a number greater than 0 when Reason is PART.";
    }
}

if ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    if ($tool_name === '') {
        $errors[] = "Tool name is required when Reason is TOOL.";
    }
    $raw_qty = trim($_POST['part_qty'] ?? '');
    if ($raw_qty === '') {
        $errors[] = "Quantity is required when Reason is TOOL.";
    } elseif (!is_numeric($raw_qty) || (float)$raw_qty <= 0) {
        $errors[] = "Quantity must be a number greater than 0 when Reason is TOOL.";
    }
}

if ($reason === 'TIME') {
    $raw_hours = trim($_POST['ground_time_hours'] ?? '');
    if ($raw_hours === '') {
        $errors[] = "Ground Time (hours) is required when Reason is TIME.";
    } elseif (!is_numeric($raw_hours) || (float)$raw_hours <= 0) {
        $errors[] = "Ground Time must be a number greater than 0 when Reason is TIME.";
    }
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
$no_cat2  = ($autoland === 'NO CAT II')   ? 1 : 0;
$no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
$no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

// Reason-specific fields — set defaults to NULL and only populate the selected reason
$reason_part = $reason_tool = $reason_time = null;
$rid_final = $part_no = $part_qty = $tool_name = $ground_time_hours = null;

if ($reason === 'PART') {
    $rid_final = $rid; // already uppercased
    $part_no   = trim($_POST['part_no'] ?? '');
    // safe cast to int if you need integer; keep as int or float depending on DB column type
    $part_qty  = is_numeric(trim($_POST['part_qty'] ?? '')) ? (int)$_POST['part_qty'] : 0;
    $reason_part = "RID: $rid_final | Part No: $part_no | Qty: $part_qty";
    // ensure other reason fields are null
    $reason_tool = null;
    $reason_time = null;
    $tool_name = null;
    $ground_time_hours = null;
} elseif ($reason === 'TOOL') {
    $tool_name = trim($_POST['tool_name'] ?? '');
    $part_no   = trim($_POST['part_no'] ?? '');
    $part_qty  = is_numeric(trim($_POST['part_qty'] ?? '')) ? (int)$_POST['part_qty'] : 0;
    $reason_tool = "Tool: $tool_name | Part No: $part_no | Qty: $part_qty";
    // clear other reason fields
    $reason_part = null;
    $reason_time = null;
    $rid_final = null;
    $ground_time_hours = null;
} elseif ($reason === 'TIME') {
    $ground_time_hours = is_numeric(trim($_POST['ground_time_hours'] ?? '')) ? (float)$_POST['ground_time_hours'] : 0;
    $reason_time = "Ground Time: $ground_time_hours hrs";
    // clear other reason fields
    $reason_part = null;
    $reason_tool = null;
    $rid_final = null;
    $part_no = null;
    $part_qty = null;
    $tool_name = null;
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
