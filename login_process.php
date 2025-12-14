<?php
// login_process.php
session_start();
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$email = $_POST['email'];
$password = $_POST['password'];

try {
    $stmt = $pdo->prepare("SELECT id, name, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Authentication SUCCESS
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role']; // 'admin' or 'user'

        header("Location: dashboard.php");
        exit();
    } else {
        // Authentication FAILED
        header("Location: index.php?error=1");
        exit();
    }
} catch (PDOException $e) {
    // Log the error
    error_log("Login error: " . $e->getMessage());
    header("Location: index.php?error=2"); // Generic error message
    exit();
}
?>