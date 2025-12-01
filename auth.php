<?php
// auth.php  ← ONLY this file starts the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Optional: refresh user data from DB (recommended)
require_once 'db_connect.php';
$stmt = $pdo->prepare("SELECT username, role, allowed_fleet, is_fleet_locked FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user) {
    $_SESSION['username']       = $user['username'];
    $_SESSION['role']           = $user['role'];
    $_SESSION['allowed_fleet']  = $user['allowed_fleet'];
    $_SESSION['is_fleet_locked']= $user['is_fleet_locked'];
}
?>