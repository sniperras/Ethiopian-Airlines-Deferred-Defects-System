<?php
// db_connect.php - CHANGE ONLY THESE 4 LINES WITH YOUR HOSTING DETAILS
$host     = 'sql100.infinityfree.com';
$dbname   = 'if0_40564648_et_deferred_defects';     // ← CHANGE TO YOUR DATABASE NAME
$username = 'if0_40564648';        // ← YOUR DB USERNAME
$password = 'a7kjNsy8mU';    // ← YOUR DB PASSWORD (from hosting panel)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>