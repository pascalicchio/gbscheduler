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

        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary) 0%, #00d2ff 100%);
        }

        .reset-card {
            background: white;
            padding: 40px;
            border-radius: 12px;
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

        .logo-circle.error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
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
            z-index: 1;
        }

        .reset-card input[type="password"] {
            width: 100%;
            padding: 12px 15px 12px 42px !important;
            margin-bottom: 0 !important;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 0.95rem;
            background: var(--input-bg);
            transition: all 0.2s ease;
            color: #333;
        }

        .reset-card input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15);
        }

        .input-wrapper:focus-within i {
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

        .password-requirements {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .footer-text {
            margin-top: 30px;
            font-size: 0.8rem;
            color: #999;
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
