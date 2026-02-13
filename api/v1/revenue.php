<?php
/**
 * GB Scheduler API - Revenue Snapshot
 * GET /api/v1/revenue.php?key=YOUR_API_KEY&location=davenport
 *
 * Returns revenue metrics and projections for a specific location
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
    sendError('Rate limit exceeded', 429);
}

// Get location parameter
$location = $_GET['location'] ?? '';
if (!in_array($location, ['davenport', 'celebration'])) {
    sendError('Invalid location - must be "davenport" or "celebration"', 400);
}

// Log request
logApiRequest('/api/v1/revenue?location=' . $location, $client_name);

// Check cache
$cache_key = 'revenue_' . $location;
$cached_data = getCachedData($cache_key);
if ($cached_data) {
    sendJsonResponse($cached_data);
}

try {
    // Calculate date ranges
    $this_month_start = date('Y-m-01');
    $this_month_end = date('Y-m-t');
    $last_month_start = date('Y-m-01', strtotime('first day of last month'));
    $last_month_end = date('Y-m-t', strtotime('last day of last month'));
    $this_year_start = date('Y-01-01');

    // MRR (this month's revenue)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
        WHERE location = ? AND payment_date >= ? AND payment_date <= ?
    ");
    $stmt->execute([$location, $this_month_start, $this_month_end]);
    $mrr = $stmt->fetchColumn();

    // Last month's revenue
    $stmt->execute([$location, $last_month_start, $last_month_end]);
    $mrr_last_month = $stmt->fetchColumn();

    // Year-to-date revenue
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
        WHERE location = ? AND payment_date >= ? AND payment_date <= CURDATE()
    ");
    $stmt->execute([$location, $this_year_start]);
    $ytd_revenue = $stmt->fetchColumn();

    // Calculate growth
    $growth_percent = $mrr_last_month > 0
        ? round((($mrr - $mrr_last_month) / $mrr_last_month) * 100, 2)
        : ($mrr > 0 ? 100 : 0);

    // Projected annual (based on current month average)
    $days_in_month = date('t');
    $current_day = date('j');
    $projected_monthly = $current_day > 0 ? ($mrr / $current_day) * $days_in_month : 0;
    $projected_annual = $projected_monthly * 12;

    // Average revenue per month (YTD)
    $months_elapsed = date('n'); // 1-12
    $avg_monthly_revenue = $months_elapsed > 0 ? $ytd_revenue / $months_elapsed : 0;

    $response = [
        'location' => $location,
        'mrr' => (float)round($mrr, 2),
        'mrr_last_month' => (float)round($mrr_last_month, 2),
        'growth_percent' => (float)$growth_percent,
        'projected_monthly' => (float)round($projected_monthly, 2),
        'projected_annual' => (float)round($projected_annual, 2),
        'ytd_revenue' => (float)round($ytd_revenue, 2),
        'avg_monthly_revenue' => (float)round($avg_monthly_revenue, 2),
        'period' => [
            'current_month' => date('F Y'),
            'last_month' => date('F Y', strtotime('last month')),
            'year' => date('Y')
        ],
        'generated_at' => date('c')
    ];

    // Cache the response
    setCachedData($cache_key, $response);

    // Send response
    sendJsonResponse($response);

} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 500);
}
