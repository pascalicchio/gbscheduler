<?php
$pageTitle = 'School Dashboard | GB Scheduler';
require_once 'includes/config.php';
require_once 'db.php';

// Require admin access only
requireAuth(['admin']);

// Handle date range from form
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Calculate days in selected range
$daysInRange = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;

// Get comprehensive data for both locations
function getLocationStats($pdo, $location, $startDate, $endDate) {
    $stats = [];
    $daysInRange = (strtotime($endDate) - strtotime($startDate)) / 86400;

    // ========================================
    // BASIC COUNTS
    // ========================================

    // Active members (total registered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM gb_members WHERE location = ? AND status = 'active'");
    $stmt->execute([$location]);
    $stats['active_members'] = $stmt->fetchColumn();

    // Members on hold (current holds - active holds based on date range)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM gb_holds
        WHERE location = ?
        AND CURDATE() BETWEEN begin_date AND end_date
    ");
    $stmt->execute([$location]);
    $stats['on_hold'] = $stmt->fetchColumn();

    // Note: ZenPlanner members CSV includes members on hold
    // So active_members is the final count, no subtraction needed

    // ========================================
    // REVENUE METRICS
    // ========================================

    // Revenue (selected date range)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
        WHERE location = ? AND payment_date >= ? AND payment_date <= ?
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['revenue_period'] = $stmt->fetchColumn();

    // Previous period revenue for comparison
    $daysInRangeInt = (int)$daysInRange; // Ensure integer
    $prevStartDate = date('Y-m-d', strtotime($startDate . " -{$daysInRangeInt} days"));
    $prevEndDate = date('Y-m-d', strtotime($startDate . " -1 day"));

    // Debug: Log if dates look wrong
    if ($prevStartDate < '1900-01-01' || $prevStartDate > '2100-01-01' ||
        $prevEndDate < '1900-01-01' || $prevEndDate > '2100-01-01') {
        error_log("Invalid date calculation: prevStart=$prevStartDate, prevEnd=$prevEndDate, startDate=$startDate, daysInRange=$daysInRange");
        // Skip previous period comparison if dates are invalid
        $prevRevenue = 0;
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM gb_revenue
            WHERE location = ? AND payment_date >= ? AND payment_date <= ?
        ");
        $stmt->execute([$location, $prevStartDate, $prevEndDate]);
        $prevRevenue = $stmt->fetchColumn();
    }

    if ($prevRevenue > 0) {
        $stats['revenue_change'] = (($stats['revenue_period'] - $prevRevenue) / $prevRevenue) * 100;
    } else {
        $stats['revenue_change'] = $stats['revenue_period'] > 0 ? 100 : 0;
    }

    // Average Revenue Per Member (ARM)
    $stats['arm'] = $stats['active_members'] > 0 ? $stats['revenue_period'] / $stats['active_members'] : 0;

    // Calculate LTV (Lifetime Value)
    $stmt = $pdo->prepare("
        SELECT
            AVG(total_revenue) as avg_revenue,
            AVG(tenure_days) as avg_tenure
        FROM (
            SELECT
                m.member_id,
                COALESCE(SUM(r.amount), 0) as total_revenue,
                DATEDIFF(
                    CASE
                        WHEN MAX(c.cancellation_date) > m.join_date
                        THEN MAX(c.cancellation_date)
                        ELSE CURDATE()
                    END,
                    m.join_date
                ) as tenure_days
            FROM gb_members m
            LEFT JOIN gb_revenue r ON m.name = r.member_name AND m.location = r.location
            LEFT JOIN gb_cancellations c ON m.name = c.member_name AND m.location = c.location
            WHERE m.location = ?
            GROUP BY m.member_id, m.join_date
        ) as member_stats
    ");
    $stmt->execute([$location]);
    $ltvData = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['ltv'] = $ltvData['avg_revenue'] ?? 0;
    $stats['avg_tenure_days'] = $ltvData['avg_tenure'] ?? 0;
    $stats['avg_tenure_months'] = $stats['avg_tenure_days'] / 30;

    // ========================================
    // MEMBER LIFECYCLE METRICS
    // ========================================

    // New members in period
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM gb_members
        WHERE location = ? AND join_date >= ? AND join_date <= ?
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['new_members_period'] = $stmt->fetchColumn();

    // Cancellations in period (total membership plans)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM gb_cancellations
        WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['cancellations_period'] = $stmt->fetchColumn();

    // Unique members who cancelled (distinct people)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT member_name) FROM gb_cancellations
        WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['unique_members_cancelled'] = $stmt->fetchColumn();

    // Net member growth
    $stats['net_growth'] = $stats['new_members_period'] - $stats['cancellations_period'];
    $stats['net_growth_rate'] = $stats['active_members'] > 0
        ? ($stats['net_growth'] / $stats['active_members']) * 100
        : 0;

    // Churn rate (cancellations / total members at start)
    $stats['churn_rate'] = $stats['active_members'] > 0
        ? ($stats['cancellations_period'] / $stats['active_members']) * 100
        : 0;

    // Retention rate
    $stats['retention_rate'] = 100 - $stats['churn_rate'];

    // ========================================
    // HOLD METRICS
    // ========================================

    // Hold rate (% of members on hold)
    $stats['hold_rate'] = $stats['active_members'] > 0
        ? ($stats['on_hold'] / $stats['active_members']) * 100
        : 0;

    // Average hold duration
    $stmt = $pdo->prepare("
        SELECT AVG(DATEDIFF(COALESCE(end_date, CURDATE()), begin_date))
        FROM gb_holds WHERE location = ?
    ");
    $stmt->execute([$location]);
    $stats['avg_hold_duration'] = $stmt->fetchColumn() ?? 0;

    // Hold to cancel correlation
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT h.member_name)
        FROM gb_holds h
        INNER JOIN gb_cancellations c
            ON h.member_name = c.member_name
            AND h.location = c.location
            AND c.cancellation_date >= h.begin_date
        WHERE h.location = ?
    ");
    $stmt->execute([$location]);
    $holdsToCancel = $stmt->fetchColumn();
    $stats['hold_to_cancel_ratio'] = $stats['on_hold'] > 0
        ? ($holdsToCancel / $stats['on_hold']) * 100
        : 0;

    // Hold reasons
    $stmt = $pdo->prepare("
        SELECT reason, COUNT(*) as count
        FROM gb_holds
        WHERE location = ?
        GROUP BY reason
        ORDER BY count DESC
    ");
    $stmt->execute([$location]);
    $stats['hold_reasons'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ========================================
    // CANCELLATION ANALYSIS
    // ========================================

    // Top cancellation reasons
    $stmt = $pdo->prepare("
        SELECT reason, COUNT(*) as count
        FROM gb_cancellations
        WHERE location = ? AND cancellation_date >= ? AND cancellation_date <= ?
        GROUP BY reason
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['top_cancellation_reasons'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cancellation timing distribution
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN DATEDIFF(c.cancellation_date, COALESCE(m.join_date, DATE_SUB(c.cancellation_date, INTERVAL 180 DAY))) <= 90 THEN '0-3 months'
                WHEN DATEDIFF(c.cancellation_date, COALESCE(m.join_date, DATE_SUB(c.cancellation_date, INTERVAL 180 DAY))) <= 180 THEN '3-6 months'
                WHEN DATEDIFF(c.cancellation_date, COALESCE(m.join_date, DATE_SUB(c.cancellation_date, INTERVAL 180 DAY))) <= 365 THEN '6-12 months'
                ELSE '12+ months'
            END as tenure_bracket,
            COUNT(*) as count
        FROM gb_cancellations c
        LEFT JOIN gb_members m ON c.member_name = m.name AND c.location = m.location
        WHERE c.location = ? AND c.cancellation_date >= ? AND c.cancellation_date <= ?
        GROUP BY tenure_bracket
        ORDER BY FIELD(tenure_bracket, '0-3 months', '3-6 months', '6-12 months', '12+ months')
    ");
    $stmt->execute([$location, $startDate, $endDate]);
    $stats['cancellation_timing'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ========================================
    // TREND DATA (12 months)
    // ========================================

    // Revenue trend (last 12 months)
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            SUM(amount) as revenue
        FROM gb_revenue
        WHERE location = ? AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute([$location]);
    $stats['revenue_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // New members trend (last 12 months)
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(join_date, '%Y-%m') as month,
            COUNT(*) as count
        FROM gb_members
        WHERE location = ? AND join_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute([$location]);
    $stats['new_members_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ========================================
    // COHORT RETENTION
    // ========================================

    // Simple cohort analysis (last 6 months of cohorts)
    $stats['cohort_retention'] = [];
    for ($i = 5; $i >= 0; $i--) {
        $cohortMonth = date('Y-m', strtotime("-$i months"));
        $cohortStart = $cohortMonth . '-01';
        $cohortEnd = date('Y-m-t', strtotime($cohortStart));

        // Count members who joined in this cohort
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM gb_members
            WHERE location = ? AND join_date >= ? AND join_date <= ?
        ");
        $stmt->execute([$location, $cohortStart, $cohortEnd]);
        $cohortSize = $stmt->fetchColumn();

        if ($cohortSize > 0) {
            // Count how many are still active
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM gb_members m
                WHERE location = ?
                AND join_date >= ? AND join_date <= ?
                AND status = 'active'
                AND member_id NOT IN (
                    SELECT DISTINCT m2.member_id
                    FROM gb_members m2
                    INNER JOIN gb_cancellations c ON m2.name = c.member_name AND m2.location = c.location
                    WHERE m2.location = ?
                )
            ");
            $stmt->execute([$location, $cohortStart, $cohortEnd, $location]);
            $stillActive = $stmt->fetchColumn();

            $stats['cohort_retention'][] = [
                'month' => date('M Y', strtotime($cohortMonth)),
                'cohort_size' => $cohortSize,
                'still_active' => $stillActive,
                'retention_rate' => ($stillActive / $cohortSize) * 100
            ];
        }
    }

    return $stats;
}

$davenportStats = getLocationStats($pdo, 'davenport', $startDate, $endDate);
$celebrationStats = getLocationStats($pdo, 'celebration', $startDate, $endDate);

// Get latest data date (most recent date across all data sources)
$latestDataDate = $pdo->query("
    SELECT MAX(latest_date) as max_date FROM (
        SELECT MAX(payment_date) as latest_date FROM gb_revenue
        UNION ALL
        SELECT MAX(cancellation_date) FROM gb_cancellations
        UNION ALL
        SELECT MAX(join_date) FROM gb_members
        UNION ALL
        SELECT MAX(end_date) FROM gb_holds
    ) AS dates
")->fetchColumn();

// Combined stats for executive summary
$combined = [
    'total_members' => ($davenportStats['active_members'] + $celebrationStats['active_members']) + ($davenportStats['on_hold'] + $celebrationStats['on_hold']),
    'total_active' => $davenportStats['active_members'] + $celebrationStats['active_members'],
    'total_on_hold' => $davenportStats['on_hold'] + $celebrationStats['on_hold'],
    'total_revenue' => $davenportStats['revenue_period'] + $celebrationStats['revenue_period'],
    'avg_arm' => (($davenportStats['arm'] + $celebrationStats['arm']) / 2),
    'avg_ltv' => (($davenportStats['ltv'] + $celebrationStats['ltv']) / 2),
    'total_new_members' => $davenportStats['new_members_period'] + $celebrationStats['new_members_period'],
    'total_cancellations' => $davenportStats['cancellations_period'] + $celebrationStats['cancellations_period'],
    'avg_retention' => (($davenportStats['retention_rate'] + $celebrationStats['retention_rate']) / 2),
];

$extraCss = <<<CSS
/* Alpine.js cloak */
[x-cloak] {
    display: none !important;
}

/* Page Layout */
body {
    padding-top: 20px;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding: 0 20px;
}

.page-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-dark);
}

.page-header h2 i {
    background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

@media (min-width: 768px) {
    .page-header h2 {
        font-size: 1.75rem;
    }
}

/* Date Picker */
.date-picker-container {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    border: 1px solid #e8ecf2;
    padding: 20px 30px;
    margin: 0 auto 30px auto;
    max-width: 1600px;
}

.date-picker-form {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.date-input-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-input-group label {
    font-weight: 600;
    color: var(--color-dark);
    font-size: 0.9rem;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 6px;
}

.date-input-group input[type="date"] {
    padding: 10px 14px;
    border: 2px solid #e8ecf2;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.date-input-group input[type="date"]:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.1);
}

.btn-filter {
    background: var(--gradient-primary);
    color: white;
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-filter:hover {
    background: var(--gradient-primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 201, 255, 0.3);
}

.btn-reset {
    background: white;
    color: #666;
    padding: 10px 20px;
    border: 2px solid #e8ecf2;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    display: inline-block;
}

.btn-reset:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.date-range-display {
    font-size: 0.85rem;
    color: #666;
    font-weight: 500;
    margin-left: auto;
}

/* Dashboard Container */
.dashboard-container {
    max-width: 1600px;
    margin: 0 auto;
}

/* Executive Summary */
.executive-summary {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    border: 1px solid #e8ecf2;
    padding: 30px;
    margin-bottom: 30px;
}

.executive-summary h3 {
    margin: 0 0 20px 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-dark);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.data-freshness-badge {
    display: inline-block;
    background: white;
    color: #333;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: 2px solid transparent;
    background-image:
        linear-gradient(white, white),
        linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
    background-origin: border-box;
    background-clip: padding-box, border-box;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.summary-card {
    background: linear-gradient(135deg, #f8fafb 0%, #ffffff 100%);
    padding: 20px;
    border-radius: 10px;
    border: 2px solid #e8ecf2;
    transition: all 0.2s;
}

.summary-card:hover {
    border-color: var(--color-primary);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.summary-label {
    font-size: 12px;
    color: #666;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 8px;
    letter-spacing: 0.5px;
    position: relative;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--color-dark);
    margin-bottom: 4px;
}

.summary-subtext {
    font-size: 12px;
    color: #888;
}

.benchmark-indicator {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    margin-top: 6px;
}

.benchmark-excellent {
    background: #e8f5e9;
    color: #2e7d32;
}

.benchmark-good {
    background: #e3f2fd;
    color: #1565c0;
}

.benchmark-average {
    background: #fff3e0;
    color: #e65100;
}

.benchmark-below {
    background: #ffebee;
    color: #c62828;
}

/* Section Headers with Info Icons */
.section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}

.section-header h4 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--color-dark);
}

.info-icon {
    position: relative;
    cursor: help;
    color: var(--color-primary);
    font-size: 0.9rem;
}

.info-tooltip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    margin-bottom: 8px;
    padding: 12px 16px;
    background: #2c3e50;
    color: white;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.5;
    white-space: normal;
    width: 280px;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    pointer-events: none;
}

.info-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-top-color: #2c3e50;
}

.info-tooltip strong {
    display: block;
    margin-bottom: 6px;
    color: #92FE9D;
}

/* Location Comparison Grid */
.location-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

@media (max-width: 1024px) {
    .location-grid {
        grid-template-columns: 1fr;
    }
}

.location-section {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    border: 1px solid #e8ecf2;
    overflow: hidden;
}

.location-header {
    padding: 20px 30px;
    background: var(--gradient-primary);
    color: white;
    font-weight: 700;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.location-header.celebration {
    background: linear-gradient(90deg, #7b1fa2, #ba68c8);
}

.location-body {
    padding: 30px;
}

/* Metric Cards */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 30px;
}

.metrics-grid-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 30px;
}

.metrics-grid-4col {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 30px;
}

.metrics-grid-3col {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 30px;
}

@media (max-width: 1200px) {
    .metrics-grid-4col {
        grid-template-columns: repeat(2, 1fr);
    }

    .metrics-grid-3col {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .metrics-grid-2col {
        grid-template-columns: 1fr;
    }

    .metrics-grid-4col {
        grid-template-columns: 1fr;
    }
}

.metric-card {
    background: #f8fafb;
    padding: 16px;
    border-radius: 8px;
    border: 1px solid #e8ecf2;
}

.metric-label {
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    position: relative;
}

.metric-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--color-dark);
}

.metric-subtext {
    font-size: 11px;
    color: #888;
    margin-top: 4px;
}

.trend-positive {
    color: #2e7d32;
}

.trend-negative {
    color: #c62828;
}

/* Subsections */
.subsection {
    margin-bottom: 30px;
}

.subsection:last-child {
    margin-bottom: 0;
}

/* Simple Bar Chart */
.simple-chart {
    margin-top: 12px;
}

.chart-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.chart-label {
    min-width: 80px;
    font-size: 12px;
    font-weight: 600;
    color: #666;
}

.chart-bar-container {
    flex: 1;
    height: 24px;
    background: #e8ecf2;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
}

.chart-bar-fill {
    height: 100%;
    background: var(--gradient-primary);
    border-radius: 4px;
    transition: width 0.5s ease;
}

.chart-value {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-dark);
    min-width: 40px;
    text-align: right;
}

/* Cohort Table */
.cohort-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.cohort-table th {
    background: #f8fafb;
    padding: 10px;
    text-align: left;
    font-weight: 700;
    color: #666;
    border-bottom: 2px solid #e8ecf2;
}

.cohort-table td {
    padding: 10px;
    border-bottom: 1px solid #e8ecf2;
}

.cohort-table tr:hover {
    background: #f8fafb;
}

.retention-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 12px;
}

.retention-excellent {
    background: #e8f5e9;
    color: #2e7d32;
}

.retention-good {
    background: #e3f2fd;
    color: #1565c0;
}

.retention-average {
    background: #fff3e0;
    color: #e65100;
}

.retention-poor {
    background: #ffebee;
    color: #c62828;
}

/* Cancellation Reasons */
.reason-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.reason-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f8fafb;
    border-radius: 6px;
    margin-bottom: 8px;
}

.reason-text {
    font-weight: 500;
    color: var(--color-dark);
}

.reason-count {
    background: var(--color-primary);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
}

.empty-state i {
    font-size: 48px;
    color: #e8ecf2;
    margin-bottom: 16px;
}

.empty-state h3 {
    margin: 0 0 12px 0;
    color: var(--color-dark);
}

.empty-state p {
    color: #666;
    margin-bottom: 20px;
}

.btn-upload {
    background: var(--gradient-primary);
    color: white;
    padding: 14px 32px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}

.btn-upload:hover {
    background: var(--gradient-primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 201, 255, 0.3);
}
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-chart-line"></i> School Dashboard</h2>
    <?php include 'includes/nav-menu.php'; ?>
</div>

<div class="date-picker-container">
    <form method="GET" class="date-picker-form">
        <div class="date-input-group">
            <label for="start_date"><i class="fas fa-calendar"></i> From:</label>
            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" required>
        </div>
        <div class="date-input-group">
            <label for="end_date"><i class="fas fa-calendar"></i> To:</label>
            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" required>
        </div>
        <button type="submit" class="btn-filter">
            <i class="fas fa-filter"></i> Apply Filter
        </button>
        <a href="school_dashboard.php" class="btn-reset">
            <i class="fas fa-redo"></i> Reset (Last 30 Days)
        </a>
        <div class="date-range-display">
            Showing: <strong><?= date('M j, Y', strtotime($startDate)) ?></strong> to <strong><?= date('M j, Y', strtotime($endDate)) ?></strong>
            (<?= round($daysInRange) ?> days)
        </div>
    </form>
</div>

<div class="dashboard-container" x-data="{ }">

    <?php if ($combined['total_active'] === 0): ?>
        <div class="empty-state">
            <i class="fas fa-database"></i>
            <h3>No Data Available</h3>
            <p>Upload ZenPlanner data to see your school statistics here.</p>
            <a href="upload_zenplanner.php" class="btn-upload">
                <i class="fas fa-upload"></i> Upload Data
            </a>
        </div>
    <?php else: ?>

    <!-- EXECUTIVE SUMMARY -->
    <div class="executive-summary">
        <h3>
            <i class="fas fa-tachometer-alt"></i> Executive Summary (Both Locations)
            <span class="data-freshness-badge">
                <i class="fas fa-calendar-check"></i> Data through <?= date('M j, Y', strtotime($latestDataDate)) ?>
            </span>
        </h3>
        <div class="summary-grid">

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Total Active Members
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>Total Active Members</strong>
                        All members with active status from ZenPlanner. This includes members currently on hold.
                        <br><strong>Note:</strong> ZenPlanner exports include holds in the member count
                    </div>
                </div>
                <div class="summary-value"><?= number_format($combined['total_active']) ?></div>
                <div class="summary-subtext">
                    <?= number_format($combined['total_on_hold']) ?> currently on hold
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Total Revenue
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>Total Revenue</strong>
                        Combined revenue from both locations in the selected period.
                        <br><strong>Growth Target:</strong> 9.9% annually (industry median)
                    </div>
                </div>
                <div class="summary-value">$<?= number_format($combined['total_revenue'], 0) ?></div>
                <div class="summary-subtext">
                    <?= round($daysInRange) ?>-day period
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Avg Revenue Per Member (ARM)
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>ARM - Your North Star Metric</strong>
                        Average revenue generated per active member. Higher is better!
                        <br><strong>Industry Benchmark:</strong> $144-150/month for martial arts
                    </div>
                </div>
                <div class="summary-value">$<?= number_format($combined['avg_arm'], 0) ?></div>
                <?php
                $armBenchmark = $combined['avg_arm'];
                if ($armBenchmark >= 150) {
                    $armClass = 'benchmark-excellent';
                    $armText = 'Excellent!';
                } elseif ($armBenchmark >= 140) {
                    $armClass = 'benchmark-good';
                    $armText = 'Good';
                } elseif ($armBenchmark >= 120) {
                    $armClass = 'benchmark-average';
                    $armText = 'Average';
                } else {
                    $armClass = 'benchmark-below';
                    $armText = 'Below Target';
                }
                ?>
                <span class="benchmark-indicator <?= $armClass ?>"><?= $armText ?></span>
            </div>

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Member Retention Rate
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>Retention Rate</strong>
                        Percentage of members staying active (100% - churn rate). Retention is 5x cheaper than acquisition!
                        <br><strong>Industry Benchmark:</strong> 75-85% (Excellent: 90%+)
                    </div>
                </div>
                <div class="summary-value"><?= number_format($combined['avg_retention'], 1) ?>%</div>
                <?php
                $retBench = $combined['avg_retention'];
                if ($retBench >= 90) {
                    $retClass = 'benchmark-excellent';
                    $retText = 'Excellent!';
                } elseif ($retBench >= 80) {
                    $retClass = 'benchmark-good';
                    $retText = 'Good';
                } elseif ($retBench >= 70) {
                    $retClass = 'benchmark-average';
                    $retText = 'Average';
                } else {
                    $retClass = 'benchmark-below';
                    $retText = 'Needs Work';
                }
                ?>
                <span class="benchmark-indicator <?= $retClass ?>"><?= $retText ?></span>
            </div>

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Customer Lifetime Value (LTV)
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>Lifetime Value</strong>
                        Average total revenue per member over their entire membership.
                        <br><strong>Industry Benchmark:</strong> $2,000-5,000
                    </div>
                </div>
                <div class="summary-value">$<?= number_format($combined['avg_ltv'], 0) ?></div>
                <div class="summary-subtext">
                    Avg tenure: <?= number_format($davenportStats['avg_tenure_months'], 1) ?>mo
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label" x-data="{ show: false }">
                    Net Member Growth
                    <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                    <div x-show="show" x-transition class="info-tooltip" x-cloak>
                        <strong>Net Growth</strong>
                        New members minus cancellations. Positive = growing, negative = shrinking.
                        <br><strong>Industry Benchmark:</strong> 5.5% annual growth
                    </div>
                </div>
                <div class="summary-value <?= $combined['total_new_members'] - $combined['total_cancellations'] >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                    <?= $combined['total_new_members'] - $combined['total_cancellations'] > 0 ? '+' : '' ?><?= $combined['total_new_members'] - $combined['total_cancellations'] ?>
                </div>
                <div class="summary-subtext">
                    <?= $combined['total_new_members'] ?> new / <?= $combined['total_cancellations'] ?> cancelled
                </div>
            </div>

        </div>
    </div>

    <!-- LOCATION COMPARISON -->
    <div class="location-grid">

        <!-- DAVENPORT -->
        <div class="location-section">
            <div class="location-header">
                <i class="fas fa-school"></i>
                Davenport
            </div>
            <div class="location-body">

                <!-- Financial Metrics -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-dollar-sign"></i> Financial Metrics</h4>
                    </div>
                    <div class="metrics-grid">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Revenue
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Revenue</strong>
                                    Total revenue in selected period vs previous period of same length
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($davenportStats['revenue_period'], 0) ?></div>
                            <div class="metric-subtext <?= $davenportStats['revenue_change'] >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                                <i class="fas fa-<?= $davenportStats['revenue_change'] >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                <?= number_format(abs($davenportStats['revenue_change']), 1) ?>% vs prev
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                ARM (Per Member)
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Revenue Per Member</strong>
                                    Revenue ÷ Active Members. Track this monthly!
                                    <br><strong>Target:</strong> $144-150/mo
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($davenportStats['arm'], 0) ?></div>
                            <div class="metric-subtext">per member in period</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Lifetime Value
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Customer LTV</strong>
                                    Average total revenue generated per member across their lifetime
                                    <br><strong>Target:</strong> $2,000-5,000
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($davenportStats['ltv'], 0) ?></div>
                            <div class="metric-subtext"><?= number_format($davenportStats['avg_tenure_months'], 1) ?>mo avg tenure</div>
                        </div>
                    </div>
                </div>

                <!-- Member Health -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-heartbeat"></i> Member Health</h4>
                    </div>
                    <div class="metrics-grid-4col">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Retention Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Retention Rate</strong>
                                    % of members staying (100% - churn). A 5% increase can boost profits 25-95%!
                                    <br><strong>Target:</strong> 80-85%+
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['retention_rate'], 1) ?>%</div>
                            <div class="metric-subtext">
                                <?php
                                if ($davenportStats['retention_rate'] >= 85) echo '<span class="trend-positive">Excellent!</span>';
                                elseif ($davenportStats['retention_rate'] >= 75) echo '<span class="trend-positive">Good</span>';
                                else echo '<span class="trend-negative">Needs work</span>';
                                ?>
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Churn Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Churn Rate</strong>
                                    % of members who cancelled. Lower is better!
                                    <br><strong>Industry:</strong> 20-30% annual churn is typical
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['churn_rate'], 1) ?>%</div>
                            <div class="metric-subtext"><?= $davenportStats['cancellations_period'] ?> cancellations</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Net Growth
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Net Member Growth</strong>
                                    New members minus cancellations
                                    <br><strong>Target:</strong> Positive growth (5.5% annually)
                                </div>
                            </div>
                            <div class="metric-value <?= $davenportStats['net_growth'] >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                                <?= $davenportStats['net_growth'] > 0 ? '+' : '' ?><?= $davenportStats['net_growth'] ?>
                            </div>
                            <div class="metric-subtext"><?= $davenportStats['new_members_period'] ?> new members</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Avg Member Tenure
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Member Tenure</strong>
                                    How long members stay with you on average. Longer tenure = higher LTV and better retention!
                                    <br><strong>Industry:</strong> 12-18 months average
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['avg_tenure_months'], 1) ?></div>
                            <div class="metric-subtext">months average</div>
                        </div>
                    </div>

                    <div class="metrics-grid-4col">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Total Members
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Total Members</strong>
                                    Total enrolled members including both active and those on hold.
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['active_members'] + $davenportStats['on_hold']) ?></div>
                            <div class="metric-subtext">
                                <?= number_format($davenportStats['active_members']) ?> active + <?= $davenportStats['on_hold'] ?> on hold
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Total Active Members
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Total Active Members</strong>
                                    All members with active status, including those on hold. This is your total enrolled member count.
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['active_members']) ?></div>
                            <div class="metric-subtext">
                                <?= $davenportStats['on_hold'] ?> currently on hold
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Members on Hold
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Members on Hold</strong>
                                    Members with active holds (current date falls between hold start and end dates). These don't count toward your training member count.
                                    <br><strong>Watch if:</strong> >10% of total members
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['on_hold']) ?></div>
                            <div class="metric-subtext">
                                <?= number_format($davenportStats['hold_rate'], 1) ?>% of total members
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Cancellations
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Cancellations</strong>
                                    Total membership plans cancelled vs unique members who left. If one member cancels multiple plans, both numbers help understand the impact.
                                    <br><strong>Plans:</strong> Revenue impact
                                    <br><strong>Members:</strong> People churn
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['cancellations_period']) ?></div>
                            <div class="metric-subtext">
                                <?= $davenportStats['cancellations_period'] ?> plans / <?= $davenportStats['unique_members_cancelled'] ?> members
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hold Analysis -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-pause-circle"></i> Hold Analysis</h4>
                    </div>
                    <div class="metrics-grid">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Hold Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Hold Rate</strong>
                                    % of members currently on hold. Holds are a retention tool but also a churn risk indicator.
                                    <br><strong>Watch out if:</strong> >10%
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['hold_rate'], 1) ?>%</div>
                            <div class="metric-subtext"><?= $davenportStats['on_hold'] ?> members on hold</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Avg Hold Duration
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Hold Duration</strong>
                                    How long members typically stay on hold. Shorter is better - means they're coming back!
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['avg_hold_duration'], 0) ?></div>
                            <div class="metric-subtext">days average</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Hold-to-Cancel %
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Hold-to-Cancel Ratio</strong>
                                    % of members who eventually cancelled after placing a hold. Lower is better!
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($davenportStats['hold_to_cancel_ratio'], 1) ?>%</div>
                            <div class="metric-subtext">
                                <?php
                                if ($davenportStats['hold_to_cancel_ratio'] < 20) echo '<span class="trend-positive">Great!</span>';
                                elseif ($davenportStats['hold_to_cancel_ratio'] < 40) echo 'Fair';
                                else echo '<span class="trend-negative">High risk</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancellation Timing -->
                <?php if (!empty($davenportStats['cancellation_timing'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-clock"></i> When Do Members Cancel?</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cancellation Timing</strong>
                                Shows when members typically cancel after joining. Industry data: 50% of churn happens in first 3 months!
                                <br><strong>Action:</strong> Focus retention efforts on new members
                            </div>
                        </div>
                    </div>
                    <div class="simple-chart">
                        <?php
                        $maxCount = max(array_column($davenportStats['cancellation_timing'], 'count'));
                        foreach ($davenportStats['cancellation_timing'] as $timing):
                            $percentage = $maxCount > 0 ? ($timing['count'] / $maxCount) * 100 : 0;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-label"><?= $timing['tenure_bracket'] ?></div>
                                <div class="chart-bar-container">
                                    <div class="chart-bar-fill" style="width: <?= $percentage ?>%"></div>
                                </div>
                                <div class="chart-value"><?= $timing['count'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cohort Retention -->
                <?php if (!empty($davenportStats['cohort_retention'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-users-cog"></i> Cohort Retention (Last 6 Months)</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cohort Retention</strong>
                                Tracks how well different join months are retaining. Helps identify which periods bring best long-term members.
                                <br><strong>Use this to:</strong> Optimize timing of marketing campaigns
                            </div>
                        </div>
                    </div>
                    <table class="cohort-table">
                        <thead>
                            <tr>
                                <th>Join Month</th>
                                <th>Cohort Size</th>
                                <th>Still Active</th>
                                <th>Retention</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($davenportStats['cohort_retention'] as $cohort): ?>
                                <tr>
                                    <td><?= $cohort['month'] ?></td>
                                    <td><?= $cohort['cohort_size'] ?></td>
                                    <td><?= $cohort['still_active'] ?></td>
                                    <td>
                                        <?php
                                        $retRate = $cohort['retention_rate'];
                                        if ($retRate >= 85) $retClass = 'retention-excellent';
                                        elseif ($retRate >= 75) $retClass = 'retention-good';
                                        elseif ($retRate >= 60) $retClass = 'retention-average';
                                        else $retClass = 'retention-poor';
                                        ?>
                                        <span class="retention-badge <?= $retClass ?>">
                                            <?= number_format($cohort['retention_rate'], 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Top Cancellation Reasons -->
                <?php if (!empty($davenportStats['top_cancellation_reasons'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-list-ul"></i> Top Cancellation Reasons</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cancellation Reasons</strong>
                                Understanding why members leave helps you prevent future cancellations. Financial and schedule reasons are often preventable!
                            </div>
                        </div>
                    </div>
                    <ul class="reason-list">
                        <?php foreach ($davenportStats['top_cancellation_reasons'] as $reason): ?>
                            <li class="reason-item">
                                <span class="reason-text"><?= htmlspecialchars($reason['reason'] ?: 'Not specified') ?></span>
                                <span class="reason-count"><?= $reason['count'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Hold Reasons -->
                <?php if (!empty($davenportStats['hold_reasons'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-pause-circle"></i> Why Members Go On Hold</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Hold Reasons</strong>
                                Understanding why members pause helps you address concerns and bring them back faster!
                            </div>
                        </div>
                    </div>
                    <div class="simple-chart">
<?php
$maxCount = max(array_column($davenportStats['hold_reasons'], 'count'));
foreach ($davenportStats['hold_reasons'] as $holdReason) {
    $percentage = $maxCount > 0 ? ($holdReason['count'] / $maxCount) * 100 : 0;
    echo '                        <div class="chart-bar">' . "\n";
    echo '                            <div class="chart-label">' . htmlspecialchars($holdReason['reason']) . '</div>' . "\n";
    echo '                            <div class="chart-bar-container">' . "\n";
    echo '                                <div class="chart-bar-fill" style="width: ' . $percentage . '%"></div>' . "\n";
    echo '                            </div>' . "\n";
    echo '                            <div class="chart-value">' . $holdReason['count'] . '</div>' . "\n";
    echo '                        </div>' . "\n";
}
?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- CELEBRATION -->
        <div class="location-section">
            <div class="location-header celebration">
                <i class="fas fa-school"></i>
                Celebration
            </div>
            <div class="location-body">

                <!-- Financial Metrics -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-dollar-sign"></i> Financial Metrics</h4>
                    </div>
                    <div class="metrics-grid">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Revenue
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Revenue</strong>
                                    Total revenue in selected period vs previous period of same length
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($celebrationStats['revenue_period'], 0) ?></div>
                            <div class="metric-subtext <?= $celebrationStats['revenue_change'] >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                                <i class="fas fa-<?= $celebrationStats['revenue_change'] >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                                <?= number_format(abs($celebrationStats['revenue_change']), 1) ?>% vs prev
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                ARM (Per Member)
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Revenue Per Member</strong>
                                    Revenue ÷ Active Members. Track this monthly!
                                    <br><strong>Target:</strong> $144-150/mo
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($celebrationStats['arm'], 0) ?></div>
                            <div class="metric-subtext">per member in period</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Lifetime Value
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Customer LTV</strong>
                                    Average total revenue generated per member across their lifetime
                                    <br><strong>Target:</strong> $2,000-5,000
                                </div>
                            </div>
                            <div class="metric-value">$<?= number_format($celebrationStats['ltv'], 0) ?></div>
                            <div class="metric-subtext"><?= number_format($celebrationStats['avg_tenure_months'], 1) ?>mo avg tenure</div>
                        </div>
                    </div>
                </div>

                <!-- Member Health -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-heartbeat"></i> Member Health</h4>
                    </div>
                    <div class="metrics-grid-4col">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Retention Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Retention Rate</strong>
                                    % of members staying (100% - churn). A 5% increase can boost profits 25-95%!
                                    <br><strong>Target:</strong> 80-85%+
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['retention_rate'], 1) ?>%</div>
                            <div class="metric-subtext">
                                <?php
                                if ($celebrationStats['retention_rate'] >= 85) echo '<span class="trend-positive">Excellent!</span>';
                                elseif ($celebrationStats['retention_rate'] >= 75) echo '<span class="trend-positive">Good</span>';
                                else echo '<span class="trend-negative">Needs work</span>';
                                ?>
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Churn Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Churn Rate</strong>
                                    % of members who cancelled. Lower is better!
                                    <br><strong>Industry:</strong> 20-30% annual churn is typical
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['churn_rate'], 1) ?>%</div>
                            <div class="metric-subtext"><?= $celebrationStats['cancellations_period'] ?> cancellations</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Net Growth
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Net Member Growth</strong>
                                    New members minus cancellations
                                    <br><strong>Target:</strong> Positive growth (5.5% annually)
                                </div>
                            </div>
                            <div class="metric-value <?= $celebrationStats['net_growth'] >= 0 ? 'trend-positive' : 'trend-negative' ?>">
                                <?= $celebrationStats['net_growth'] > 0 ? '+' : '' ?><?= $celebrationStats['net_growth'] ?>
                            </div>
                            <div class="metric-subtext"><?= $celebrationStats['new_members_period'] ?> new members</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Avg Member Tenure
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Member Tenure</strong>
                                    How long members stay with you on average. Longer tenure = higher LTV and better retention!
                                    <br><strong>Industry:</strong> 12-18 months average
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['avg_tenure_months'], 1) ?></div>
                            <div class="metric-subtext">months average</div>
                        </div>
                    </div>

                    <div class="metrics-grid-4col">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Total Members
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Total Members</strong>
                                    Total enrolled members including both active and those on hold.
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['active_members'] + $celebrationStats['on_hold']) ?></div>
                            <div class="metric-subtext">
                                <?= number_format($celebrationStats['active_members']) ?> active + <?= $celebrationStats['on_hold'] ?> on hold
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Total Active Members
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Total Active Members</strong>
                                    All members with active status, including those on hold. This is your total enrolled member count.
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['active_members']) ?></div>
                            <div class="metric-subtext">
                                <?= $celebrationStats['on_hold'] ?> currently on hold
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Members on Hold
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Members on Hold</strong>
                                    Members with active holds (current date falls between hold start and end dates). These don't count toward your training member count.
                                    <br><strong>Watch if:</strong> >10% of total members
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['on_hold']) ?></div>
                            <div class="metric-subtext">
                                <?= number_format($celebrationStats['hold_rate'], 1) ?>% of total members
                            </div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Cancellations
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Cancellations</strong>
                                    Total membership plans cancelled vs unique members who left. If one member cancels multiple plans, both numbers help understand the impact.
                                    <br><strong>Plans:</strong> Revenue impact
                                    <br><strong>Members:</strong> People churn
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['cancellations_period']) ?></div>
                            <div class="metric-subtext">
                                <?= $celebrationStats['cancellations_period'] ?> plans / <?= $celebrationStats['unique_members_cancelled'] ?> members
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hold Analysis -->
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-pause-circle"></i> Hold Analysis</h4>
                    </div>
                    <div class="metrics-grid">
                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Hold Rate
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Hold Rate</strong>
                                    % of members currently on hold. Holds are a retention tool but also a churn risk indicator.
                                    <br><strong>Watch out if:</strong> >10%
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['hold_rate'], 1) ?>%</div>
                            <div class="metric-subtext"><?= $celebrationStats['on_hold'] ?> members on hold</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Avg Hold Duration
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Average Hold Duration</strong>
                                    How long members typically stay on hold. Shorter is better - means they're coming back!
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['avg_hold_duration'], 0) ?></div>
                            <div class="metric-subtext">days average</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-label" x-data="{ show: false }">
                                Hold-to-Cancel %
                                <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                                <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                    <strong>Hold-to-Cancel Ratio</strong>
                                    % of members who eventually cancelled after placing a hold. Lower is better!
                                </div>
                            </div>
                            <div class="metric-value"><?= number_format($celebrationStats['hold_to_cancel_ratio'], 1) ?>%</div>
                            <div class="metric-subtext">
                                <?php
                                if ($celebrationStats['hold_to_cancel_ratio'] < 20) echo '<span class="trend-positive">Great!</span>';
                                elseif ($celebrationStats['hold_to_cancel_ratio'] < 40) echo 'Fair';
                                else echo '<span class="trend-negative">High risk</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancellation Timing -->
                <?php if (!empty($celebrationStats['cancellation_timing'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-clock"></i> When Do Members Cancel?</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cancellation Timing</strong>
                                Shows when members typically cancel after joining. Industry data: 50% of churn happens in first 3 months!
                                <br><strong>Action:</strong> Focus retention efforts on new members
                            </div>
                        </div>
                    </div>
                    <div class="simple-chart">
                        <?php
                        $maxCount = max(array_column($celebrationStats['cancellation_timing'], 'count'));
                        foreach ($celebrationStats['cancellation_timing'] as $timing):
                            $percentage = $maxCount > 0 ? ($timing['count'] / $maxCount) * 100 : 0;
                        ?>
                            <div class="chart-bar">
                                <div class="chart-label"><?= $timing['tenure_bracket'] ?></div>
                                <div class="chart-bar-container">
                                    <div class="chart-bar-fill" style="width: <?= $percentage ?>%"></div>
                                </div>
                                <div class="chart-value"><?= $timing['count'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cohort Retention -->
                <?php if (!empty($celebrationStats['cohort_retention'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-users-cog"></i> Cohort Retention (Last 6 Months)</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cohort Retention</strong>
                                Tracks how well different join months are retaining. Helps identify which periods bring best long-term members.
                                <br><strong>Use this to:</strong> Optimize timing of marketing campaigns
                            </div>
                        </div>
                    </div>
                    <table class="cohort-table">
                        <thead>
                            <tr>
                                <th>Join Month</th>
                                <th>Cohort Size</th>
                                <th>Still Active</th>
                                <th>Retention</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($celebrationStats['cohort_retention'] as $cohort): ?>
                                <tr>
                                    <td><?= $cohort['month'] ?></td>
                                    <td><?= $cohort['cohort_size'] ?></td>
                                    <td><?= $cohort['still_active'] ?></td>
                                    <td>
                                        <?php
                                        $retRate = $cohort['retention_rate'];
                                        if ($retRate >= 85) $retClass = 'retention-excellent';
                                        elseif ($retRate >= 75) $retClass = 'retention-good';
                                        elseif ($retRate >= 60) $retClass = 'retention-average';
                                        else $retClass = 'retention-poor';
                                        ?>
                                        <span class="retention-badge <?= $retClass ?>">
                                            <?= number_format($cohort['retention_rate'], 1) ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Top Cancellation Reasons -->
                <?php if (!empty($celebrationStats['top_cancellation_reasons'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-list-ul"></i> Top Cancellation Reasons</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Cancellation Reasons</strong>
                                Understanding why members leave helps you prevent future cancellations. Financial and schedule reasons are often preventable!
                            </div>
                        </div>
                    </div>
                    <ul class="reason-list">
                        <?php foreach ($celebrationStats['top_cancellation_reasons'] as $reason): ?>
                            <li class="reason-item">
                                <span class="reason-text"><?= htmlspecialchars($reason['reason'] ?: 'Not specified') ?></span>
                                <span class="reason-count"><?= $reason['count'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Hold Reasons -->
                <?php if (!empty($celebrationStats['hold_reasons'])): ?>
                <div class="subsection">
                    <div class="section-header">
                        <h4><i class="fas fa-pause-circle"></i> Why Members Go On Hold</h4>
                        <div style="position: relative; display: inline-block;" x-data="{ show: false }">
                            <i class="fas fa-info-circle info-icon" @mouseenter="show = true" @mouseleave="show = false"></i>
                            <div x-show="show" x-transition class="info-tooltip" x-cloak>
                                <strong>Hold Reasons</strong>
                                Understanding why members pause helps you address concerns and bring them back faster!
                            </div>
                        </div>
                    </div>
                    <div class="simple-chart">
<?php
$maxCount = max(array_column($celebrationStats['hold_reasons'], 'count'));
foreach ($celebrationStats['hold_reasons'] as $holdReason) {
    $percentage = $maxCount > 0 ? ($holdReason['count'] / $maxCount) * 100 : 0;
    echo '                        <div class="chart-bar">' . "\n";
    echo '                            <div class="chart-label">' . htmlspecialchars($holdReason['reason']) . '</div>' . "\n";
    echo '                            <div class="chart-bar-container">' . "\n";
    echo '                                <div class="chart-bar-fill" style="width: ' . $percentage . '%"></div>' . "\n";
    echo '                            </div>' . "\n";
    echo '                            <div class="chart-value">' . $holdReason['count'] . '</div>' . "\n";
    echo '                        </div>' . "\n";
}
?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>

    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
