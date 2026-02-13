<?php
// API Configuration
// Generate your secure API key with: openssl rand -hex 32

// API Keys (add more as needed)
define('API_KEYS', [
    '479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df' => 'OpenClaw Agent',
]);

// Rate limiting
define('RATE_LIMIT_REQUESTS', 100); // Max requests per hour
define('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// Cache settings
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour
define('CACHE_DIR', '/tmp/gbscheduler_api_cache');

// Create cache directory if it doesn't exist
if (CACHE_ENABLED && !is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// CORS settings (adjust for production)
define('CORS_ORIGIN', '*'); // Change to specific domain in production
define('CORS_METHODS', 'GET, OPTIONS');
define('CORS_HEADERS', 'Content-Type, Authorization');
