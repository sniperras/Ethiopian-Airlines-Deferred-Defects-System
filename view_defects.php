<?php
session_start();
if (!isset($_SESSION['loggedin'])) header("Location: index.php");
$db = new PDO('sqlite:db/defects.db');
$defects = $db->query("SELECT * FROM defects ORDER BY due_date ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <title>All Deferred Defects</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .due-soon { background-color: #fff3cd !important; }
    .overdue { background-color: #f8d7da !important; color: #721c24; font-weight: bold; }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>All Deferred Defects (<?= count($defects) ?>)</h2>
  <a href="dashboard.php" class="btn btn-primary mb-3">← Back to Add</a>
  <div class="table-responsive">
    <table class="table table-striped table-hover">
      <thead class="table-dark">
        <tr>
          <th>Fleet</th>
          <th>Tail</th>
          <th>Description</th>
          <th>Due Date</th>
          <th>Category</th>
          <th>Days Left</th>
          <th>Section</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($defects as $d): 
          $due = new DateTime($d['due_date']);
          $now = new DateTime();
          $days = $now->diff($due)->days;
          $invert = $now > $due;
          $class = '';
          if ($invert) {
            $class = 'overdue';
            $days = "-$days";
          } elseif ($days <= 2) {
            $class = 'due-soon';
          }
        ?>
          <tr class="<?= $class ?>">
            <td><?= htmlspecialchars($d['fleet']) ?></td>
            <td><?= htmlspecialchars($d['tail_number']) ?></td>
            <td><?= htmlspecialchars($d['description']) ?></td>
            <td><?= $d['due_date'] ?></td>
            <td><?= htmlspecialchars($d['category']) ?></td>
            <td><?= $days ?> days</td>
            <td><?= htmlspecialchars($d['responsible_section']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>