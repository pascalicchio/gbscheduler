<?php
// coach_payments.php - PAYMENT TRACKING SYSTEM
require_once 'includes/config.php';

// Require admin access
requireAuth(['admin']);

// Get current tab
$tab = $_GET['tab'] ?? 'weekly';
$valid_tabs = ['weekly', 'biweekly', 'monthly', 'history'];
if (!in_array($tab, $valid_tabs)) $tab = 'weekly';

// Calculate ALL period dates ONCE (used for both page defaults and badge counting)
$today = new DateTime();

// Weekly period (Sunday to Saturday)
$day_of_week = (int)$today->format('w');
$weekly_period_start = (clone $today)->modify("-{$day_of_week} days")->format('Y-m-d');
$weekly_period_end = (clone $today)->modify("-{$day_of_week} days")->modify('+6 days')->format('Y-m-d');

// Biweekly period
$reference = new DateTime('2024-01-07'); // A known Sunday
$diff = $today->diff($reference)->days;
$weeks_since = floor($diff / 14) * 14;
$biweekly_start_obj = (clone $reference)->modify("+{$weeks_since} days");
if ($biweekly_start_obj > $today) {
    $biweekly_start_obj->modify('-14 days');
}
$biweekly_period_start = $biweekly_start_obj->format('Y-m-d');
$biweekly_period_end = (clone $biweekly_start_obj)->modify('+13 days')->format('Y-m-d');

// Monthly period
$monthly_period_start = $today->format('Y-m-01');
$monthly_period_end = $today->format('Y-m-t');

// Set defaults based on current tab
if ($tab === 'weekly') {
    $default_start = $weekly_period_start;
    $default_end = $weekly_period_end;
} elseif ($tab === 'biweekly') {
    $default_start = $biweekly_period_start;
    $default_end = $biweekly_period_end;
} elseif ($tab === 'monthly') {
    $default_start = $monthly_period_start;
    $default_end = $monthly_period_end;
} else {
    // History - last 3 months
    $default_start = (clone $today)->modify('-3 months')->format('Y-m-01');
    $default_end = $today->format('Y-m-d');
}

