<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$is_admin = ($user['role'] === 'admin' || $user['allowed_fleet'] === 'ALL');
$user_fleet = $user['allowed_fleet'];
$forced_fleet = (!$is_admin && $user['is_fleet_locked']) ? $user_fleet : null;

$today = date('Y-m-d');
$min_date = date('Y-m-d', strtotime('-2 days'));

// Fetch active defects
$sql = $forced_fleet ? "WHERE fleet = ? AND status = 'ACTIVE'" : "WHERE status = 'ACTIVE'";
$params = $forced_fleet ? [$user_fleet] : [];
$stmt = $pdo->prepare("SELECT * FROM deferred_defects $sql ORDER BY due_date ASC");
$stmt->execute($params);
$defects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ethiopian Airlines - Deferred Defects System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #003087, #005eb8); min-height: 100vh; font-family: Arial, sans-serif; }
        .card { border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.6); }
        .card-header { background: #003087; color: white; padding: 25px; text-align: center; font-size: 1.5rem; }
        .form-label { font-weight: bold; color: #00205b; }
        .conditional { display: none; }
        .due-soon { background: #fff3cd !important; color: #856404; font-weight: bold; }
        .overdue { background: #f8d7da !important; color: #721c24; font-weight: bold; }
        .navbar-brand { font-weight: bold; font-size: 1.4rem; }
    </style>
</head>
<body>

<!-- NAVIGATION -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            Ethiopian Airlines - Deferred Defects
        </a>
        <div class="d-flex gap-2">
            <a href="view_defects.php" class="btn btn-outline-light">View All Defects</a>
            <a href="logout.php" class="btn btn-danger">Logout (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
        </div>
    </div>
</nav>

<div class="container mt-4 pb-5">

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <strong>SUCCESS!</strong> Deferred defect added successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add Defect Form -->
    <div class="card mb-5">
        <div class="card-header">
            <h3>ADD NEW DEFERRED DEFECT</h3>
            <p class="mb-0 text-warning">Logged in as: <strong><?= $_SESSION['username'] ?></strong> | Your Fleet: <strong><?= $user_fleet ?></strong></p>
        </div>
        <div class="card-body bg-light">
            <form action="add_defect.php" method="POST" class="row g-3">

                <?php if ($forced_fleet): ?>
                    <input type="hidden" name="fleet" value="<?= $user_fleet ?>">
                <?php endif; ?>

                <!-- Deferral Date -->
                <div class="col-md-3">
                    <label class="form-label">Deferral Date</label>
                    <input type="date" name="deferral_date" id="deferral_date" class="form-control" 
                           min="<?= $min_date ?>" max="<?= $today ?>" required onchange="calculateDueDate()">
                </div>

                <!-- Fleet -->
                <div class="col-md-3">
                    <label class="form-label">Fleet</label>
                    <?php if ($is_admin): ?>
                        <select name="fleet" class="form-select" onchange="updateReg(this.value)" required>
                            <option value="">Select Fleet</option>
                            <option value="A350">A350</option>
                            <option value="737">737</option>
                            <option value="787">787</option>
                            <option value="777">777</option>
                            <option value="Q400">Q400</option>
                            <option value="CARGO">CARGO</option>
                            <option value="TRAINING">TRAINING</option>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= $user_fleet ?>" readonly>
                    <?php endif; ?>
                </div>

                <!-- A/C Registration -->
                <div class="col-md-4">
                    <label class="form-label">A/C Registration</label>
                    <select name="ac_registration" id="reg_select" class="form-select" required>
                        <option value="">Select Aircraft</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">ADD Log No</label>
                    <input type="text" name="add_log_no" class="form-control">
                </div>

                <!-- Source -->
                <div class="col-md-4">
                    <label class="form-label">Source</label>
                    <select name="source" id="source" class="form-select" required onchange="toggleMelFields()">
                        <option value="MEL">MEL</option>
                        <option value="CDL">CDL</option>
                        <option value="NEF">NEF</option>
                    </select>
                </div>

                <!-- ATA + Sequence No (Only for MEL) -->
                <div class="col-md-5 conditional" id="ata_seq_field">
                    <label class="form-label">ATA + Sequence No</label>
                    <input type="text" name="ata_seq" class="form-control" placeholder="e.g. 27-11-00-001">
                </div>

                <!-- MEL Category + Auto Due Date -->
                <div class="col-md-4 conditional" id="mel_category_field">
                    <label class="form-label">Choose MEL Category <span class="text-danger">*</span></label>
                    <select name="mel_category" id="mel_category" class="form-select" required onchange="calculateDueDate()">
                        <option value="">-- Select Category --</option>
                        <option value="A">A → 24 Hours</option>
                        <option value="B">B → 3 Calendar Days</option>
                        <option value="C">C → 10 Calendar Days</option>
                        <option value="D">D → 120 Calendar Days</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Due Date (Auto Calculated)</label>
                    <input type="date" name="due_date" id="due_date" class="form-control" readonly style="background:#e9ecef; font-weight:bold; color:#d63384;">
                </div>

                <!-- Time Limit Source -->
                <div class="col-md-4">
                    <label class="form-label">Time Limit Source</label>
                    <select name="time_limit_source" class="form-select">
                        <option value="NONE">NONE</option>
                        <option value="AMM">AMM</option>
                        <option value="SRM">SRM</option>
                        <option value="OTHER">OTHER</option>
                    </select>
                </div>

                <!-- ETOPS Effect -->
                <div class="col-md-4">
                    <label class="form-label">ETOPS Effect</label>
                    <select name="etops_effect" class="form-select">
                        <option value="NONE">NONE</option>
                        <option value="NON ETOPS">NON ETOPS</option>
                        <option value="LIMITED ETOPS">LIMITED ETOPS</option>
                    </select>
                </div>

                <!-- Autoland Restrictions -->
                <div class="col-md-8">
                    <label class="form-label">Autoland Restrictions (Leave blank = NONE)</label><br>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="no_cat2" value="1"> <label class="form-check-label">NO CAT II</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="no_cat3a" value="1"> <label class="form-check-label">NO CAT IIIA</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="no_cat3b" value="1"> <label class="form-check-label">NO CAT IIIB</label>
                    </div>
                </div>

                <!-- TSFN -->
                <div class="col-md-4">
                    <label class="form-label">TSFN</label>
                    <input type="text" name="tsfn" class="form-control" placeholder="TSFN800XXXXX" required>
                </div>

                <!-- Reason for Deferral -->
                <div class="col-12">
                    <label class="form-label">Reason for Deferral (Select ONE only)</label><br>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="reason" value="PART" onchange="showReasonFields()"> <label class="form-check-label">PART</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="reason" value="TOOL" onchange="showReasonFields()"> <label class="form-check-label">TOOL</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="reason" value="TIME" onchange="showReasonFields()"> <label class="form-check-label">TIME</label>
                    </div>
                </div>

                <!-- Conditional Fields -->
                <div class="col-md-6 conditional" id="rid_field">
                    <label class="form-label">RID (Start with RSV)</label>
                    <input type="text" name="rid" class="form-control" placeholder="RSVXXXXXXXXXX">
                </div>
                <div class="col-md-6 conditional" id="part_no_field">
                    <label class="form-label">Part Number</label>
                    <input type="text" name="part_no" class="form-control">
                </div>
                <div class="col-md-3 conditional" id="part_qty_field">
                    <label class="form-label">Qty</label>
                    <input type="number" name="part_qty" class="form-control" min="1">
                </div>
                <div class="col-md-9 conditional" id="tool_name_field">
                    <label class="form-label">Tool Name</label>
                    <input type="text" name="tool_name" class="form-control">
                </div>
                <div class="col-md-6 conditional" id="ground_time_field">
                    <label class="form-label">Ground Time Needed (Hours)</label>
                    <input type="number" step="0.5" name="ground_time_hours" class="form-control" min="0.5">
                </div>

                <!-- Defect Description -->
                <div class="col-12">
                    <label class="form-label">Defect Description</label>
                    <textarea name="defect_desc" class="form-control" rows="4" required></textarea>
                </div>

                <!-- Deferred By -->
                <div class="col-md-6">
                    <label class="form-label">Deferred By (Full Name)</label>
                    <input type="text" name="deferred_by_name" class="form-control" required>
                </div>

                <!-- USER MUST TYPE THEIR ID -->
                <div class="col-md-6">
                    <label class="form-label">Your ID / Signature <span class="text-danger">*</span></label>
                    <input type="text" name="id_signature" class="form-control" placeholder="Type your ID here" required>
                </div>

                <div class="col-12 text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg px-5">
                        ADD DEFECT
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Active Defects Table -->
    <div class="card">
        <div class="card-header bg-warning text-dark">
            <h4>ACTIVE DEFECTS (<?= count($defects) ?>)</h4>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Fleet</th><th>Reg</th><th>Description</th><th>Due Date</th><th>MEL</th><th>Reason</th><th>TSFN</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($defects as $d):
                        $due = new DateTime($d['due_date'] ?? '2099-01-01');
                        $now = new DateTime();
                        $class = ($now > $due) ? 'overdue' : (($now->diff($due)->days <= 2) ? 'due-soon' : '');
                    ?>
                    <tr class="<?= $class ?>">
                        <td><strong><?= $d['fleet'] ?></strong></td>
                        <td><?= $d['ac_registration'] ?></td>
                        <td><?= htmlspecialchars(substr($d['defect_desc'], 0, 50)) ?>...</td>
                        <td><?= $d['due_date'] ?? '-' ?></td>
                        <td><?= $d['mel_category'] ?? '-' ?></td>
                        <td><?= $d['reason'] ?? '-' ?></td>
                        <td><?= $d['tsfn'] ?? '-' ?></td>
                        <td>
                            <a href="clear_defect.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-success">Clear</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Full Ethiopian Airlines fleet
const aircraft = {
    "A350": ["ET-ATQ","ET-ATR","ET-ATY","ET-AUA","ET-AUB","ET-AUC","ET-AVB","ET-AVC","ET-AVD","ET-AVE","ET-AWM","ET-AWN","ET-AWO","ET-AWP","ET-AYA","ET-AYB","ET-AYM","ET-AYN","ET-AZI","ET-AZN","ET-BCD","ET-BCE","ET-BAW","ET-BAX","ET-BAY","ET-BAZ"],
    "737": ["ET-ALM","ET-ALN","ET-APL","ET-APM","ET-APO","ET-AQN","ET-AQO","ET-AQP","ET-AQQ","ET-ASJ","ET-AVX","ET-AWC","ET-AWR","ET-AWS","ET-AXI","ET-AXO","ET-AYL","ET-AYP","ET-AYU","ET-AZY","ET-AZZ","ET-BAG","ET-BAH","ET-BBR","ET-AVI","ET-AVK","ET-AVL","ET-AVM","ET-AWF","ET-AWG","ET-AWH","ET-AWI","ET-AWJ","ET-AWK","ET-AXG","ET-AZA","ET-AZO","ET-BAI","ET-BAJ","ET-BAK","ET-BAL","ET-BAM","ET-BAN","ET-BAO","ET-BBA","ET-BBC","ET-BAQ","ET-BAR","ET-BAT","ET-BAU","ET-BBB","ET-BBD"],
    "787": ["ET-AOO","ET-AOP","ET-AOQ","ET-AOR","ET-AOS","ET-AOT","ET-AOU","ET-AOV","ET-ARE","ET-ARF","ET-ASG","ET-ASH","ET-ASI","ET-ATG","ET-ATH","ET-ATI","ET-ATJ","ET-ATK","ET-ATL","ET-BCC","ET-AUO","ET-AUP","ET-AUQ","ET-AUR","ET-AXK","ET-AXL","ET-AXS","ET-AXT","ET-AYC","ET-AYD"],
    "777": ["ET-ANN","ET-ANO","ET-ANP","ET-ANQ","ET-ANR","ET-AQL","ET-APX","ET-APY","ET-ASK","ET-ASL","ET-BBG","ET-APS","ET-APU","ET-ARI","ET-ARJ","ET-ARK","ET-AVN","ET-AVQ","ET-AVT","ET-AWE","ET-BAA","ET-BAB","ET-BAC"],
    "Q400": ["ET-ANI","ET-ANJ","ET-ANK","ET-ANL","ET-ANV","ET-ANX","ET-AQB","ET-AQD","ET-AQE","ET-AQF","ET-ARL","ET-ARM","ET-ARN","ET-ASA","ET-AUD","ET-AUE","ET-AVA","ET-AVH","ET-AVR","ET-AXE","ET-AXF","ET-AXP","ET-AXW","ET-AXX","ET-AXY","ET-AXZ","ET-AYF","ET-AYG","ET-AYH"],
    "CARGO": ["ET-APS","ET-APU","ET-ARI","ET-ARJ","ET-ARK","ET-AVN","ET-AVQ","ET-AVT","ET-AWE","ET-BAA","ET-BAB","ET-BAC"],
    "TRAINING": ["ET-AQY","ET-ASP","ET-ASQ","ET-ASR","ET-ASS","ET-AST","ET-ASU","ET-ASV","ET-AOH","ET-AOI","ET-AOJ","ET-AOY","ET-APA","ET-APB","ET-APC","ET-APD","ET-AUH","ET-AUI","ET-AUJ","ET-AWT","ET-AWU","ET-AWV","ET-AWW","ET-AXA","ET-AXB","ET-AXC","ET-AXD","ET-AZC","ET-AZD","ET-AZE","ET-AZF","ET-AZH","ET-BBS","ET-BBT","ET-BBU","ET-BBV","ET-BBW","ET-BBX","ET-BBY","ET-BBZ","ET-APG","ET-APH","ET-AWA","ET-AWB","ET-BCA","ET-BCB"]
};

function updateReg(fleet) {
    const sel = document.getElementById("reg_select");
    sel.innerHTML = "<option value=''>Select Aircraft</option>";
    if (aircraft[fleet]) {
        aircraft[fleet].sort().forEach(reg => {
            sel.innerHTML += `<option value="${reg}">${reg}</option>`;
        });
    }
}
<?php if ($forced_fleet): ?>updateReg("<?= $user_fleet ?>");<?php endif; ?>

function toggleMelFields() {
    const show = document.getElementById("source").value === "MEL";
    document.getElementById("ata_seq_field").style.display = show ? "block" : "none";
    document.getElementById("mel_category_field").style.display = show ? "block" : "none";
    if (!show) {
        document.getElementById("mel_category").value = "";
        document.getElementById("due_date").value = "";
    }
}

function showReasonFields() {
    const reason = document.querySelector('input[name="reason"]:checked')?.value;
    document.querySelectorAll('.conditional').forEach(el => el.style.display = 'none');
    if (reason === "PART") {
        ["rid_field", "part_no_field", "part_qty_field"].forEach(id => document.getElementById(id).style.display = "block");
    }
    if (reason === "TOOL") {
        ["tool_name_field", "part_no_field", "part_qty_field"].forEach(id => document.getElementById(id).style.display = "block");
    }
    if (reason === "TIME") {
        document.getElementById("ground_time_field").style.display = "block";
    }
}

function calculateDueDate() {
    const cat = document.getElementById("mel_category").value;
    const dateStr = document.getElementById("deferral_date").value;
    if (!dateStr || !cat) {
        document.getElementById("due_date").value = "";
        return;
    }
    const date = new Date(dateStr);
    const days = { "A": 1, "B": 3, "C": 10, "D": 120 };
    date.setDate(date.getDate() + days[cat]);
    document.getElementById("due_date").value = date.toISOString().split('T')[0];
}

// Initialize on load
document.addEventListener("DOMContentLoaded", toggleMelFields);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>