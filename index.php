<?php
// index.php - LOGIN PAGE
session_start();

// Redirect if already logged in
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
    <title>Login | GB Schedule</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #007bff;
            --primary-hover: #0056b3;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --bg-color: #f0f4f8;
            --input-bg: #ffffff;
            --border-color: #e1e4e8;
        }

        body {
            font-family: sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: var(--bg-color);
            color: var(--text-dark);
        }

        /* Top decoration line for the page */
        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary) 0%, #00d2ff 100%);
        }

        .login-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
            /* Soft, modern shadow */
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05), 0 2px 5px rgba(0, 0, 0, 0.02);
            width: 100%;
            max-width: 380px;
            text-align: center;
            border: 1px solid white;
        }

        .logo-area {
            margin-bottom: 20px;
        }

        .logo-circle {
            width: 60px;
            height: 60px;
            background: rgba(0, 123, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px auto;
            color: var(--primary);
            font-size: 1.5rem;
        }

        h2 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1.5rem;
            font-weight: 700;
        }

        p.subtitle {
            color: var(--text-light);
            margin: 5px 0 30px 0;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #ccc;
            transition: color 0.3s;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px 12px 40px;
            /* Space for icon */
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 0.95rem;
            background: var(--input-bg);
            transition: all 0.2s ease;
            color: #333;
        }

        input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
        }

        input:focus+i {
            color: var(--primary);
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 10px;
            box-shadow: 0 4px 6px rgba(0, 123, 255, 0.15);
        }

        button:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        button:active {
            transform: translateY(1px);
        }

        .error-msg {
            background-color: #fff5f5;
            color: #e02424;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #fed7d7;
            font-size: 0.85rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .footer-text {
            margin-top: 30px;
            font-size: 0.8rem;
            color: #999;
        }
    </style>
</head>

<body>

    <div class="login-card">
        <div class="logo-area">
            <div class="logo-circle">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <h2>Welcome Back</h2>
            <p class="subtitle">Sign in to your dashboard</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i> Invalid email or password.
            </div>
        <?php endif; ?>

        <form action="login_process.php" method="POST">
            <div class="form-group">
                <label for="email">Email</label>
                <div class="input-wrapper">
                    <input type="email" id="email" name="email" placeholder="coach@example.com" required autofocus>
                    <i class="fas fa-envelope"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" placeholder="Enter password" required>
                    <i class="fas fa-lock"></i>
                </div>
            </div>

            <button type="submit">Log In</button>
        </form>

        <div class="footer-text">
            &copy; <?= date('Y') ?> GB Schedule System
        </div>
    </div>

</body>

</html>