$start_date = $_GET['start'] ?? $default_start;
$end_date = $_GET['end'] ?? $default_end;
$filter_coach_id = $_GET['coach_id'] ?? '';
$filter_location_id = $_GET['location_id'] ?? '';

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'mark_paid') {
        $user_id = $_POST['user_id'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'] ?? 'manual';
        $notes = $_POST['notes'] ?? '';
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

        $pay_location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;
        $stmt = $pdo->prepare("INSERT INTO coach_payments (user_id, location_id, period_start, period_end, amount, payment_date, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $pay_location_id, $period_start, $period_end, $amount, $payment_date, $payment_method, $notes, getUserId()]);

        setFlash("Payment recorded successfully!", 'success');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_payment') {
        $payment_id = $_POST['payment_id'];
        $pdo->prepare("DELETE FROM coach_payments WHERE id = ?")->execute([$payment_id]);
        setFlash("Payment deleted.", 'error');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_all_payments') {
        $user_id = $_POST['user_id'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $del_location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;
        if ($del_location_id) {
            $stmt = $pdo->prepare("DELETE FROM coach_payments WHERE user_id = ? AND period_start = ? AND period_end = ? AND location_id = ?");
            $stmt->execute([$user_id, $period_start, $period_end, $del_location_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM coach_payments WHERE user_id = ? AND period_start = ? AND period_end = ?");
            $stmt->execute([$user_id, $period_start, $period_end]);
        }
        setFlash("All payments deleted for this period.", 'error');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_conversions') {
        $user_id = $_POST['user_id'];
        $period_month = $_POST['period_month'];
        $conversions = $_POST['conversions'] ?? 0;
        $notes = $_POST['notes'] ?? '';

        // Upsert conversions
        $check = $pdo->prepare("SELECT id FROM user_conversions WHERE user_id = ? AND period_month = ?");
        $check->execute([$user_id, $period_month]);

        if ($check->fetch()) {
            $stmt = $pdo->prepare("UPDATE user_conversions SET conversions = ?, notes = ?, created_by = ? WHERE user_id = ? AND period_month = ?");
            $stmt->execute([$conversions, $notes, getUserId(), $user_id, $period_month]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO user_conversions (user_id, period_month, conversions, notes, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $period_month, $conversions, $notes, getUserId()]);
        }

        setFlash("Conversions updated successfully!", 'success');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update_deduction') {
        $user_id = $_POST['user_id'];
        $period_month = $_POST['period_month'];
        $amount = $_POST['amount'] ?? 0;
        $reason = $_POST['reason'] ?? '';

        // Upsert deduction
        $check = $pdo->prepare("SELECT id FROM user_deductions WHERE user_id = ? AND period_month = ?");
        $check->execute([$user_id, $period_month]);

        if ($check->fetch()) {
            $stmt = $pdo->prepare("UPDATE user_deductions SET amount = ?, reason = ?, created_by = ? WHERE user_id = ? AND period_month = ?");
            $stmt->execute([$amount, $reason, getUserId(), $user_id, $period_month]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO user_deductions (user_id, period_month, amount, reason, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $period_month, $amount, $reason, getUserId()]);
        }

        setFlash("Deduction updated successfully!", 'success');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

$msg = getFlash();

// Get coaches filtered by payment frequency (for non-history tabs)
$frequency_filter = '';
if ($tab !== 'history') {
    $frequency_filter = $tab;
}

// Fetch users (filtered by location and/or frequency)
$coaches_sql = "SELECT * FROM users WHERE 1=1";
$coaches_params = [];
if ($frequency_filter) {
    $coaches_sql .= " AND payment_frequency = :freq";
    $coaches_params['freq'] = $frequency_filter;
}
if ($filter_location_id) {
    $coaches_sql .= " AND id IN (SELECT user_id FROM user_locations WHERE location_id = :loc)";
    $coaches_params['loc'] = $filter_location_id;
}
$coaches_sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($coaches_sql);
$stmt->execute($coaches_params);
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For history tab, get all users for filter dropdown
$all_coaches = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get counts of unpaid users for badge counters on tabs
// Uses the same period dates calculated at the top of the file
$unpaid_counts = [
    'weekly' => 0,
    'biweekly' => 0,
    'monthly' => 0
];

// Count unpaid users for each frequency (filtered by location if selected)
foreach ([
    'weekly' => [$weekly_period_start, $weekly_period_end],
    'biweekly' => [$biweekly_period_start, $biweekly_period_end],
    'monthly' => [$monthly_period_start, $monthly_period_end]
] as $freq => $dates) {
    list($period_start, $period_end) = $dates;

    // Get users with this frequency (optionally filtered by location)
    $freq_sql = "SELECT id FROM users WHERE payment_frequency = ?";
    $freq_params = [$freq];
    if ($filter_location_id) {
        $freq_sql .= " AND id IN (SELECT user_id FROM user_locations WHERE location_id = ?)";
        $freq_params[] = $filter_location_id;
    }
    $freq_users = $pdo->prepare($freq_sql);
    $freq_users->execute($freq_params);
    $users_with_freq = $freq_users->fetchAll(PDO::FETCH_COLUMN);

    foreach ($users_with_freq as $user_id) {
        // Check if they have been paid for this period
        $paid_check = $pdo->prepare("SELECT id FROM coach_payments WHERE user_id = ? AND period_start = ? AND period_end = ?");
        $paid_check->execute([$user_id, $period_start, $period_end]);

        // If not paid, increment counter
        if (!$paid_check->fetch()) {
            $unpaid_counts[$freq]++;
        }
    }
}

// Calculate earnings for each coach (for payment tabs)
$coach_data = [];
if ($tab !== 'history') {
    foreach ($coaches as $c) {
        if ($filter_coach_id && $c['id'] != $filter_coach_id) continue;

        $coach_data[$c['id']] = [
            'info' => $c,
            'regular_pay' => 0,
            'private_pay' => 0,
            'fixed_salary' => 0,
            'commission_pay' => 0,
            'conversions' => 0,
            'deduction' => 0,
            'deduction_reason' => '',
            'total_pay' => 0,
            'class_count' => 0
        ];
    }

    // Regular Classes
    $sql_reg = "
        SELECT ea.user_id, ea.position,
               TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
        FROM event_assignments ea
        JOIN class_templates ct ON ea.template_id = ct.id
        WHERE ea.class_date BETWEEN :start AND :end
        AND DAYNAME(ea.class_date) = ct.day_of_week
    ";
    $reg_params = ['start' => $start_date, 'end' => $end_date];
    if ($filter_location_id) {
        $sql_reg .= " AND ct.location_id = :loc";
        $reg_params['loc'] = $filter_location_id;
    }
    $stmt = $pdo->prepare($sql_reg);
    $stmt->execute($reg_params);
    $reg_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reg_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $hours = (float)$row['hours'];
        if ($hours < 1) $hours = 1.0;

        $head_rate = $coach_data[$uid]['info']['rate_head_coach'] ?? 0;
        $helper_rate = $coach_data[$uid]['info']['rate_helper'] ?? 0;
        $rate = ($row['position'] === 'head') ? $head_rate : $helper_rate;
        $pay = $hours * $rate;

        $coach_data[$uid]['regular_pay'] += $pay;
        $coach_data[$uid]['total_pay'] += $pay;
        $coach_data[$uid]['class_count']++;
    }

    // Private Classes
    $sql_priv = "
        SELECT pc.user_id, pc.payout
        FROM private_classes pc
        WHERE pc.class_date BETWEEN :start AND :end
    ";
    $priv_params = ['start' => $start_date, 'end' => $end_date];
    if ($filter_location_id) {
        $sql_priv .= " AND pc.location_id = :loc";
        $priv_params['loc'] = $filter_location_id;
    }
    $stmt = $pdo->prepare($sql_priv);
    $stmt->execute($priv_params);
    $priv_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($priv_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $coach_data[$uid]['private_pay'] += $row['payout'];
        $coach_data[$uid]['total_pay'] += $row['payout'];
        $coach_data[$uid]['class_count']++;
    }

    // Fixed Salary & Commission (for monthly payments)
    // Get the month from the start_date for monthly salary and commission lookup
    $period_month = date('Y-m-01', strtotime($start_date));

    // Add fixed salary for users who have it (only if location matches or no location filter)
    foreach ($coach_data as $uid => $data) {
        $fixed_salary = (float)($coach_data[$uid]['info']['fixed_salary'] ?? 0);
        $salary_location = $coach_data[$uid]['info']['fixed_salary_location_id'] ?? null;

        if ($fixed_salary > 0 && $salary_location) {
            // Only include salary if: no location filter, OR location filter matches salary location
            $location_matches = !$filter_location_id || ((int)$salary_location === (int)$filter_location_id);

            if ($location_matches) {
                $coach_data[$uid]['fixed_salary'] = $fixed_salary;
                $coach_data[$uid]['total_pay'] += $fixed_salary;
            }
        }
    }

    // Add commission from conversions
    $conv_sql = "SELECT user_id, conversions FROM user_conversions WHERE period_month = ?";
    $stmt = $pdo->prepare($conv_sql);
    $stmt->execute([$period_month]);
    $conv_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($conv_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $conversions = $row['conversions'];
        $commission = 0;

        // Parse commission tiers - find which tier the conversions fall into
        $tiers_json = $coach_data[$uid]['info']['commission_tiers'] ?? null;
        if ($tiers_json && $conversions > 0) {
            $tiers = json_decode($tiers_json, true);
            if ($tiers && is_array($tiers)) {
                // Sort tiers by min value (descending to find highest matching tier first)
                usort($tiers, function($a, $b) {
                    return $b['min'] - $a['min'];
                });

                // Find which tier the conversion count falls into
                foreach ($tiers as $tier) {
                    $tier_min = $tier['min'];
                    $tier_max = $tier['max'] ?? 999999;
                    $tier_rate = $tier['rate'];

                    // If conversions >= tier minimum, use this tier's rate for ALL conversions
                    if ($conversions >= $tier_min) {
                        $commission = $conversions * $tier_rate;
                        break; // Stop at the first (highest) matching tier
                    }
                }
            }
        }

        $coach_data[$uid]['conversions'] = $conversions;
        $coach_data[$uid]['commission_pay'] = $commission;
        $coach_data[$uid]['total_pay'] += $commission;
    }

    // Apply deductions
    $ded_sql = "SELECT user_id, amount, reason FROM user_deductions WHERE period_month = ?";
    $stmt = $pdo->prepare($ded_sql);
    $stmt->execute([$period_month]);
    $ded_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ded_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $deduction = (float)$row['amount'];
        $coach_data[$uid]['deduction'] = $deduction;
        $coach_data[$uid]['deduction_reason'] = $row['reason'] ?? '';
        $coach_data[$uid]['total_pay'] -= $deduction;
    }

    // Check which coaches have been paid for the FILTERED period (use actual filter dates)
    // Now we get SUM of all payments for this period to support multiple/partial payments
    // When a location filter is active, only count payments recorded for that specific location
    // When "All Locations" is selected, count all payments (legacy NULL + per-location)
    $paid_sql = "
        SELECT user_id, 
               SUM(amount) as total_paid,
               MAX(payment_date) as last_payment_date,
               MAX(id) as last_payment_id,
               MAX(payment_method) as payment_method
        FROM coach_payments
        WHERE period_start = ? AND period_end = ?
    ";
    $paid_params = [$start_date, $end_date];
    if ($filter_location_id) {
        $paid_sql .= " AND location_id = ?";
        $paid_params[] = $filter_location_id;
    }
    $paid_sql .= " GROUP BY user_id";
    $paid_check = $pdo->prepare($paid_sql);
    $paid_check->execute($paid_params);
    $paid_records = [];
    foreach ($paid_check->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paid_records[$p['user_id']] = $p;
    }

    // Recalculate unpaid count for current tab based on coaches with actual earnings
    // This ensures badge matches the table (only counting coaches with total_pay > 0)
    // A coach is considered "unpaid" if they have a balance due (earned more than paid)
    $unpaid_counts[$tab] = 0;
    foreach ($coach_data as $uid => $data) {
        $total_paid = isset($paid_records[$uid]) ? $paid_records[$uid]['total_paid'] : 0;
        $balance_due = $data['total_pay'] - $total_paid;
        // Only count if they have a positive balance due
        if ($balance_due > 0.01) { // Use 0.01 to avoid floating point issues
            $unpaid_counts[$tab]++;
        }
    }
}

// For history tab, get payment records
$payment_history = [];
if ($tab === 'history') {
    $history_sql = "
        SELECT cp.*, u.name as coach_name
        FROM coach_payments cp
        JOIN users u ON cp.user_id = u.id
        WHERE cp.payment_date BETWEEN :start AND :end
    ";
    $params = ['start' => $start_date, 'end' => $end_date];

    if ($filter_coach_id) {
        $history_sql .= " AND cp.user_id = :coach";
        $params['coach'] = $filter_coach_id;
    }

    $history_sql .= " ORDER BY cp.payment_date DESC, cp.created_at DESC";
    $stmt = $pdo->prepare($history_sql);
    $stmt->execute($params);
    $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals (only for coaches in the current filtered view)
$total_owed = 0;
$total_paid = 0;
$total_balance = 0;
foreach ($coach_data as $uid => $cd) {
    $total_owed += $cd['total_pay'];
    $coach_paid = isset($paid_records[$uid]) ? $paid_records[$uid]['total_paid'] : 0;
    $total_paid += $coach_paid;
    $total_balance += ($cd['total_pay'] - $coach_paid);
}

// Page setup
$pageTitle = 'Coach Payments | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 16px; }

    [x-cloak] { display: none !important; }

    /* CSS Variables */
    :root {
        --gradient-dark: linear-gradient(135deg, #1a202c, #2d3748);
    }

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        gap: 12px;
    }

    .page-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
    }

    .page-header h2 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    @media (min-width: 768px) {
        body { padding: 20px; }
        .page-header h2 {
            font-size: 1.75rem;
        }
    }

    /* Tabs */
    .tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .tab-btn {
        padding: 10px 18px;
        border: 2px solid #e2e8f0;
        background: white;
        cursor: pointer;
        font-weight: 600;
        color: #6c757d;
        transition: all 0.25s ease;
        border-radius: 10px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        position: relative;
    }

    .tab-btn:hover {
        background: rgba(0, 201, 255, 0.05);
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
    }

    .tab-btn.active {
        color: white;
        background: var(--gradient-dark);
        border-color: transparent;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .tab-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        background: #dc3545;
        color: white;
        font-size: 0.65em;
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 10px;
        min-width: 18px;
        text-align: center;
    }

    /* Controls / Filters */
    .controls {
        background: white;
        padding: 14px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        border: 1px solid #e2e8f0;
        display: flex;
        gap: 12px;
        align-items: flex-end;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 120px;
    }

    .form-group label {
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 6px;
        color: #2c3e50;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .form-group input[type="text"],
    .form-group select {
        height: 40px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 0 12px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #2c3e50;
        transition: all 0.2s ease;
    }

    .form-group input[type="text"]:focus,
    .form-group select:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .btn-apply {
        height: 40px;
        padding: 0 20px;
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .btn-apply:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.3);
    }

    /* Summary Bar - Design System Dark Gradient */
    .summary-bar {
        background-image: var(--gradient-dark);
        color: white;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .summary-bar h3 {
        margin: 0 0 4px 0;
        font-size: 0.7rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.7);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .summary-bar .big-number {
        font-size: 1.3em;
        font-weight: 800;
        color: white;
    }

    /* Payment Table */
    .payment-table {
        width: 100%;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }

    .payment-table th {
        background: var(--gradient-dark);
        padding: 12px 10px;
        text-align: center;
        font-size: 0.75rem;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 700;
        letter-spacing: 0.05em;
    }

    .payment-table th:first-child {
        text-align: left;
    }

    .payment-table td {
        padding: 10px;
        border-bottom: 1px solid #f0f0f0;
        text-align: center;
        font-size: 0.85rem;
    }

    .payment-table td:first-child {
        text-align: left;
    }

    .payment-table tbody tr:hover {
        background-color: rgba(0, 201, 255, 0.04);
    }

    .text-danger { color: #dc3545; }
    .text-right { text-align: right; }
    .text-success { color: rgb(0, 201, 255); }
    .font-bold { font-weight: 700; }

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.75em;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-paid {
        background: #d4edda;
        color: #155724;
    }

    .status-partial {
        background: #fff3cd;
        color: #856404;
    }

    .status-unpaid {
        background: #f8d7da;
        color: #721c24;
    }

    /* Buttons */
    .btn-pay {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
        border: none;
        padding: 6px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8em;
        font-weight: 700;
        white-space: nowrap;
    }

    .btn-pay:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.3);
    }

    .btn-view {
        background: var(--gradient-dark);
        color: white;
        border: none;
        padding: 6px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8em;
        font-weight: 600;
        white-space: nowrap;
    }

    .btn-icon {
        background: none;
        border: none;
        cursor: pointer;
        padding: 2px;
    }

    /* Modal styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal {
        background: white;
        border-radius: 12px;
        width: 100%;
        max-width: 450px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        margin: 16px;
    }

    .modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5em;
        cursor: pointer;
        color: #999;
    }

    .modal-body {
        padding: 20px;
    }

    .modal-body .form-group {
        margin-bottom: 15px;
    }

    .modal-body input,
    .modal-body select,
    .modal-body textarea {
        width: 100%;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 8px 12px;
        font-size: 0.85rem;
    }

    .modal-body input:focus,
    .modal-body select:focus,
    .modal-body textarea:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .modal-footer .btn {
        padding: 8px 20px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        cursor: pointer;
        border: none;
    }

    .modal-footer .btn-primary {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
    }

    .modal-footer .btn-secondary {
        background: #e2e8f0;
        color: #2c3e50;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 3em;
        color: #cbd5e0;
        margin-bottom: 15px;
    }

    /* Flatpickr presets */
    .flatpickr-presets {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        padding: 8px;
        border-top: 1px solid #e6e6e6;
        background: #f5f5f5;
    }

    .flatpickr-presets button {
        flex: 1;
        min-width: 70px;
        padding: 6px 8px;
        border: 2px solid #e2e8f0;
        background: white;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.75em;
        font-weight: 600;
    }

    .flatpickr-presets button:hover {
        background: rgba(0, 201, 255, 0.05);
        border-color: rgba(0, 201, 255, 0.3);
    }

    /* ======================================== */
    /* Mobile Responsive */
    /* ======================================== */
    @media (max-width: 768px) {
        /* Flatpickr mobile input fix */
        .flatpickr-input.flatpickr-mobile {
            margin-bottom: 0 !important;
        }

        /* Tabs - compact */
        .tabs {
            gap: 4px;
        }

        .tab-btn {
            padding: 8px 10px;
            font-size: 0.75rem;
            gap: 4px;
            flex: 1;
            justify-content: center;
            text-align: center;
        }

        .tab-btn i {
            display: none;
        }

        /* Controls - 2 col grid */
        .controls {
            gap: 8px;
            padding: 12px;
        }

        .form-group {
            min-width: calc(50% - 4px);
            flex: 1 1 calc(50% - 4px);
        }

        .form-group label {
            font-size: 0.65rem;
            margin-bottom: 4px;
        }

        .form-group input[type="text"],
        .form-group select {
            height: 34px;
            font-size: 0.8rem;
            padding: 0 8px;
            margin-bottom: 0;
        }

        .btn-apply {
            display: none;
        }

        /* Summary bar - compact horizontal */
        .summary-bar {
            padding: 12px 14px;
            gap: 4px 12px;
            flex-wrap: wrap;
        }

        .summary-bar > div {
            flex: 0 0 auto;
        }

        .summary-bar h3 {
            font-size: 0.6rem;
            margin-bottom: 2px;
        }

        .summary-bar .big-number {
            font-size: 0.9em;
        }

        /* Table → card layout on mobile */
        .payment-table {
            border: none;
            box-shadow: none;
            background: transparent;
        }

        .payment-table thead {
            display: none;
        }

        .payment-table tbody {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .payment-table tbody tr {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .payment-table tbody tr:hover {
            background: white;
        }

        .payment-table td {
            border: none;
            padding: 2px 0;
            text-align: left;
            font-size: 0.8rem;
        }

        .payment-table td::before {
            content: attr(data-label);
            display: block;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #a0aec0;
            margin-bottom: 2px;
        }

        /* Coach name spans full width */
        .payment-table td:first-child {
            grid-column: 1 / -1;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 8px;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }

        /* Action spans full width */
        .payment-table td:last-child {
            grid-column: 1 / -1;
            border-top: 1px solid #f0f0f0;
            padding-top: 8px;
            margin-top: 4px;
            text-align: center;
        }

        .payment-table td:last-child .btn-pay,
        .payment-table td:last-child .btn-view {
            width: 100%;
            padding: 10px;
            font-size: 0.85rem;
        }

        /* Hide less important cols in card view */
        .col-hide-mobile {
            display: none;
        }
    }
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-money-check-alt"></i> Coach Payments</h2>
    <?php include 'includes/nav-menu.php'; ?>
</div>

<?= $msg ?>

<!-- Tabs -->
<?php $location_param = $filter_location_id ? '&location_id=' . urlencode($filter_location_id) : ''; ?>
<div class="tabs">
    <a href="?tab=weekly<?= $location_param ?>" class="tab-btn <?= $tab === 'weekly' ? 'active' : '' ?>">
        <i class="fas fa-calendar-week"></i> Weekly
        <?php if ($unpaid_counts['weekly'] > 0): ?>
            <span class="tab-badge"><?= $unpaid_counts['weekly'] ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=biweekly<?= $location_param ?>" class="tab-btn <?= $tab === 'biweekly' ? 'active' : '' ?>">
        <i class="fas fa-calendar-alt"></i> Biweekly
        <?php if ($unpaid_counts['biweekly'] > 0): ?>
            <span class="tab-badge"><?= $unpaid_counts['biweekly'] ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=monthly<?= $location_param ?>" class="tab-btn <?= $tab === 'monthly' ? 'active' : '' ?>">
        <i class="fas fa-calendar"></i> Monthly
        <?php if ($unpaid_counts['monthly'] > 0): ?>
            <span class="tab-badge"><?= $unpaid_counts['monthly'] ?></span>
        <?php endif; ?>
    </a>
    <a href="?tab=history<?= $location_param ?>" class="tab-btn <?= $tab === 'history' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> History
    </a>
</div>

<!-- Filters -->
<form method="GET" class="controls">
    <input type="hidden" name="tab" value="<?= $tab ?>">

    <div class="form-group">
        <label>Start Date</label>
        <input type="text" name="start" id="start_date" value="<?= $start_date ?>" readonly>
    </div>
    <div class="form-group">
        <label>End Date</label>
        <input type="text" name="end" id="end_date" value="<?= $end_date ?>" readonly>
    </div>
    <div class="form-group">
        <label>Location</label>
        <select name="location_id">
            <option value="">All Locations</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $filter_location_id == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Coach</label>
        <select name="coach_id">
            <option value="">All Coaches</option>
            <?php foreach ($all_coaches as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_coach_id == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-apply">Apply</button>
</form>

<?php if ($tab !== 'history'): ?>
    <!-- Summary Bar -->
    <div class="summary-bar">
        <div>
            <h3>Period</h3>
            <div class="big-number"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></div>
        </div>
        <div>
            <h3>Total Owed</h3>
            <div class="big-number">$<?= number_format($total_owed, 2) ?></div>
        </div>
        <div>
            <h3>Total Paid</h3>
            <div class="big-number" style="color: rgb(146, 254, 157);">$<?= number_format($total_paid, 2) ?></div>
        </div>
        <div>
            <h3>Remaining</h3>
            <div class="big-number" style="color: <?= ($total_owed - $total_paid) > 0 ? '#ffc107' : 'rgb(146, 254, 157)' ?>;">
                $<?= number_format($total_owed - $total_paid, 2) ?>
            </div>
        </div>
    </div>

    <!-- Payment Table -->
    <?php if (empty($coach_data)): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>No coaches found</h3>
            <p>No coaches are set to <?= $tab ?> payment frequency.</p>
        </div>
    <?php else: ?>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Coach</th>
                    <th>Classes</th>
                    <th>Regular</th>
                    <th>Private</th>
                    <th>Salary</th>
                    <th>Commission</th>
                    <th>Deduction</th>
                    <th>Total Owed</th>
                    <th>Total Paid</th>
                    <th>Balance Due</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coach_data as $uid => $data):
                    $total_paid = isset($paid_records[$uid]) ? $paid_records[$uid]['total_paid'] : 0;
                    $balance_due = $data['total_pay'] - $total_paid;
                    
                    // Skip if no activity at all
                    if ($data['total_pay'] == 0 && $total_paid == 0) continue;
                    
                    $is_paid = $total_paid > 0;
                    $is_fully_paid = $balance_due < 0.01; // Fully paid (accounting for floating point)
                    $payment = $paid_records[$uid] ?? null;
                    $has_commission = !empty($data['info']['commission_tiers']);
                ?>
                    <tr>
                        <td class="font-bold" data-label="Coach">
                            <button type="button" onclick="openCoachInfoModal(<?= $uid ?>, <?= htmlspecialchars(json_encode($data['info']), ENT_QUOTES) ?>)" class="btn-icon" style="margin-right: 6px;" title="View payment info">
                                <i class="fas fa-info-circle" style="color: rgb(0, 201, 255);"></i>
                            </button>
                            <?= e($data['info']['name']) ?>
                        </td>
                        <td data-label="Classes"><?= $data['class_count'] ?></td>
                        <td data-label="Regular">$<?= number_format($data['regular_pay'], 2) ?></td>
                        <td data-label="Private">$<?= number_format($data['private_pay'], 2) ?></td>
                        <td data-label="Salary" class="col-hide-mobile"><?= $data['fixed_salary'] > 0 ? '$' . number_format($data['fixed_salary'], 2) : '-' ?></td>
                        <td data-label="Commission" class="col-hide-mobile">
                            <?php if ($has_commission): ?>
                                $<?= number_format($data['commission_pay'], 2) ?>
                                <button type="button" onclick="openConversionModal(<?= $uid ?>, '<?= e($data['info']['name']) ?>', <?= $data['conversions'] ?>, '<?= date('Y-m-01', strtotime($start_date)) ?>')" class="btn-icon" style="margin-left: 4px;" title="Edit conversions">
                                    <i class="fas fa-edit"></i>
                                </button>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-label="Deduction" class="col-hide-mobile">
                            <?php if ($data['deduction'] > 0): ?>
                                <span class="text-danger">-$<?= number_format($data['deduction'], 2) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                            <button type="button" onclick="openDeductionModal(<?= $uid ?>, <?= htmlspecialchars(json_encode($data['info']['name']), ENT_QUOTES) ?>, <?= $data['deduction'] ?>, <?= htmlspecialchars(json_encode($data['deduction_reason']), ENT_QUOTES) ?>, '<?= date('Y-m-01', strtotime($start_date)) ?>')" class="btn-icon" style="margin-left: 4px;" title="Edit deduction">
                                <i class="fas fa-minus-circle"></i>
                            </button>
                        </td>
                        <td data-label="Total Owed" class="font-bold text-success">$<?= number_format($data['total_pay'], 2) ?></td>
                        <td data-label="Total Paid" class="font-bold" style="color: #28a745;">
                            <?= $total_paid > 0 ? '$' . number_format($total_paid, 2) : '-' ?>
                        </td>
                        <td data-label="Balance Due" class="font-bold" style="color: <?= $balance_due > 0.01 ? '#dc3545' : '#28a745' ?>;">
                            $<?= number_format($balance_due, 2) ?>
                        </td>
                        <td data-label="Status">
                            <?php if ($is_fully_paid): ?>
                                <span class="status-badge status-paid">
                                    <i class="fas fa-check-circle"></i> Paid <?= date('M d', strtotime($payment['last_payment_date'])) ?>
                                </span>
                            <?php elseif ($is_paid): ?>
                                <span class="status-badge status-partial">
                                    <i class="fas fa-exclamation-circle"></i> Partial
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-unpaid">
                                    <i class="fas fa-clock"></i> Unpaid
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="">
                            <?php if ($is_fully_paid): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove payment record for this coach? This will delete ALL payments for this period.');">
                                    <input type="hidden" name="action" value="delete_all_payments">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="location_id" value="<?= htmlspecialchars($filter_location_id) ?>">
                                    <input type="hidden" name="period_start" value="<?= $start_date ?>">
                                    <input type="hidden" name="period_end" value="<?= $end_date ?>">
                                    <button type="submit" class="btn-view" title="Remove all payments">
                                        <i class="fas fa-undo"></i> Undo
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn-pay" onclick="openPayModal(<?= $uid ?>, '<?= e($data['info']['name']) ?>', <?= $balance_due ?>)">
                                    <i class="fas fa-check"></i> <?= $is_paid ? 'Pay Balance' : 'Mark Paid' ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else: ?>
    <!-- History View -->
    <?php if (empty($payment_history)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h3>No payment history</h3>
            <p>No payments recorded for this period.</p>
        </div>
    <?php else: ?>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Coach</th>
                    <th>Payment Date</th>
                    <th>Period</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_history as $p): ?>
                    <tr>
                        <td class="font-bold" data-label="Coach"><?= e($p['coach_name']) ?></td>
                        <td data-label="Payment Date"><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                        <td data-label="Period"><?= date('M d', strtotime($p['period_start'])) ?> - <?= date('M d', strtotime($p['period_end'])) ?></td>
                        <td data-label="Amount" class="font-bold text-success">$<?= number_format($p['amount'], 2) ?></td>
                        <td data-label="Method">
                            <span class="status-badge <?= $p['payment_method'] === 'adp' ? 'status-paid' : 'status-unpaid' ?>">
                                <?= strtoupper($p['payment_method']) ?>
                            </span>
                        </td>
                        <td data-label="Notes" class="col-hide-mobile"><?= e($p['notes'] ?: '-') ?></td>
                        <td data-label="">
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this payment record?');">
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<!-- Payment Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-money-check-alt"></i> Record Payment</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="user_id" id="modal_user_id">
            <input type="hidden" name="location_id" value="<?= htmlspecialchars($filter_location_id) ?>">
            <input type="hidden" name="period_start" value="<?= $start_date ?>">
            <input type="hidden" name="period_end" value="<?= $end_date ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label>Coach</label>
                    <input type="text" id="modal_coach_name" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Period</label>
                    <input type="text" value="<?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?>" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" id="modal_amount" required>
                </div>
                <div class="form-group">
                    <label>Payment Date</label>
                    <input type="text" name="payment_date" id="modal_payment_date" value="<?= date('Y-m-d') ?>" required readonly>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="manual">Manual</option>
                        <option value="adp">ADP Payroll</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Any additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Conversion Modal -->
<div class="modal-overlay" id="conversionModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-users"></i> Manage Conversions</h3>
            <button class="modal-close" onclick="closeConversionModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_conversions">
            <input type="hidden" name="user_id" id="conv_user_id">
            <input type="hidden" name="period_month" id="conv_period_month">

            <div class="modal-body">
                <div class="form-group">
                    <label>Coach</label>
                    <input type="text" id="conv_coach_name" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Month</label>
                    <input type="text" id="conv_period_display" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Number of Conversions/Leads</label>
                    <input type="number" name="conversions" id="conv_conversions" min="0" required>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Any additional notes about conversions..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeConversionModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Save Conversions</button>
            </div>
        </form>
    </div>
</div>

<!-- Deduction Modal -->
<div class="modal-overlay" id="deductionModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-minus-circle"></i> Manage Deduction</h3>
            <button class="modal-close" onclick="closeDeductionModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_deduction">
            <input type="hidden" name="user_id" id="ded_user_id">
            <input type="hidden" name="period_month" id="ded_period_month">

            <div class="modal-body">
                <div class="form-group">
                    <label>Employee</label>
                    <input type="text" id="ded_coach_name" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Month</label>
                    <input type="text" id="ded_period_display" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Deduction Amount ($)</label>
                    <input type="number" step="0.01" name="amount" id="ded_amount" min="0" required>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" id="ded_reason" rows="2" placeholder="Reason for deduction (e.g., missed days, advance payment, etc.)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeDeductionModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Save Deduction</button>
            </div>
        </form>
    </div>
</div>

<!-- Coach Info Modal -->
<div class="modal-overlay" id="coachInfoModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-user-circle"></i> Coach Payment Information</h3>
            <button class="modal-close" onclick="closeCoachInfoModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Name</label>
                <input type="text" id="info_coach_name" readonly style="background:#f8f9fa;">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="text" id="info_coach_email" readonly style="background:#f8f9fa;">
            </div>
            <div class="form-group">
                <label>Payment Method</label>
                <input type="text" id="info_payment_method" readonly style="background:#f8f9fa;">
            </div>
            <div class="form-group">
                <label>Payment Information (Zelle, Bank, etc.)</label>
                <textarea id="info_payment_info" readonly style="background:#f8f9fa; min-height: 80px;" placeholder="No payment information on file"></textarea>
            </div>
            <div class="form-group">
                <label>Payment Frequency</label>
                <input type="text" id="info_payment_frequency" readonly style="background:#f8f9fa;">
            </div>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                <a id="info_coach_report_link" href="#" target="_blank" class="btn btn-primary" style="display: inline-block; text-decoration: none;">
                    <i class="fas fa-file-invoice-dollar"></i> View Detailed Report
                </a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeCoachInfoModal()">Close</button>
        </div>
    </div>
</div>

<script>
function openPayModal(userId, coachName, amount) {
    document.getElementById('modal_user_id').value = userId;
    document.getElementById('modal_coach_name').value = coachName;
    document.getElementById('modal_amount').value = amount.toFixed(2);
    document.getElementById('payModal').classList.add('active');
}

function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
}

function openConversionModal(userId, coachName, conversions, periodMonth) {
    document.getElementById('conv_user_id').value = userId;
    document.getElementById('conv_coach_name').value = coachName;
    document.getElementById('conv_conversions').value = conversions;
    document.getElementById('conv_period_month').value = periodMonth;

    // Format the period month for display
    const date = new Date(periodMonth);
    const monthName = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    document.getElementById('conv_period_display').value = monthName;

    document.getElementById('conversionModal').classList.add('active');
}

function closeConversionModal() {
    document.getElementById('conversionModal').classList.remove('active');
}

function openDeductionModal(userId, coachName, amount, reason, periodMonth) {
    document.getElementById('ded_user_id').value = userId;
    document.getElementById('ded_coach_name').value = coachName;
    document.getElementById('ded_amount').value = amount > 0 ? amount.toFixed(2) : '0.00';
    document.getElementById('ded_reason').value = reason;
    document.getElementById('ded_period_month').value = periodMonth;

    // Format the period month for display
    const date = new Date(periodMonth);
    const monthName = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    document.getElementById('ded_period_display').value = monthName;

    document.getElementById('deductionModal').classList.add('active');
}

function closeDeductionModal() {
    document.getElementById('deductionModal').classList.remove('active');
}

function openCoachInfoModal(userId, coachInfo) {
    document.getElementById('info_coach_name').value = coachInfo.name;
    document.getElementById('info_coach_email').value = coachInfo.email;
    document.getElementById('info_payment_method').value = coachInfo.payment_method || 'N/A';
    document.getElementById('info_payment_info').value = coachInfo.payment_info || '';
    
    // Format payment frequency
    const frequency = coachInfo.payment_frequency || 'weekly';
    document.getElementById('info_payment_frequency').value = frequency.charAt(0).toUpperCase() + frequency.slice(1);
    
    // Set report link (use coach_id parameter)
    const reportLink = 'reports.php?coach_id=' + userId;
    document.getElementById('info_coach_report_link').href = reportLink;
    
    document.getElementById('coachInfoModal').classList.add('active');
}

function closeCoachInfoModal() {
    document.getElementById('coachInfoModal').classList.remove('active');
}

// Close modals on overlay click
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

document.getElementById('conversionModal').addEventListener('click', function(e) {
    if (e.target === this) closeConversionModal();
});

document.getElementById('deductionModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeductionModal();
});

document.getElementById('coachInfoModal').addEventListener('click', function(e) {
    if (e.target === this) closeCoachInfoModal();
});

// Flatpickr initialization
document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const paymentDateInput = document.getElementById('modal_payment_date');
    const currentTab = '<?= $tab ?>';

    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function getPresetDates(preset) {
        const today = new Date();
        let start, end;

        switch(preset) {
            case 'this-month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                break;
            case 'last-month':
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end = new Date(today.getFullYear(), today.getMonth(), 0);
                break;
            case 'this-week':
                const dayOfWeek = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - dayOfWeek);
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
            case 'last-week':
                const lastWeekDay = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - lastWeekDay - 7);
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
        }
        return { start, end };
    }

    function createPresetButtons(fp) {
        const presetContainer = document.createElement('div');
        presetContainer.className = 'flatpickr-presets';

        const presets = [
            { label: 'This Week', value: 'this-week' },
            { label: 'Last Week', value: 'last-week' },
            { label: 'This Month', value: 'this-month' },
            { label: 'Last Month', value: 'last-month' }
        ];

        presets.forEach(preset => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = preset.label;
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const dates = getPresetDates(preset.value);
                startPicker.setDate(dates.start, true);
                endPicker.setDate(dates.end, true);
                fp.close();
            });
            presetContainer.appendChild(btn);
        });

        return presetContainer;
    }

    // Use monthSelect plugin for monthly tab, regular datepicker for others
    if (currentTab === 'monthly') {
        // Month picker for monthly tab
        const startPicker = flatpickr(startInput, {
            plugins: [
                new monthSelectPlugin({
                    shorthand: false,
                    dateFormat: "Y-m-d",
                    altFormat: "F Y"
                })
            ],
            onChange: function(selectedDates) {
                if (selectedDates[0]) {
                    // Set to first day of month
                    const date = new Date(selectedDates[0]);
                    const firstDay = new Date(date.getFullYear(), date.getMonth(), 1);
                    const lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0);
                    
                    startPicker.setDate(firstDay, false);
                    endPicker.setDate(lastDay, true);
                }
            }
        });

        const endPicker = flatpickr(endInput, {
            plugins: [
                new monthSelectPlugin({
                    shorthand: false,
                    dateFormat: "Y-m-d",
                    altFormat: "F Y"
                })
            ],
            onChange: function(selectedDates) {
                if (selectedDates[0]) {
                    // Set to last day of month
                    const date = new Date(selectedDates[0]);
                    const firstDay = new Date(date.getFullYear(), date.getMonth(), 1);
                    const lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0);
                    
                    startPicker.setDate(firstDay, true);
                    endPicker.setDate(lastDay, false);
                }
            }
        });
    } else {
        // Regular date picker for weekly/biweekly/history tabs
        const fpConfig = {
            dateFormat: 'Y-m-d',
            locale: { firstDayOfWeek: 0 },
            onReady: function(selectedDates, dateStr, instance) {
                instance.calendarContainer.appendChild(createPresetButtons(instance));
            }
        };

        const startPicker = flatpickr(startInput, {
            ...fpConfig,
            onChange: function(selectedDates) {
                if (selectedDates[0]) {
                    endPicker.set('minDate', selectedDates[0]);
                }
            }
        });

        const endPicker = flatpickr(endInput, {
            ...fpConfig,
            onChange: function(selectedDates) {
                if (selectedDates[0]) {
                    startPicker.set('maxDate', selectedDates[0]);
                }
            }
        });
    }

    // Payment date picker (simple, no presets)
    flatpickr(paymentDateInput, {
        dateFormat: 'Y-m-d',
        locale: { firstDayOfWeek: 0 }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
