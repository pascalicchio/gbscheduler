<?php
// forgot_password.php - REQUEST PASSWORD RESET
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Generate secure token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Delete any existing tokens for this user
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

            // Insert new token
            $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $token, $expires]);

            // Build reset URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $resetUrl = "{$protocol}://{$host}/reset_password.php?token={$token}";

            // Send email
            $to = $user['email'];
            $subject = "GB Scheduler - Password Reset";
            $message = "Hi {$user['name']},\n\n";
            $message .= "You requested a password reset. Click the link below to reset your password:\n\n";
            $message .= "{$resetUrl}\n\n";
            $message .= "This link will expire in 1 hour.\n\n";
            $message .= "If you didn't request this, please ignore this email.\n\n";
            $message .= "- GB Scheduler Team";

            $headers = "From: noreply@gbscheduler.com\r\n";
            $headers .= "Reply-To: noreply@gbscheduler.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            if (mail($to, $subject, $message, $headers)) {
                $success = true;
            } else {
                $error = 'Failed to send email. Please try again.';
            }
        } else {
            // Don't reveal if email exists or not (security)
            $success = true;
        }
    }
}

// Page setup
$pageTitle = 'Forgot Password | GB Schedule';
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
        .reset-card input[type="email"] {
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

        .success-msg {
            background: linear-gradient(135deg, #f0fff4 0%, #e6ffed 100%);
            color: #22543d;
            padding: 14px 16px;
            border-radius: 12px;
            border: 2px solid rgba(146, 254, 157, 0.3);
            font-size: 0.9rem;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 500;
        }

        .success-msg i {
            font-size: 2rem;
            display: block;
            margin-bottom: 10px;
            color: rgb(0, 201, 255);
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
        <div class="logo-area">
            <div class="logo-circle">
                <i class="fas fa-key"></i>
            </div>
            <h2>Forgot Password</h2>
            <p class="subtitle">Enter your email to reset your password</p>
        </div>

        <?php if ($success): ?>
            <div class="success-msg">
                <i class="fas fa-envelope"></i>
                If an account exists with that email, you'll receive a password reset link shortly.
            </div>
            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-circle"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" placeholder="coach@example.com" required autofocus>
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>

                <button type="submit">Send Reset Link</button>
            </form>

            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <?php endif; ?>

        <div class="footer-text">
            &copy; <?= date('Y') ?> GB Schedule System
        </div>
    </div>

<?php require_once 'includes/footer.php'; ?>
