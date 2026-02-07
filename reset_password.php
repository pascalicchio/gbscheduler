<?php
// reset_password.php - SET NEW PASSWORD
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = false;
$validToken = false;
$userId = null;

// Check token
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (!empty($token)) {
    // Verify token exists and is not expired
    $stmt = $pdo->prepare("
        SELECT pr.user_id, pr.expires_at, u.email
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
    ");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reset) {
        if (strtotime($reset['expires_at']) > time()) {
            $validToken = true;
            $userId = $reset['user_id'];
        } else {
            $error = 'This reset link has expired. Please request a new one.';
            // Clean up expired token
            $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        }
    } else {
        $error = 'Invalid reset link. Please request a new one.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);

        // Delete used token
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);

        // Redirect to login with success message
        header("Location: index.php?reset=1");
        exit();
    }
}

// Page setup
$pageTitle = 'Reset Password | GB Schedule';
$extraCss = <<<CSS

        /* ======================================== */
        /* CSS Variables - Mobile First */
        /* ======================================== */
        :root {
            --gradient-primary: linear-gradient(90deg, rgb(0, 201, 255), rgb(146, 254, 157));
            --gradient-hover: linear-gradient(90deg, rgb(0, 181, 235), rgb(126, 234, 137));
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --bg-color: #f8fafb;
            --input-bg: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 30px rgba(0, 201, 255, 0.12);
        }

        /* ======================================== */
        /* Base Layout - Mobile First */
        /* ======================================== */
        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
            background: var(--bg-color);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
        }

        /* Top gradient decoration */
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background-image: var(--gradient-primary);
            z-index: 1000;
        }

        /* ======================================== */
        /* Reset Card - Mobile First */
        /* ======================================== */
        .reset-card {
            background: white;
            padding: 32px 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 420px;
            text-align: center;
            border: 1px solid rgba(0, 201, 255, 0.1);
        }

        @media (min-width: 480px) {
            .reset-card {
                padding: 40px 32px;
            }
        }

        /* ======================================== */
        /* Logo Area */
        /* ======================================== */
        .logo-area {
            margin-bottom: 32px;
        }

        .logo-circle {
            width: 72px;
            height: 72px;
            background-image: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
            color: white;
            font-size: 1.75rem;
            box-shadow: var(--shadow-lg);
            animation: pulse-glow 3s ease-in-out infinite;
        }

        .logo-circle.error {
            background-image: linear-gradient(135deg, #fc5c7d, #6a82fb);
            box-shadow: 0 12px 30px rgba(252, 92, 125, 0.15);
        }

        @keyframes pulse-glow {
            0%, 100% {
                box-shadow: var(--shadow-lg);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 12px 35px rgba(0, 201, 255, 0.2);
                transform: scale(1.02);
            }
        }

        h2 {
            margin: 0;
            color: var(--text-dark);
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        @media (min-width: 480px) {
            h2 {
                font-size: 2rem;
            }
        }

        p.subtitle {
            color: var(--text-light);
            margin: 8px 0 32px 0;
            font-size: 0.95rem;
            font-weight: 400;
        }

        /* ======================================== */
        /* Form Elements - Touch Friendly */
        /* ======================================== */
        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.875rem;
            letter-spacing: 0.3px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #cbd5e0;
            transition: all 0.3s ease;
            z-index: 1;
            font-size: 1.1rem;
        }

        /* Touch-friendly inputs */
        .reset-card input[type="password"] {
            width: 100%;
            padding: 16px 16px 16px 48px !important;
            margin-bottom: 0 !important;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 1rem;
            background: var(--input-bg);
            transition: all 0.3s ease;
            color: var(--text-dark);
            -webkit-appearance: none;
        }

        .reset-card input:focus {
            border-color: rgb(0, 201, 255);
            outline: none;
            box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
            background: #fafbfc;
        }

        .input-wrapper:focus-within i {
            color: rgb(0, 201, 255);
            transform: translateY(-50%) scale(1.1);
        }

        .password-requirements {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 8px;
        }

        /* ======================================== */
        /* Messages */
        /* ======================================== */
        .error-msg {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            color: #c53030;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid rgba(229, 62, 62, 0.2);
            font-size: 0.9rem;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 500;
        }

        /* ======================================== */
        /* Button - Gradient Primary */
        /* ======================================== */
        button[type="submit"],
        .reset-card button {
            width: 100%;
            padding: 16px 24px;
            background-image: var(--gradient-primary) !important;
            background-color: transparent !important;
            color: white !important;
            border: none !important;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 12px;
            box-shadow: 0 6px 20px rgba(0, 201, 255, 0.25);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }

        @media (min-width: 480px) {
            button[type="submit"],
            .reset-card button {
                padding: 18px 24px;
                font-size: 1.1rem;
            }
        }

        button::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        button[type="submit"]:hover,
        .reset-card button:hover {
            background-image: var(--gradient-hover) !important;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 201, 255, 0.35);
        }

        button:hover::before {
            left: 100%;
        }

        button[type="submit"]:active,
        .reset-card button:active {
            transform: translateY(0);
            box-shadow: 0 4px 15px rgba(0, 201, 255, 0.3);
        }

        /* ======================================== */
        /* Back Link & Footer */
        /* ======================================== */
        .back-link {
            display: inline-block;
            margin-top: 24px;
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            padding: 4px 0;
        }

        .back-link:hover {
            color: rgb(0, 201, 255);
            text-decoration: underline;
        }

        .footer-text {
            margin-top: 32px;
            font-size: 0.8rem;
            color: #a0aec0;
            font-weight: 400;
        }
CSS;

require_once 'includes/header.php';
?>

    <div class="reset-card">
        <?php if (!$validToken && !empty($token)): ?>
            <div class="logo-area">
                <div class="logo-circle error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2>Link Invalid</h2>
                <p class="subtitle"><?= e($error) ?></p>
            </div>
            <a href="forgot_password.php" class="back-link"><i class="fas fa-arrow-left"></i> Request New Link</a>

        <?php elseif (empty($token)): ?>
            <div class="logo-area">
                <div class="logo-circle error">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2>Missing Token</h2>
                <p class="subtitle">No reset token provided.</p>
            </div>
            <a href="forgot_password.php" class="back-link"><i class="fas fa-arrow-left"></i> Request Reset Link</a>

        <?php else: ?>
            <div class="logo-area">
                <div class="logo-circle">
                    <i class="fas fa-lock"></i>
                </div>
                <h2>Reset Password</h2>
                <p class="subtitle">Enter your new password</p>
            </div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-circle"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter new password" required autofocus>
                        <i class="fas fa-lock"></i>
                    </div>
                    <p class="password-requirements">Minimum 6 characters</p>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                        <i class="fas fa-lock"></i>
                    </div>
                </div>

                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="footer-text">
            &copy; <?= date('Y') ?> GB Schedule System
        </div>
    </div>

<?php require_once 'includes/footer.php'; ?>
