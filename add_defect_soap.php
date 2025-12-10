<?php
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

// Path to Python virtual environment
$python = __DIR__ . '\\scripts\\bs_scraper\\.venv_bs\\Scripts\\python.exe';
$script = __DIR__ . '\\scripts\\bs_scraper\\scrape_defects.py';

// The source URL to scrape; can be local PHP page or remote URL
$targetUrl = 'http://localhost:8000/view_all_defects.php';

// Run Python scraper
$cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --url ' . escapeshellarg($targetUrl);
exec($cmd . ' 2>&1', $outputLines, $returnVar);
$json = implode("\n", $outputLines);

if ($returnVar !== 0) {
    $maybe = json_decode($json, true);
    $msg = ($maybe && isset($maybe['message'])) ? $maybe['message'] : $json;
    $_SESSION['add_defect_errors'] = ["Scraper failed: $msg"];
    header("Location: dashboard.php");
    exit();
}

$data = json_decode($json, true);
if ($data === null || !is_array($data)) {
    $_SESSION['add_defect_errors'] = ["Invalid JSON returned by scraper."];
    header("Location: dashboard.php");
    exit();
}

// Loop through scraped defects and insert into deferred_defects table
$insertedCount = 0;
foreach ($data as $defect) {
    try {
        // Basic required fields mapping; adjust keys to match your JSON
        $fleet           = trim($defect['fleet'] ?? '');
        $ac_registration = trim($defect['ac_registration'] ?? '');
        $add_log_no      = strtoupper(trim($defect['add_log_no'] ?? ''));
        $ata_seq         = strtoupper(trim($defect['ata_seq'] ?? ''));
        $defect_desc     = trim($defect['defect_desc'] ?? '');
        $deferral_date   = trim($defect['deferral_date'] ?? date('Y-m-d'));
        $deferred_by_name= trim($defect['deferred_by_name'] ?? 'SCRAPER');
        $id_signature    = trim($defect['id_signature'] ?? 'SCRAPER');

        // Reason logic: PART / TOOL / TIME
        $reason = strtoupper(trim($defect['reason'] ?? 'PART'));
        $reason_part = $reason_tool = $reason_time = null;
        $rid_final = $part_no = $part_qty = $tool_name = $ground_time_hours = null;

        if ($reason === 'PART') {
            $rid_final = strtoupper(trim($defect['rid'] ?? ''));
            $part_no   = trim($defect['part_no'] ?? '');
            $part_qty  = is_numeric($defect['part_qty'] ?? 0) ? (int)$defect['part_qty'] : 0;
            $reason_part = "RID: $rid_final | Part No: $part_no | Qty: $part_qty";
        } elseif ($reason === 'TOOL') {
            $tool_name = trim($defect['tool_name'] ?? '');
            $part_no   = trim($defect['part_no'] ?? '');
            $part_qty  = is_numeric($defect['part_qty'] ?? 0) ? (int)$defect['part_qty'] : 0;
            $reason_tool = "Tool: $tool_name | Part No: $part_no | Qty: $part_qty";
        } elseif ($reason === 'TIME') {
            $ground_time_hours = is_numeric($defect['ground_time_hours'] ?? 0) ? (float)$defect['ground_time_hours'] : 0;
            $reason_time = "Ground Time: $ground_time_hours hrs";
        }

        // Optional defaults
        $transferred_from_mnt_logbook = trim($defect['transferred_from_mnt_logbook'] ?? '');
        $source = trim($defect['source'] ?? 'MEL');
        $mel_category = trim($defect['mel_category'] ?? '');
        $due_date = trim($defect['due_date'] ?? $deferral_date);
        $time_limit_source = trim($defect['time_limit_source'] ?? 'NONE');
        $etops_effect = in_array($defect['etops_effect'] ?? '', ['1',1], true) ? 1 : 0;

        // Autoland flags
        $autoland = trim($defect['autoland_restriction'] ?? 'NONE');
        $no_cat2  = ($autoland === 'NO CAT II')   ? 1 : 0;
        $no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
        $no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

        // === INSERT SQL ===
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
            $source, $ata_seq, $mel_category, $due_date, $time_limit_source, $etops_effect, $defect['tsfn'] ?? null,
            $defect_desc, $reason, $reason_part, $reason_tool, $reason_time,
            $rid_final, $part_no, $part_qty, $tool_name, $ground_time_hours,
            $no_cat2, $no_cat3a, $no_cat3b,
            $deferred_by_name, $id_signature
        ]);

        $insertedCount++;

    } catch (Exception $e) {
        error_log("DefTrack Scraper Insert Error: " . $e->getMessage());
    }
}

$_SESSION['success_message'] = "$insertedCount deferred defects added successfully from scraper.";
header("Location: dashboard.php");
exit();
