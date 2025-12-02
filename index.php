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
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <!-- Dedicated Login Styles -->
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

    <div class="login-master">
        <div class="login-box" data-aos="fade-up">

            <div class="logo-mini">
                <img src="img/logo.png" alt="Ethiopian Airlines">
            </div>

            <h1>DefTrack</h1>
            <p>Acceptable Deferred Defect Track System</p>

            <form action="login_process.php" method="post">

                <?php if (isset($_SESSION['login_error'])): ?>
                    <div class="alert">
                        <i class="fa fa-exclamation-circle"></i>
                        <?= htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?>
                    </div>
                <?php endif; ?>

                <div class="input-wrap">
                    <i class="fa fa-user"></i>
                    <input type="text" name="username" placeholder="Username" required autocomplete="username">
                </div>

                <div class="input-wrap">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn-login">
                    <i class="fa fa-arrow-right-to-bracket"></i> SIGN IN
                </button>
            </form>

            <div class="tiny-footer">
                © 2025 Ethiopian Airlines
            </div>
        </div>
    </div>

    <!-- AOS Animation -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 800, once: true });
    </script>

</body>
</html>