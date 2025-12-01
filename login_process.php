<?php
// login_process.php - ONLY handles login logic
session_start();
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = "Please fill all fields";
    header("Location: index.php");
    exit();
}

// Fetch user from database
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // SUCCESS - Create session
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['allowed_fleet'] = $user['allowed_fleet'] ?? 'ALL';
    $_SESSION['is_fleet_locked'] = $user['is_fleet_locked'];

    // Update last login
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Clear any error and go to dashboard
    unset($_SESSION['login_error']);
    header("Location: dashboard.php");
    exit();
} else {
    $_SESSION['login_error'] = "Invalid username or password";
    header("Location: index.php");
    exit();
}
?>