<?php
// add_defect_soap.php - Scrape Once And Populate
session_start();
require_once 'auth.php';
require_once 'db_connect.php';

// Run the scraper
$cmd = 'python "' . __DIR__ . '/scripts/bs_scraper/scrape_defects.py" 2>&1';
exec($cmd, $output, $code);

if ($code !== 0) {
    $_SESSION['soap_error'] = "Scraper failed: " . implode("\n", $output);
    header("Location: dashboard.php");
    exit();
}

// Read latest JSON
$last = json_decode(file_get_contents(__DIR__ . '/last_scrape.json'), true);
$json_file = __DIR__ . '/' . ($last['files'][1] ?? '');

if (!file_exists($json_file)) {
    $_SESSION['soap_error'] = "No data file created.";
    header("Location: dashboard.php");
    exit();
}

$defects = json_decode(file_get_contents($json_file), true);

$inserted = 0;
$skipped = 0;

$pdo->beginTransaction();

foreach ($defects as $d) {
    // Skip if already exists
    $check = $pdo->prepare("SELECT id FROM deferred_defects WHERE tsfn = ? OR fault_id = ?");
    $check->execute([$d['tsfn'], $d['fault_id']]);
    if ($check->rowCount() > 0) {
        $skipped++;
        continue;
    }

    // Guess fleet from registration
    $reg = $d['ac_registration'] ?? "ET-XXX";
    $fleet = str_contains($reg, "787") ? "Boeing 787" : "Boeing 737";

    // Reason logic
    $desc = strtoupper($d['fault_name']);
    $reason = 'TIME';
    if (str_contains($desc, "PART") || str_contains($desc, "P/N")) $reason = 'PART';
    if (str_contains($desc, "TOOL") || str_contains($desc, "GST")) $reason = 'TOOL';

    $stmt = $pdo->prepare("INSERT INTO deferred_defects (
        fleet, ac_registration, deferral_date, add_log_no, source, ata_seq,
        defect_desc, reason, tsfn, status, deferred_by_name, id_signature
    ) VALUES (
        ?, ?, CURDATE(), ?, 'MAINTENIX', ?, ?, ?, ?, 'active', 'SYSTEM', 'SOAP'
    )");

    $stmt->execute([
        $fleet,
        $reg,
        $d['fault_id'],
        $d['ata_seq'],
        $d['fault_name'],
        $reason,
        $d['tsfn']
    ]);

    $inserted++;
}

$pdo->commit();

$_SESSION['soap_success'] = "SOAP Import completed! Inserted $inserted new defects (Skipped $skipped duplicates)";
header("Location: dashboard.php");
exit();
?>