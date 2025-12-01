<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ethiopian Airlines - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #003087, #005eb8); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .login-card { max-width: 400px; width: 100%; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
        .card-header { background: #003087; color: white; text-align: center; padding: 25px; }
        .btn-login { background: #003087; border: none; height: 50px; font-size: 18px; }
        .btn-login:hover { background: #00205b; }
    </style>
</head>
<body>
    <div class="login-card card">
        <div class="card-header">
            <h3>Ethiopian Airlines</h3>
            <p class="mb-0">Deferred Defects System</p>
        </div>
        <div class="card-body p-4">
            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= $_SESSION['login_error'] ?>
                    <?php unset($_SESSION['login_error']); ?>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="post">
                <div class="mb-3">
                    <label class="form-label text-dark fw-bold">Username</label>
                    <input type="text" name="username" class="form-control form-control-lg" required autofocus placeholder="Enter username">
                </div>
                <div class="mb-4">
                    <label class="form-label text-dark fw-bold">Password</label>
                    <input type="password" name="password" class="form-control form-control-lg" required placeholder="Enter password">
                </div>
                <button type="submit" class="btn btn-primary btn-login w-100">LOGIN</button>
            </form>

            <hr>
            <div class="text-center small text-muted">
                <strong>Test Account</strong><br>
                Username: <code>cargo36689</code><br>
                Password: <code>1234567</code>
            </div>
        </div>
    </div>
</body>
</html>