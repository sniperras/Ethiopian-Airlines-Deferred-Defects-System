<?php
// db_connect.php - CHANGE ONLY THESE 4 LINES WITH YOUR HOSTING DETAILS
$host     = 'localhost';
$dbname   = 'et_deferred_defects';     // ← CHANGE TO YOUR DATABASE NAME
$username = 'root';        // ← YOUR DB USERNAME
$password = '';    // ← YOUR DB PASSWORD (from hosting panel)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>