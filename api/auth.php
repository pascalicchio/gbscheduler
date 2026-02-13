<?php
require_once __DIR__ . '/config.php';

/**
 * Validate API key from request
 * @return bool|string Returns key name if valid, false otherwise
 */
function validateApiKey() {
    $key = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (empty($key)) {
        return false;
    }

    if (isset(API_KEYS[$key])) {
        return API_KEYS[$key];
    }

    return false;
}

/**
 * Check rate limit for API key
 * @param string $api_key
 * @return bool True if within limit, false if exceeded
 */
function checkRateLimit($api_key) {
    $cache_file = "/tmp/api_rate_limit_" . md5($api_key) . ".txt";

    // Clean up old rate limit file if outside window
    if (file_exists($cache_file)) {
        $age = time() - filemtime($cache_file);
        if ($age > RATE_LIMIT_WINDOW) {
            unlink($cache_file);
        }
    }

    // Get current request count
    $requests = file_exists($cache_file) ? (int)file_get_contents($cache_file) : 0;

    if ($requests >= RATE_LIMIT_REQUESTS) {
        return false;
    }

    // Increment counter
    file_put_contents($cache_file, $requests + 1);

    return true;
}

/**
 * Log API request
 * @param string $endpoint
 * @param string $client_name
 */
function logApiRequest($endpoint, $client_name = 'unknown') {
    $log_file = __DIR__ . '/../logs/api.log';
    $log_dir = dirname($log_file);

    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log_entry = "{$timestamp} - {$ip} - {$client_name} - {$endpoint}\n";

    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Send JSON response with proper headers
 * @param mixed $data
 * @param int $status_code
 */
function sendJsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Methods: ' . CORS_METHODS);
    header('Access-Control-Allow-Headers: ' . CORS_HEADERS);

    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * @param string $message
 * @param int $status_code
 */
function sendError($message, $status_code = 400) {
    sendJsonResponse([
        'error' => $message,
        'timestamp' => date('c')
    ], $status_code);
}

/**
 * Get cached data if available and fresh
 * @param string $cache_key
 * @return mixed|null Returns cached data or null if expired/missing
 */
function getCachedData($cache_key) {
    if (!CACHE_ENABLED) {
        return null;
    }

    $cache_file = CACHE_DIR . '/' . md5($cache_key) . '.json';

    if (!file_exists($cache_file)) {
        return null;
    }

    $age = time() - filemtime($cache_file);
    if ($age > CACHE_TTL) {
        unlink($cache_file);
        return null;
    }

    return json_decode(file_get_contents($cache_file), true);
}

/**
 * Save data to cache
 * @param string $cache_key
 * @param mixed $data
 */
function setCachedData($cache_key, $data) {
    if (!CACHE_ENABLED) {
        return;
    }

    $cache_file = CACHE_DIR . '/' . md5($cache_key) . '.json';
    file_put_contents($cache_file, json_encode($data));
}
