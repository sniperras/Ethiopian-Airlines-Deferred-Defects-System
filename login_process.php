<?php
// login_process.php - PLAIN TEXT VERSION (very simple)
session_start();
require_once 'db_connect.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username == '' || $password == '') {
    $_SESSION['login_error'] = "Fill all fields";
    header("Location: index.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ? LIMIT 1");
$stmt->execute([$username, $password]);
$user = $stmt->fetch();

if ($user) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['allowed_fleet'] = $user['allowed_fleet'];
    header("Location: dashboard.php");
    exit();
} else {
    $_SESSION['login_error'] = "Wrong username or password";
    header("Location: index.php");
    exit();
}
?>