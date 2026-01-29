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

        .reset-card input[type="email"] {
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

        .success-msg {
            background-color: #f0fff4;
            color: #22543d;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #9ae6b4;
            font-size: 0.9rem;
            margin-bottom: 20px;
            text-align: center;
        }

        .success-msg i {
            font-size: 2rem;
            display: block;
            margin-bottom: 10px;
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
