<?php
/**
 * GB Scheduler API - Enrollment Trends
 * GET /api/v1/trends.php?key=YOUR_API_KEY&location=davenport&period=30days
 *
 * Returns enrollment and churn trends over time
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

// Get parameters
$location = $_GET['location'] ?? '';
if (!in_array($location, ['davenport', 'celebration'])) {
    sendError('Invalid location - must be "davenport" or "celebration"', 400);
}

$period = $_GET['period'] ?? '30days';
$valid_periods = ['7days', '30days', '90days', '1year'];
if (!in_array($period, $valid_periods)) {
    sendError('Invalid period - must be one of: ' . implode(', ', $valid_periods), 400);
}

// Log request
logApiRequest('/api/v1/trends?location=' . $location . '&period=' . $period, $client_name);

// Check cache
$cache_key = 'trends_' . $location . '_' . $period;
$cached_data = getCachedData($cache_key);
if ($cached_data) {
    sendJsonResponse($cached_data);
}

try {
    // Calculate date range based on period
    $period_map = [
        '7days' => 7,
        '30days' => 30,
        '90days' => 90,
        '1year' => 365
    ];
    $days = $period_map[$period];
    $start_date = date('Y-m-d', strtotime("-{$days} days"));
    $end_date = date('Y-m-d');

    // Get enrollment data (grouped by week for periods > 30 days)
    $group_by = $days > 30 ? 'week' : 'day';

    if ($group_by === 'day') {
        // Daily enrollments
        $stmt = $pdo->prepare("
            SELECT
                join_date as date,
                COUNT(*) as count
            FROM gb_members
            WHERE location = ? AND join_date >= ? AND join_date <= ?
            GROUP BY join_date
            ORDER BY join_date ASC
        ");
        $stmt->execute([$location, $start_date, $end_date]);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Daily churn
        $stmt = $pdo->prepare("
            SELECT
                cancellation_date as date,
                COUNT(DISTINCT member_name) as count
            FROM gb_cancellations
            WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
            GROUP BY cancellation_date
            ORDER BY cancellation_date ASC
        ");
        $stmt->execute([$location, $start_date, $end_date]);
        $churn = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Weekly enrollments
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(join_date, '%Y-%m-%d') as date,
                YEARWEEK(join_date) as week,
                COUNT(*) as count
            FROM gb_members
            WHERE location = ? AND join_date >= ? AND join_date <= ?
            GROUP BY week
            ORDER BY week ASC
        ");
        $stmt->execute([$location, $start_date, $end_date]);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Weekly churn
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(cancellation_date, '%Y-%m-%d') as date,
                YEARWEEK(cancellation_date) as week,
                COUNT(DISTINCT member_name) as count
            FROM gb_cancellations
            WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
            GROUP BY week
            ORDER BY week ASC
        ");
        $stmt->execute([$location, $start_date, $end_date]);
        $churn = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calculate net growth
    $total_enrollments = array_sum(array_column($enrollments, 'count'));
    $total_churn = array_sum(array_column($churn, 'count'));
    $net_growth = $total_enrollments - $total_churn;

    // Calculate average per period
    $avg_enrollments = count($enrollments) > 0 ? round($total_enrollments / count($enrollments), 2) : 0;
    $avg_churn = count($churn) > 0 ? round($total_churn / count($churn), 2) : 0;

    $response = [
        'location' => $location,
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'grouping' => $group_by,
        'enrollments' => array_map(function($item) {
            return [
                'date' => $item['date'],
                'count' => (int)$item['count']
            ];
        }, $enrollments),
        'churn' => array_map(function($item) {
            return [
                'date' => $item['date'],
                'count' => (int)$item['count']
            ];
        }, $churn),
        'summary' => [
            'total_enrollments' => (int)$total_enrollments,
            'total_churn' => (int)$total_churn,
            'net_growth' => (int)$net_growth,
            'avg_enrollments_per_' . $group_by => (float)$avg_enrollments,
            'avg_churn_per_' . $group_by => (float)$avg_churn
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
