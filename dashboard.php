<?php
require_once 'db_connect.php';
require_once 'auth.php';

// Get current logged-in user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch();

$is_admin = ($current_user['role'] === 'admin' || $current_user['allowed_fleet'] === 'ALL');
$user_fleet = $current_user['allowed_fleet'];  // e.g. CARGO, 737, etc.
$is_locked = $current_user['is_fleet_locked'] ?? 1;

// For normal users → force their fleet
$forced_fleet = (!$is_admin && $is_locked) ? $user_fleet : '';

// Fetch defects (optional: only show user's fleet if not admin)
$where = (!$is_admin && $is_locked) ? "WHERE fleet = ?" : "";
$params = (!$is_admin && $is_locked) ? [$user_fleet] : [];

$sql = "SELECT * FROM deferred_defects $where ORDER BY due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$defects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deferred Defects - Ethiopian Airlines</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .due-soon { background-color: #fff3cd !important; color: #856404; font-weight: bold; }
        .overdue  { background-color: #f8d7da !important; color: #721c24; font-weight: bold; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark" style="background-color: #003087;">
    <div class="container-fluid">
        <span class="navbar-brand">ET Deferred Defects System</span>
        <div>
            <a href="view_defects.php" class="btn btn-light me-2">View All</a>
            <a href="logout.php" class="btn btn-outline-light">Logout (<?= htmlspecialchars($current_user['username']) ?>)</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-success text-white text-center">
            <h4>Add New Deferred Defect</h4>
            <?php if (!$is_admin): ?>
                <p class="mb-0"><strong>Your Fleet: <?= $user_fleet ?> FLEET</strong> (restricted access)</p>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form action="add_defect.php" method="POST" class="row g-3">

                <!-- FLEET SELECTION -->
                <?php if ($is_admin): ?>
                    <div class="col-md-4">
                        <label><strong>Fleet</strong></label>
                        <select name="fleet" class="form-select" required onchange="updateTails(this.value)">
                            <option value="">Select Fleet</option>
                            <option value="CARGO">CARGO FLEET</option>
                            <option value="737">737 FLEET</option>
                            <option value="Q400">Q400 FLEET</option>
                            <option value="777">777 FLEET</option>
                            <option value="787">787 FLEET</option>
                            <option value="AIRBUS">AIRBUS FLEET</option>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="fleet" value="<?= $user_fleet ?>">
                    <div class="col-md-4">
                        <label><strong>Fleet (Fixed)</strong></label>
                        <input type="text" class="form-control" value="<?= $user_fleet ?> FLEET" readonly>
                    </div>
                <?php endif; ?>

                <div class="col-md-4">
                    <label><strong>Aircraft Tail Number</strong></label>
                    <select name="tail_number" id="tail_number" class="form-select" required>
                        <option value="">Select Tail</option>
                    </select>
                </div>

                <!-- Rest of your form fields (same as before) -->
                <div class="col-md-4"><label>Deferred Date</label><input type="date" name="defer_date" class="form-control" required></div>
                <div class="col-md-4"><label>Due Date</label><input type="date" name="due_date" class="form-control" required></div>
                <div class="col-12"><label>Description</label><textarea name="description" class="form-control" rows="3" required></textarea></div>
                <div class="col-md-3"><label>Category</label>
                    <select name="category" class="form-select" required>
                        <option value="MEL A">MEL A</option><option value="MEL B">MEL B</option>
                        <option value="MEL C">MEL C</option><option value="MEL D">MEL D</option>
                        <option value="CDL">CDL</option><option value="NEF">NEF</option>
                    </select>
                </div>
                <div class="col-md-9"><label>Responsible Section</label><input type="text" name="responsible_section" class="form-control" required></div>

                <!-- Optional fields -->
                <div class="col-md-3"><label>ADD Page #</label><input type="text" name="add_page" class="form-control"></div>
                <div class="col-md-3"><label>Transfer From</label><input type="text" name="transfer_from" class="form-control"></div>
                <div class="col-md-3"><label>Reference</label><input type="text" name="reference" class="form-control"></div>
                <div class="col-md-3"><label>Reason</label><input type="text" name="reason" class="form-control"></div>
                <div class="col-md-3"><label>TSFN</label><input type="text" name="tsfn" class="form-control"></div>
                <div class="col-md-3"><label>RID</label><input type="text" name="rid" class="form-control"></div>
                <div class="col-md-3"><label>RID Status</label><input type="text" name="rid_status" class="form-control"></div>
                <div class="col-md-3"><label>Part Number</label><input type="text" name="part_number" class="form-control"></div>

                <div class="col-12 text-center mt-4">
                    <button type="submit" class="btn btn-success btn-lg">Add Defect</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary table (same color coding) -->
    <!-- ... (same table as before) ... -->
</div>

<script>
const fleetTails = {
    "CARGO": ["ET-ARH","ET-ARI","ET-ARJ","ET-ARK","ET-ARL","ET-ARM","ET-ARN"],
    "737": ["ET-AOK","ET-AOL","ET-AOM","ET-AQA","ET-AQB","ET-AQC","ET-AQD","ET-AQE"],
    "Q400": ["ET-AQA","ET-AQB","ET-AQC","ET-AQD","ET-AQE"],
    "777": ["ET-ANR","ET-ANS","ET-ANT","ET-ANU","ET-ANV"],
    "787": ["ET-AOQ","ET-AOR","ET-AOS","ET-AOT","ET-AOU","ET-AUP","ET-AUR","ET-AUS"],
    "AIRBUS": ["ET-ATQ","ET-ATR","ET-ATS","ET-ATT","ET-ATU","ET-ATV"]
};

// Auto-fill tail numbers when fleet is forced
<?php if ($forced_fleet): ?>
    updateTails("<?= $forced_fleet ?>");
    document.querySelector('[name="fleet"]').closest('.col-md-4').style.display = 'none';
<?php endif; ?>

function updateTails(fleet) {
    const select = document.getElementById("tail_number");
    select.innerHTML = '<option value="">Select Tail</option>';
    if (fleetTails[fleet]) {
        fleetTails[fleet].forEach(t => {
            select.innerHTML += `<option value="${t}">${t}</option>`;
        });
    }
}
</script>
</body>
</html>