<?php
require_once 'auth.php';
require_once 'db_connect.php';

$defect_id = $_GET['id'] ?? 0;
$defect_id = (int)$defect_id;

if ($defect_id <= 0) {
    die("Invalid defect ID.");
}

// Fetch the defect
$stmt = $pdo->prepare("SELECT * FROM deferred_defects WHERE id = ?");
$stmt->execute([$defect_id]);
$defect = $stmt->fetch();

if (!$defect) {
    die("Defect not found.");
}

if (strtoupper($defect['status']) !== 'ACTIVE') {
    $_SESSION['error'] = "This defect is already cleared or invalid.";
    header("Location: view_all_defects.php");
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logbook_no = trim($_POST['cleared_logbook_no'] ?? '');
    $signature  = $_SESSION['username']; // You can change to full name later

    if (empty($logbook_no)) {
        $error = "Logbook/MNT page number is required.";
    } else {
        // Update the defect
        $sql = "UPDATE deferred_defects SET 
                    status = 'CLEARED',
                    cleared_logbook_no = ?,
                    cleared_by_id = ?,
                    cleared_by_sig = ?,
                    cleared_date = CURDATE()
                WHERE id = ? AND status = 'ACTIVE'";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([$logbook_no, $signature, $signature, $defect_id]);

        if ($success) {
            $_SESSION['success'] = "Defect successfully CLEARED for A/C <strong>{$defect['ac_registration']}</strong>";
            header("Location: view_all_defects.php");
            exit();
        } else {
            $error = "Failed to clear defect. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Defect | DefTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=5">
    <style>
        .clear-card {
            max-width: 700px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 10px 35px rgba(27,60,83,0.2);
            border-top: 6px solid #27ae60;
        }
        .defect-info {
            background: #f8fbff;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 5px solid #234C6A;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
            font-size: 0.95rem;
        }
        .info-label {
            font-weight: 600;
            color: #234C6A;
        }
        .btn-clear-final {
            background: #27ae60;
            color: white;
            padding: 14px 32px;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px auto 0;
            transition: 0.4s;
        }
        .btn-clear-final:hover {
            background: #219653;
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(39,174,96,0.4);
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><h2>DefTrack</h2></div>
        <div class="nav-user">
            <span><i class="fa fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="view_all_defects.php" class="btn-back"><i class="fa fa-arrow-left"></i> Back to List</a>
        </div>
    </nav>

    <div class="container-compact">

        <div class="clear-card">
            <h2 class="text-2xl font-bold text-center mb-8" style="color:#1B3C53;">
                <i class="fa fa-check-circle" style="color:#27ae60;"></i> Clear Deferred Defect
            </h2>

            <?php if (isset($error)): ?>
                <div style="background:#ffe6e6; color:#c33; padding:15px; border-radius:10px; margin-bottom:20px; border-left:5px solid #c33;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="defect-info">
                <h3 style="margin:0 0 15px; color:#234C6A;"><strong>Defect Details</strong></h3>
                <div class="info-grid">
                    <div><span class="info-label">A/C Registration:</span><br><strong><?= htmlspecialchars($defect['ac_registration']) ?></strong></div>
                    <div><span class="info-label">Fleet:</span><br><strong><?= htmlspecialchars($defect['fleet']) ?></strong></div>
                    <div><span class="info-label">ATA:</span><br><?= htmlspecialchars($defect['ata_seq']) ?></div>
                    <div><span class="info-label">MEL Category:</span><br><?= htmlspecialchars($defect['mel_category'] ?? '—') ?></div>
                    <div><span class="info-label">Due Date:</span><br><?= $defect['due_date'] ? date('d/m/Y', strtotime($defect['due_date'])) : '—' ?></div>
                    <div><span class="info-label">Reason:</span><br><?= htmlspecialchars($defect['reason'] ?? '—') ?></div>
                </div>
                <div style="margin-top:15px; padding:12px; background:#fff3cd; border-radius:8px; border-left:4px solid #ffc107;">
                    <strong>Description:</strong><br>
                    <?= nl2br(htmlspecialchars($defect['defect_desc'])) ?>
                </div>
            </div>

            <form method="POST">
                <div class="form-group full">
                    <label>Cleared Logbook / MNT Page No *</label>
                    <input type="text" 
                           name="cleared_logbook_no" 
                           required 
                           placeholder="e.g. LB-2456 or MNT-2025-789"
                           style="font-size:1rem; padding:12px;">
                </div>

                <div class="text-center" style="margin-top:30px;">
                    <p style="color:#456882; margin-bottom:15px;">
                        <i class="fa fa-info-circle"></i> 
                        This action will mark the defect as <strong style="color:#27ae60;">CLEARED</strong><br>
                        Cleared by: <strong><?= htmlspecialchars($_SESSION['username']) ?></strong> on <?= date('d M Y') ?>
                    </p>

                    <button type="submit" class="btn-clear-final">
                        <i class="fa fa-check-circle"></i> Confirm & Clear Defect
                    </button>
                </div>
            </form>

            <div class="text-center mt-8">
                <a href="view_all_defects.php" style="color:#456882; text-decoration:underline;">
                    <i class="fa fa-arrow-left"></i> Cancel and go back
                </a>
            </div>
        </div>

    </div>

</body>
</html>