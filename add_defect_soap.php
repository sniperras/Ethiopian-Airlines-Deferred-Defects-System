<?php
// add_defect_soap.php - FULL FLEET IMPORT FROM ALL JSON FILES (December 2025)
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

$data_dir = __DIR__ . '/scripts/data';

// Check if directory exists
if (!is_dir($data_dir)) {
    $_SESSION['add_defect_errors'] = ["Data directory not found: $data_dir"];
    header("Location: dashboard.php");
    exit();
}

// Find all defects_latest_*.json files
$files = glob($data_dir . '/defects_latest_*.json');
if (empty($files)) {
    $_SESSION['add_defect_errors'] = ["No defect JSON files found in $data_dir"];
    header("Location: dashboard.php");
    exit();
}

function safeDate($str) {
    if (!$str || trim($str) === '') return date('Y-m-d');
    $clean = preg_replace('/\s+[A-Z]{3,4}$/', '', trim($str)); // remove timezone like EAT
    $dt = DateTime::createFromFormat('d-M-Y H:i', $clean) ?: 
          DateTime::createFromFormat('d-M-Y', $clean);
    return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
}

$total_inserted = $total_skipped = $total_failed = 0;
$report = [];

$pdo->beginTransaction();

foreach ($files as $file) {
    $filename = basename($file);
    $raw = json_decode(file_get_contents($file), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $report[] = "⚠️ $filename → Invalid JSON";
        continue;
    }

    $defects = $raw['defects'] ?? [];
    $ac_reg = $raw['registration'] ?? 'UNKNOWN';
    $oem_model = $raw['oem_model'] ?? 'UNKNOWN';

    if (empty($defects)) {
        $report[] = "ℹ️ $filename → No defects (empty)";
        continue;
    }

    $inserted = $skipped = $failed = 0;

    foreach ($defects as $d) {
        $tsfn = trim($d['tsfn'] ?? $d['fault_id'] ?? '');
        if (!$tsfn) {
            $failed++;
            continue;
        }

        // Check for duplicate
        $check = $pdo->prepare("SELECT id FROM deferred_defects WHERE tsfn = ?");
        $check->execute([$tsfn]);
        if ($check->fetch()) {
            $skipped++;
            continue;
        }

        // Extract values safely
        $fleet = $d['oem_model'] ?? $oem_model ?? 'UNKNOWN';
        $ac_registration = $d['ac_registration'] ?? $ac_reg ?? 'UNKNOWN';
        $deferral_date = safeDate($d['found_on_date'] ?? '');
        $due_date = safeDate($d['due_date'] ?? '');
        $ata_seq = trim($d['config_position'] ?? ''); // usually ATA here
        $defect_desc = trim($d['fault_name'] ?? 'No description');
        $mel_category = trim($d['deferral_class'] ?? 'D');
        $severity = trim($d['severity'] ?? 'UNKNOWN');
        $deferred_by = trim($d['work_package_name'] ?: 'MAINTENIX');
        $id_sig = trim($d['work_package_no'] ?: 'SOAP');
        $etops = (!empty($d['etops_significant']) && strtolower($d['etops_significant']) !== 'no') ? 1 : 0;

        // Auto reason logic
        $reason = 'TIME';
        $rid = $part_no = $part_qty = $ground_time = null;
        $reason_part = $reason_tool = $reason_time = null;

        $material = $d['material_availability'] ?? '';
        if (empty($material) || stripos($material, 'not') !== false || stripos($material, 'no') !== false) {
            $reason = 'PART';
            $rid = 'RSV0500' . substr($tsfn, -6); // last 6 digits
            $part_no = $material ?: 'UNKNOWN PART';
            $part_qty = 1;
            $reason_part = "Auto: Material not available";
        } else {
            $reason = 'TIME';
            $ground_time = 8.0;
            $reason_time = "Auto: Scheduled maintenance";
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
                    ?, ?, ?, '', ?, ?,
                    'MAINTENIX', ?, ?, NULL,
                    ?, ?, ?, ?, ?, NULL,
                    ?, NULL, ?, 0, 0, 0,
                    ?, ?, ?, ?, ?, ?,
                    'active', NULL, NULL, NULL, NULL,
                    NOW(), NOW(), 'SOAP_IMPORT'
                )
            ");

            $stmt->execute([
                $fleet,
                $ac_registration,
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
            error_log("IMPORT FAILED | File: $filename | TSFN: $tsfn | Error: " . $e->getMessage());
            $failed++;
        }
    }

    $total_inserted += $inserted;
    $total_skipped += $skipped;
    $total_failed += $failed;

    $icon = $inserted > 0 ? "✅" : "ℹ️";
    $msg = "$icon $filename → +$inserted new | $skipped skipped | $failed failed";
    $report[] = $msg;
}

$pdo->commit();

// Final message
$message = "<strong>FULL FLEET IMPORT COMPLETE!</strong><br>";
$message .= "<strong>$total_inserted</strong> new defects added<br>";
if ($total_skipped) $message .= "<strong>$total_skipped</strong> duplicates skipped<br>";
if ($total_failed) $message .= "<strong>$total_failed</strong> failed (check error log)<br>";
$message .= "<small>" . implode("<br>", $report) . "</small>";

$_SESSION['success_message'] = $message;
header("Location: dashboard.php");
exit();
?>