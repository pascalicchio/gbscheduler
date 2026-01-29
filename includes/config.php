<?php
/**
 * Global Configuration & Helper Functions
 * Include this at the top of every page
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../db.php';

// Set timezone
date_default_timezone_set('America/New_York');

/**
 * Check if user is authenticated
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user's role
 */
function getUserRole(): string {
    return $_SESSION['user_role'] ?? 'guest';
}

/**
 * Get current user's ID
 */
function getUserId(): int {
    return $_SESSION['user_id'] ?? 0;
}

/**
 * Check if current user is admin
 */
function isAdmin(): bool {
    return getUserRole() === 'admin';
}

/**
 * Check if current user is manager
 */
function isManager(): bool {
    return getUserRole() === 'manager';
}

/**
 * Check if current user can manage (admin or manager)
 */
function canManage(): bool {
    return isAdmin() || isManager();
}

/**
 * Require authentication - redirect to login if not logged in
 * @param array $allowedRoles Optional array of allowed roles
 */
function requireAuth(array $allowedRoles = []): void {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit();
    }

    if (!empty($allowedRoles) && !in_array(getUserRole(), $allowedRoles)) {
        header("Location: dashboard.php");
        exit();
    }
}

/**
 * Set a flash message
 */
function setFlash(string $message, string $type = 'success'): void {
    $icon = $type === 'success' ? 'fa-check-circle' : ($type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');
    $_SESSION['flash_msg'] = "<div class='alert alert-{$type}'><i class='fas {$icon}'></i> {$message}</div>";
}

/**
 * Get and clear flash message
 */
function getFlash(): string {
    $msg = $_SESSION['flash_msg'] ?? '';
    unset($_SESSION['flash_msg']);
    return $msg;
}

/**
 * Escape HTML output
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
