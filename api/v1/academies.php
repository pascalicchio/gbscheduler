<?php
/**
 * GB Scheduler API - Academy Overview
 * GET /api/v1/academies.php?key=YOUR_API_KEY
 *
 * Returns member counts, revenue, and growth metrics for all locations
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../auth.php';

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJsonResponse(['status' => 'ok']);
}

// Validate API key
$client_name = validateApiKey();
if (!$client_name) {
    sendError('Unauthorized - Invalid or missing API key', 401);
}

// Check rate limit
if (!checkRateLimit($_GET['key'] ?? '')) {
    sendError('Rate limit exceeded - Max ' . RATE_LIMIT_REQUESTS . ' requests per hour', 429);
}

// Log request
logApiRequest('/api/v1/academies', $client_name);

// Check cache
$cache_key = 'academies_overview';
$cached_data = getCachedData($cache_key);
if ($cached_data) {
    sendJsonResponse($cached_data);
}

try {
    // Calculate date ranges
    $today = date('Y-m-d');
    $first_of_month = date('Y-m-01');
    $last_month_start = date('Y-m-01', strtotime('first day of last month'));
    $last_month_end = date('Y-m-t', strtotime('last day of last month'));

    $response = [];

    // Get data for each location
    foreach (['davenport', 'celebration'] as $location) {
        // Active members
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM gb_members WHERE location = ? AND status = 'active'");
        $stmt->execute([$location]);
        $active_members = $stmt->fetchColumn();

        // Members on hold
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM gb_holds
            WHERE location = ? AND CURDATE() BETWEEN begin_date AND end_date
        ");
        $stmt->execute([$location]);
        $on_hold = $stmt->fetchColumn();

        // Note: ZenPlanner members CSV includes members on hold
        // So active_members is the final count, no subtraction needed

        // New members this month
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM gb_members
            WHERE location = ? AND join_date >= ? AND join_date <= ?
        ");
        $stmt->execute([$location, $first_of_month, $today]);
        $new_this_month = $stmt->fetchColumn();

        // Churned this month (unique members)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT member_name) FROM gb_cancellations
            WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
        ");
        $stmt->execute([$location, $first_of_month, $today]);
        $churned_this_month = $stmt->fetchColumn();

        // MRR (Monthly Recurring Revenue) - revenue this month
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
            WHERE location = ? AND payment_date >= ? AND payment_date <= ?
        ");
        $stmt->execute([$location, $first_of_month, $today]);
        $mrr = $stmt->fetchColumn();

        // Last month MRR for comparison
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
            WHERE location = ? AND payment_date >= ? AND payment_date <= ?
        ");
        $stmt->execute([$location, $last_month_start, $last_month_end]);
        $mrr_last_month = $stmt->fetchColumn();

        // ARM (Average Revenue per Member)
        $arm = $active_members > 0 ? round($mrr / $active_members, 2) : 0;

        // Net growth
        $net_growth = $new_this_month - $churned_this_month;

        // Retention rate
        $churn_rate = $active_members > 0 ? ($churned_this_month / $active_members) * 100 : 0;
        $retention_rate = 100 - $churn_rate;

        $response[$location] = [
            'total_members' => (int)$active_members,
            'active_members' => (int)$active_members,
            'on_hold' => (int)$on_hold,
            'new_this_month' => (int)$new_this_month,
            'churned_this_month' => (int)$churned_this_month,
            'net_growth' => (int)$net_growth,
            'mrr' => (float)$mrr,
            'mrr_last_month' => (float)$mrr_last_month,
            'mrr_growth' => $mrr_last_month > 0 ? round((($mrr - $mrr_last_month) / $mrr_last_month) * 100, 2) : 0,
            'arm' => (float)$arm,
            'retention_rate' => round($retention_rate, 2),
            'churn_rate' => round($churn_rate, 2),
            'last_updated' => date('c')
        ];
    }

    $response['generated_at'] = date('c');
    $response['data_as_of'] = date('c');

    // Cache the response
    setCachedData($cache_key, $response);

    // Send response
    sendJsonResponse($response);

} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 500);
}
