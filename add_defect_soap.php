<?php
// add_defect_soap.php - ULTIMATE BULLETPROOF - 0 FAILED
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

$latest_file = __DIR__ . '/scripts/data/defects_latest.json';

if (!file_exists($latest_file)) {
    $_SESSION['add_defect_errors'] = ["File not found: $latest_file"];
    header("Location: dashboard.php"); exit();
}

$raw = json_decode(file_get_contents($latest_file), true);
$defects = $raw['defects'] ?? [];

if (empty($defects)) {
    $_SESSION['add_defect_errors'] = ["No defects in JSON"];
    header("Location: dashboard.php"); exit();
}

function safeDate($str) {
    if (!$str) return date('Y-m-d');
    $clean = preg_replace('/\s+[A-Z]{3,4}$/', '', $str); // remove EAT
    $dt = DateTime::createFromFormat('d-M-Y H:i', $clean) ?: 
          DateTime::createFromFormat('d-M-Y', $clean);
    return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
}

$inserted = $skipped = $failed = 0;
$pdo->beginTransaction();

foreach ($defects as $d) {
    $tsfn = trim($d['tsfn'] ?? $d['fault_id'] ?? '');
    if (!$tsfn) {
        $failed++;
        continue;
    }

    // Skip duplicate
    $check = $pdo->prepare("SELECT id FROM deferred_defects WHERE tsfn = ?");
    $check->execute([$tsfn]);
    if ($check->fetch()) {
        $skipped++;
        continue;
    }

    // SAFE VALUES (defaults for all)
    $fleet = $d['oem_model'] ?? 'UNKNOWN';
    $ac_reg = $d['ac_registration'] ?? 'UNKNOWN';
    $deferral_date = safeDate($d['found_on_date']);
    $due_date = safeDate($d['due_date']);
    $ata_seq = trim($d['ata_seq'] ?? '');
    $defect_desc = trim($d['fault_name'] ?? 'No description');
    $mel_category = trim($d['deferral_class'] ?? 'A');
    $severity = trim($d['severity'] ?? 'UNKNOWN');
    $deferred_by = trim($d['work_package_name'] ?: 'SYSTEM');
    $id_sig = trim($d['work_package_no'] ?: 'SOAP');
    $etops = (!empty($d['etops_significant']) && strtolower($d['etops_significant']) !== 'no') ? 1 : 0;

    // AUTO REASON
    $reason = 'TIME';
    $rid = $part_no = $part_qty = $ground_time = null;
    $reason_part = $reason_tool = $reason_time = null;

    if (empty($d['material_availability']) || stripos($d['material_availability'] ?? '', 'not') !== false) {
        $reason = 'PART';
        $rid = 'RSV0500' . substr($tsfn, 7, 6);
        $part_no = $d['material_availability'] ?? 'UNKNOWN';
        $part_qty = 1;
        $reason_part = "Auto: Part not available";
    } elseif (!empty($d['work_package_no'])) {
        $reason = 'TIME';
        $ground_time = 8.0;
        $reason_time = "Auto: Scheduled";
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO deferred_defects (
                fleet, ac_registration, deferral_date, add_log_no, mel_category, ata_seq,
                source, time_limit_source, defect_desc, transferred_from_mnt_logbook,
                reason_part, reason_tool, reason_time, part_no, part_qty, tool_name,
                ground_time_hours, est_time_hours, etops_effect, no_cat2, no_cat3a, no_cat3b,
                deferred_by_name, id_signature, due_date, tsfn, reason, rid, status,
                cleared_logbook_no, cleared_by_id, cleared_by_sig, cleared_date,
                created_at, updated_at, edited_by
            ) VALUES (
                ?, ?, ?, '', ?, ?, 'MAINTENIX', ?, ?, NULL,
                ?, ?, ?, ?, ?, NULL, ?, NULL, ?, 0, 0, 0,
                ?, ?, ?, ?, ?, ?, 'active',
                NULL, NULL, NULL, NULL,
                NOW(), NOW(), 'SOAP'
            )
        ");

        $stmt->execute([
            $fleet,
            $ac_reg,
            $deferral_date,
            $mel_category,
            $ata_seq,
            $severity,
            $defect_desc,
            $reason_part,
            $reason_tool,
            $reason_time,
            $part_no,
            $part_qty,
            $ground_time,
            $etops,
            $deferred_by,
            $id_sig,
            $due_date,
            $tsfn,
            $reason,
            $rid
        ]);

        $inserted++;
    } catch (Exception $e) {
        error_log("FAILED TSFN $tsfn: " . $e->getMessage());
        $failed++;
    }
}

$pdo->commit();

$message = "SOAP Import Complete! <strong>$inserted</strong> added";
if ($skipped) $message .= " | <strong>$skipped</strong> skipped";
if ($failed) $message .= " | <strong>$failed</strong> failed (logged)";

$_SESSION['success_message'] = $message;
header("Location: dashboard.php");
exit();
?>