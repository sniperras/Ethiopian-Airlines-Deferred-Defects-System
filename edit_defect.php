<?php
require_once 'auth.php';
require_once 'db_connect.php';

$defect_id = (int)($_GET['id'] ?? 0);
if ($defect_id <= 0) {
    die("Invalid defect ID.");
}

// Fetch defect
$stmt = $pdo->prepare("SELECT * FROM deferred_defects WHERE id = ?");
$stmt->execute([$defect_id]);
$defect = $stmt->fetch();

if (!$defect) {
    die("Defect not found.");
}

if (strtoupper($defect['status']) === 'CLEARED') {
    $_SESSION['error'] = "Cannot edit a CLEARED defect.";
    header("Location: view_all_defects.php");
    exit();
}

// Process update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Get and sanitize inputs
    $fleet              = trim($_POST['fleet'] ?? '');
    $ac_registration    = strtoupper(trim($_POST['ac_registration'] ?? ''));
    $deferral_date      = $_POST['deferral_date'] ?? '';
    $add_log_no         = strtoupper(trim($_POST['add_log_no'] ?? ''));
    $source             = $_POST['source'] ?? 'MEL';
    $ata_seq            = strtoupper(trim($_POST['ata_seq'] ?? ''));
    $mel_category       = $_POST['mel_category'] ?? '';
    $due_date           = $_POST['due_date'] ?? $deferral_date;
    $time_limit_source  = $_POST['time_limit_source'] ?? 'NONE';
    $etops_effect       = $_POST['etops_effect'] ?? '0';
    $tsfn               = trim($_POST['tsfn'] ?? '');
    $defect_desc        = trim($_POST['defect_desc'] ?? '');
    $reason             = $_POST['reason'] ?? '';

    // Autoland
    $autoland = $_POST['autoland_restriction'] ?? 'NONE';
    $no_cat2  = ($autoland === 'NO CAT II') ? 1 : 0;
    $no_cat3a = ($autoland === 'NO CAT IIIA') ? 1 : 0;
    $no_cat3b = ($autoland === 'NO CAT IIIB') ? 1 : 0;

    // Reason fields
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

    // Basic validation
    if (empty($fleet)) $errors[] = "Fleet is required.";
    if (empty($ac_registration)) $errors[] = "A/C Registration is required.";
    if (empty($ata_seq)) $errors[] = "ATA + Seq No is required.";
    if (empty($defect_desc)) $errors[] = "Defect Description is required.";
    if (empty($reason)) $errors[] = "Reason for Deferral is required.";

    if (empty($errors)) {
        $sql = "UPDATE deferred_defects SET
                    fleet = ?, ac_registration = ?, deferral_date = ?, add_log_no = ?,
                    source = ?, ata_seq = ?, mel_category = ?, due_date = ?,
                    time_limit_source = ?, etops_effect = ?, tsfn = ?, defect_desc = ?,
                    reason = ?, reason_part = ?, reason_tool = ?, reason_time = ?,
                    rid = ?, part_no = ?, part_qty = ?, tool_name = ?, ground_time_hours = ?,
                    no_cat2 = ?, no_cat3a = ?, no_cat3b = ?,
                    edited_by = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $fleet, $ac_registration, $deferral_date, $add_log_no,
            $source, $ata_seq, $mel_category, $due_date,
            $time_limit_source, $etops_effect, $tsfn, $defect_desc,
            $reason, $reason_part, $reason_tool, $reason_time,
            $rid, $part_no, $part_qty, $tool_name, $ground_time_hours,
            $no_cat2, $no_cat3a, $no_cat3b,
            $_SESSION['username'],  // ← edited_by
            $defect_id
        ]);

        if ($success) {
            $_SESSION['success'] = "Defect #{$defect_id} updated successfully! Edited by: {$_SESSION['username']}";
            header("Location: view_all_defects.php");
            exit();
        } else {
            $error = "Database error. Update failed.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Defect #<?= $defect_id ?> | DefTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=7">
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><h2>DefTrack</h2></div>
        <div class="nav-user">
            <span><i class="fa fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="view_all_defects.php" class="btn-back">
  <i class="fa fa-arrow-left"></i> Back to List
</a>

        </div>
    </nav>

    <div class="container-compact">
        <div class="card-compact" style="border-top:6px solid #234C6A;">
            <h2 class="card-title">Edit or View Deferred Defect</h2>

            <?php if (!empty($errors)): ?>
                <div style="background:#ffe6e6; color:#c33; padding:15px; border-radius:10px; margin:20px 0; border-left:5px solid #c33;">
                    <strong>Please fix:</strong><br>
                    <?php foreach ($errors as $e): ?>
                        • <?= htmlspecialchars($e) ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div style="background:#ffe6e6; color:#c33; padding:15px; border-radius:10px; margin:20px 0; border-left:5px solid #c33;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="defect-form">
                <!-- Same fields as add form — pre-filled and working -->
                <div class="form-grid">
                    <div class="form-group">
                        <label>Deferral Date</label>
                        <input type="date" name="deferral_date" required value="<?= $defect['deferral_date'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Fleet</label>
                        <input type="text" name="fleet" required value="<?= htmlspecialchars($defect['fleet']) ?>">
                    </div>
                    <div class="form-group">
                        <label>A/C Registration</label>
                        <input type="text" name="ac_registration" required value="<?= htmlspecialchars($defect['ac_registration']) ?>">
                    </div>
                    <div class="form-group">
                        <label>ADD Log No</label>
                        <input type="text" name="add_log_no" required value="<?= htmlspecialchars($defect['add_log_no']) ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Source</label>
                        <select name="source" required>
                            <option value="MEL" <?= $defect['source']==='MEL'?'selected':'' ?>>MEL</option>
                            <option value="CDL" <?= $defect['source']==='CDL'?'selected':'' ?>>CDL</option>
                            <option value="NON-MEL" <?= $defect['source']==='NON-MEL'?'selected':'' ?>>NON-MEL</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ATA + Seq No</label>
                        <input type="text" name="ata_seq" required value="<?= htmlspecialchars($defect['ata_seq']) ?>">
                    </div>
                    <div class="form-group">
                        <label>MEL Category</label>
                        <select name="mel_category">
                            <option value="">-- Select --</option>
                            <option value="A" <?= $defect['mel_category']==='A'?'selected':'' ?>>A → 24 Hours</option>
                            <option value="B" <?= $defect['mel_category']==='B'?'selected':'' ?>>B → 3 Days</option>
                            <option value="C" <?= $defect['mel_category']==='C'?'selected':'' ?>>C → 10 Days</option>
                            <option value="D" <?= $defect['mel_category']==='D'?'selected':'' ?>>D → 120 Days</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="due_date" value="<?= $defect['due_date'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Time Limit Source</label>
                        <select name="time_limit_source">
                            <option value="NONE" <?= $defect['time_limit_source']==='NONE'?'selected':'' ?>>NONE</option>
                            <option value="AMM" <?= $defect['time_limit_source']==='AMM'?'selected':'' ?>>AMM</option>
                            <option value="SRM" <?= $defect['time_limit_source']==='SRM'?'selected':'' ?>>SRM</option>
                            <option value="OTHER" <?= $defect['time_limit_source']==='OTHER'?'selected':'' ?>>OTHER</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ETOPS Effect</label>
                        <select name="etops_effect">
                            <option value="0" <?= $defect['etops_effect']==0?'selected':'' ?>>NONE</option>
                            <option value="1" <?= $defect['etops_effect']==1?'selected':'' ?>>NON ETOPS / LIMITED</option>
                        </select>
                    </div>
                </div>

                <!-- Autoland -->
                <div class="form-group full">
                    <label>Autoland Restrictions</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="autoland_restriction" value="NONE" <?= ($defect['no_cat2']==0 && $defect['no_cat3a']==0 && $defect['no_cat3b']==0)?'checked':'' ?>> NONE</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT II" <?= $defect['no_cat2']==1?'checked':'' ?>> NO CAT II</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT IIIA" <?= $defect['no_cat3a']==1?'checked':'' ?>> NO CAT IIIA</label>
                        <label><input type="radio" name="autoland_restriction" value="NO CAT IIIB" <?= $defect['no_cat3b']==1?'checked':'' ?>> NO CAT IIIB</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>TSFN</label>
                    <input type="text" name="tsfn" value="<?= htmlspecialchars($defect['tsfn'] ?? '') ?>">
                </div>

                <hr style="margin:30px 0; border-color:#E3E3E3;">

                <div class="form-group full">
                    <label>Reason for Deferral *</label>
                    <div class="radio-inline">
                        <label><input type="radio" name="reason" value="PART" <?= $defect['reason']==='PART'?'checked':'' ?> onclick="showReason('part')"> PART</label>
                        <label><input type="radio" name="reason" value="TOOL" <?= $defect['reason']==='TOOL'?'checked':'' ?> onclick="showReason('tool')"> TOOL</label>
                        <label><input type="radio" name="reason" value="TIME" <?= $defect['reason']==='TIME'?'checked':'' ?> onclick="showReason('time')"> TIME</label>
                    </div>
                </div>

                <div id="part_fields" class="reason-fields" style="display:<?= $defect['reason']==='PART'?'grid':'none' ?>;">
                    <div class="form-grid">
                        <div class="form-group"><label>RID</label><input type="text" name="rid" value="<?= htmlspecialchars($defect['rid'] ?? '') ?>"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no" value="<?= htmlspecialchars($defect['part_no'] ?? '') ?>"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" value="<?= $defect['part_qty'] ?>"></div>
                    </div>
                </div>

                <div id="tool_fields" class="reason-fields" style="display:<?= $defect['reason']==='TOOL'?'grid':'none' ?>;">
                    <div class="form-grid">
                        <div class="form-group"><label>Tool Name</label><input type="text" name="tool_name" value="<?= htmlspecialchars($defect['tool_name'] ?? '') ?>"></div>
                        <div class="form-group"><label>Part Number</label><input type="text" name="part_no" value="<?= htmlspecialchars($defect['part_no'] ?? '') ?>"></div>
                        <div class="form-group"><label>Qty</label><input type="number" name="part_qty" value="<?= $defect['part_qty'] ?>"></div>
                    </div>
                </div>

                <div id="time_fields" class="reason-fields" style="display:<?= $defect['reason']==='TIME'?'block':'none' ?>;">
                    <div class="form-group"><label>Ground Time (hrs)</label><input type="number" step="0.1" name="ground_time_hours" value="<?= $defect['ground_time_hours'] ?>"></div>
                </div>

                <div class="form-group full">
                    <label>Defect Description *</label>
                    <textarea name="defect_desc" rows="4" required><?= htmlspecialchars($defect['defect_desc']) ?></textarea>
                </div>

                <div class="text-center" style="margin-top:40px;">
                    <button type="submit" class="btn-submit-centered">
                        Update Defect
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showReason(type) {
            document.querySelectorAll('.reason-fields').forEach(el => el.style.display = 'none');
            if (type === 'part') document.getElementById('part_fields').style.display = 'grid';
            if (type === 'tool') document.getElementById('tool_fields').style.display = 'grid';
            if (type === 'time') document.getElementById('time_fields').style.display = 'block';
        }

        // Auto-show correct reason on page load
        const currentReason = "<?= strtolower($defect['reason'] ?? '') ?>";
        if (currentReason) showReason(currentReason.replace('part','part').replace('tool','tool').replace('time','time'));
    </script>
</body>
</html>