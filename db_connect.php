<?php
// db_connect.php - CHANGE ONLY THESE 4 LINES WITH YOUR HOSTING DETAILS
$host     = 'sql202.infinityfree.com';
$dbname   = 'if0_40574136_et_deferred_defects';     // ← CHANGE TO YOUR DATABASE NAME
$username = 'if0_40574136';        // ← YOUR DB USERNAME
$password = 'T1YycIU1dv6';    // ← YOUR DB PASSWORD (from hosting panel)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>