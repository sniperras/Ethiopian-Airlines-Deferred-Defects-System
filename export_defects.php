<?php
require_once 'auth.php';
require_once 'db_connect.php';

// === USER PERMISSION CHECK (fix undefined $is_admin) ===
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role, allowed_fleet, is_fleet_locked FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$is_admin        = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet      = strtoupper($user['allowed_fleet'] ?? '');
$is_fleet_locked = $user['is_fleet_locked'] ?? 0;

// === GET FILTERS FROM URL (same as view page) ===
$status_filter = $_GET['status'] ?? 'ALL';
$fleet_filter  = $_GET['fleet']  ?? 'ALL';
$search        = trim($_GET['search'] ?? '');

// === BUILD QUERY ===
$sql = "SELECT * FROM deferred_defects WHERE 1=1";
$params = [];

if (!$is_admin && $is_fleet_locked) {
    $sql .= " AND fleet = ?";
    $params[] = $user_fleet;
}
if ($status_filter !== 'ALL') {
    $sql .= " AND UPPER(status) = ?";
    $params[] = strtoupper($status_filter);
}
if ($fleet_filter !== 'ALL') {
    $sql .= " AND fleet = ?";
    $params[] = $fleet_filter;
}
if ($search !== '') {
    $sql .= " AND (ac_registration LIKE ? OR defect_desc LIKE ? OR tsfn LIKE ? OR ata_seq LIKE ? OR rid LIKE ? OR add_log_no LIKE ?)";
    $like = "%$search%";
    foreach (['', '', '', '', '', ''] as $x) $params[] = $like;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$defects = $stmt->fetchAll();

// === TRY TO MAKE REAL .XLSX (if ZipArchive exists) ===
if (class_exists('ZipArchive') && extension_loaded('zip')) {
    createRealXlsx($defects);
    exit();
}

// === FALLBACK: CLEAN CSV (opens perfectly in Excel) ===
createCsvExport($defects);
exit();

/* ============================================================= */
/* REAL .XLSX (if ZipArchive is enabled)                        */
/* ============================================================= */
function createRealXlsx($data) {
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        createCsvExport($data);
        return;
    }

    // Minimal required files for Excel
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="xml" ContentType="application/xml"/>
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheets><sheet name="Defects" sheetId="1" r:id="rId1"/></sheets>
</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2"><font><b/></font><font/></fonts>
    <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1B3C53"/></patternFill></fill></fills>
    <cellXfs count="2"><xf fontId="0" fillId="0" applyFont="1"/><xf fontId="0" fillId="1" applyFill="1" applyFont="1"/></cellXfs>
</styleSheet>');

    // Build sheet
    $rows = '<row r="1"><c t="inlineStr" s="1"><is><t>ETHIOPIAN AIRLINES - DEFERRED DEFECTS REPORT</t></is></c></row>';
    $rows .= '<row r="2"><c t="inlineStr"><is><t>Generated: ' . date('d M Y H:i') . ' by ' . $_SESSION['username'] . '</t></is></c></row>';
    $headers = ['#','Fleet','A/C Reg','Status','Source','MEL/ATA','Due Date','Reason','TSFN','RID','Description','Deferred By','Date','Log No'];
    $rows .= '<row r="4">';
    foreach ($headers as $i => $h) $rows .= '<c t="inlineStr" s="1"><is><t>' . $h . '</t></is></c>';
    $rows .= '</row>';

    $n = 5; $i = 1;
    foreach ($data as $d) {
        $mel = $d['mel_category'] ? "MEL {$d['mel_category']}" : '';
        $ata = $d['ata_seq'] ?? '';
        $desc = htmlspecialchars($d['defect_desc'], ENT_XML1);
        $rows .= "<row r=\"$n\">";
        $cols = [$i++, $d['fleet'], $d['ac_registration'], $d['status'], $d['source']??'', "$mel $ata", $d['due_date']?date('d/m/Y',strtotime($d['due_date'])):'', $d['reason']??'', $d['tsfn']??'', $d['rid']??'', $desc, $d['deferred_by_name'], date('d/m/Y',strtotime($d['deferral_date'])), $d['add_log_no']];
        foreach ($cols as $v) $rows .= '<c t="inlineStr"><is><t>' . $v . '</t></is></c>';
        $rows .= '</row>';
        $n++;
    }

    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
    $zip->close();

    $filename = "DefTrack_Deferred_Defects_" . date('Y-m-d_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($tmp);
    unlink($tmp);
}

/* ============================================================= */
/* FALLBACK: CLEAN CSV (always works)                           */
/* ============================================================= */
function createCsvExport($data) {
    $filename = "DefTrack_Deferred_Defects_" . date('Y-m-d_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Title
    fputcsv($output, ['ETHIOPIAN AIRLINES - DEFERRED DEFECTS REPORT']);
    fputcsv($output, ['Generated on ' . date('d M Y H:i') . ' by ' . $_SESSION['username']]);
    fputcsv($output, []); // empty line

    // Headers
    fputcsv($output, ['#','Fleet','A/C Reg','Status','Source','MEL/ATA','Due Date','Reason','TSFN','RID','Description','Deferred By','Date','Log No']);

    // Data
    $i = 1;
    foreach ($data as $d) {
        $mel = $d['mel_category'] ? "MEL {$d['mel_category']}" : '';
        $ata = $d['ata_seq'] ?? '';
        fputcsv($output, [
            $i++,
            $d['fleet'],
            $d['ac_registration'],
            $d['status'],
            $d['source'] ?? '',
            trim("$mel $ata"),
            $d['due_date'] ? date('d/m/Y', strtotime($d['due_date'])) : '',
            $d['reason'] ?? '',
            $d['tsfn'] ?? '',
            $d['rid'] ?? '',
            $d['defect_desc'],
            $d['deferred_by_name'],
            date('d/m/Y', strtotime($d['deferral_date'])),
            $d['add_log_no']
        ]);
    }

    fclose($output);
}