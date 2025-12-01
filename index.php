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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DefTrack | Ethiopian Airlines</title>

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- NEW HORIZONTAL CSS -->
    <link rel="stylesheet" href="assets/css/login.css?v=3">
</head>
<body>

    <div class="login-master">
        <div class="login-container">

            <!-- LEFT: Logo Section -->
            <div class="logo-section">
                <div class="logo-wrapper">
                    <img src="img/logo.png" alt="Ethiopian Airlines" class="et-logo">
                </div>
                <div class="logo-text">
                    <h1>DefTrack</h1>
                    <p>Acceptable Deferred Defect<br>Tracking System</p>
                </div>
            </div>

            <!-- RIGHT: Login Form -->
            <div class="form-section">
                <div class="form-box">

                    <h2>Welcome Back</h2>
                    <p class="subtitle">Sign in to continue</p>

                    <form action="login_process.php" method="post">

                        <?php if (isset($_SESSION['login_error'])): ?>
                            <div class="alert">
                                <i class="fa fa-exclamation-circle"></i>
                                <?= htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="input-group">
                            <i class="fa fa-user"></i>
                            <input type="text" name="username" placeholder="Username" required autocomplete="username">
                        </div>

                        <div class="input-group">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                        </div>

                        <button type="submit" class="btn-signin">
                            <i class="fa fa-arrow-right-to-bracket"></i>
                            SIGN IN
                        </button>

                        <div class="footer-text">
                            © 2025 Ethiopian Airlines Engineering
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</body>
</html>