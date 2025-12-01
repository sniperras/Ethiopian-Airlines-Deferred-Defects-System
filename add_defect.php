<?php
session_start();
if (!isset($_SESSION['loggedin'])) die("Access denied");

$db = new PDO('sqlite:db/defects.db');

$stmt = $db->prepare("INSERT INTO defects (
  fleet, tail_number, defer_date, description, due_date, category,
  add_page, transfer_from, reference, reason, tsfn, rid, rid_status,
  part_number, responsible_section
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->execute([
  $_POST['fleet'], $_POST['tail_number'], $_POST['defer_date'],
  $_POST['description'], $_POST['due_date'], $_POST['category'],
  $_POST['add_page'], $_POST['transfer_from'], $_POST['reference'],
  $_POST['reason'], $_POST['tsfn'], $_POST['rid'], $_POST['rid_status'],
  $_POST['part_number'], $_POST['responsible_section']
]);

header("Location: dashboard.php?added=1");