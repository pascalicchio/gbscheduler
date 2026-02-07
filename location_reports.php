<?php
// location_reports.php - MASTER PAYROLL (Grouped by Location in Detailed View)
require_once 'includes/config.php';

// Require admin access
requireAuth(['admin']);

$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end'] ?? date('Y-m-t');
$view_mode  = $_GET['view'] ?? 'summary';
$filter_coach_id = $_GET['coach_id'] ?? '';

$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$coaches = $pdo->query("SELECT * FROM users WHERE role != 'manager' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$master_data = [];
$grand_total_pay = 0;
$grand_total_hours = 0;

foreach ($coaches as $c) {
    if ($filter_coach_id && $c['id'] != $filter_coach_id) continue;

    $master_data[$c['id']] = [
        'info' => $c,
        'regular_pay' => 0,
        'private_pay' => 0,
        'total_pay' => 0,
        'total_hours' => 0,
        'activities' => []
    ];
}

// Regular Classes Logic
$sql_reg = "
    SELECT ea.user_id, ea.class_date, ea.position, ct.class_name, ct.martial_art, ct.start_time, ct.end_time, l.name as loc_name, l.id as location_id,
           TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
    FROM event_assignments ea
    JOIN class_templates ct ON ea.template_id = ct.id
    JOIN locations l ON ct.location_id = l.id
    WHERE ea.class_date BETWEEN :start AND :end
    AND DAYNAME(ea.class_date) = ct.day_of_week
";
$stmt = $pdo->prepare($sql_reg);
$stmt->execute(['start' => $start_date, 'end' => $end_date]);
$reg_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($reg_rows as $row) {
    $uid = $row['user_id'];
    if (!isset($master_data[$uid])) continue;

    // Rounding Logic: < 1h becomes 1h
    $hours = (float)$row['hours'];
    if ($hours < 1) {
        $hours = 1.0;
    }

    $head_rate = $master_data[$uid]['info']['rate_head_coach'] ?? 0;
    $helper_rate = $master_data[$uid]['info']['rate_helper'] ?? 0;

    $rate = ($row['position'] === 'head') ? $head_rate : $helper_rate;
    $pay = $hours * $rate;

    $master_data[$uid]['regular_pay'] += $pay;
    $master_data[$uid]['total_pay'] += $pay;
    $master_data[$uid]['total_hours'] += $hours;

    $master_data[$uid]['activities'][] = [
        'type' => 'regular',
        'date' => $row['class_date'],
        'time' => date('g:i A', strtotime($row['start_time'])),
        'desc' => strtoupper($row['martial_art']) . ' - ' . $row['class_name'],
        'location' => $row['loc_name'],
        'location_id' => $row['location_id'],
        'detail' => ucfirst($row['position']),
        'rate' => $rate,
        'qty' => number_format($hours, 2) . 'h',
        'pay' => $pay
    ];
}

// Private Classes Logic
$sql_priv = "
    SELECT pc.*, l.name as loc_name 
    FROM private_classes pc
    JOIN locations l ON pc.location_id = l.id
    WHERE pc.class_date BETWEEN :start AND :end
";
$stmt = $pdo->prepare($sql_priv);
$stmt->execute(['start' => $start_date, 'end' => $end_date]);
$priv_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($priv_rows as $row) {
    $uid = $row['user_id'];
    if (!isset($master_data[$uid])) continue;

    $pay = $row['payout'];

    $master_data[$uid]['private_pay'] += $pay;
    $master_data[$uid]['total_pay'] += $pay;

    $master_data[$uid]['activities'][] = [
        'type' => 'private',
        'date' => $row['class_date'],
        'time' => $row['class_time'] ? date('g:i A', strtotime($row['class_time'])) : '-',
        'desc' => 'Private/Activity',
        'location' => $row['loc_name'],
        'location_id' => $row['location_id'],
        'detail' => $row['student_name'],
        'rate' => $pay,
        'qty' => '1',
        'pay' => $pay
    ];
}

foreach ($master_data as $uid => $data) {
    $grand_total_pay += $data['total_pay'];
    $grand_total_hours += $data['total_hours'];
    // Sort chronologically first
    usort($master_data[$uid]['activities'], function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}

// Page setup
$pageTitle = 'Payroll Report | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
HTML;

$extraCss = <<<CSS
        /* Alpine.js cloak */
        [x-cloak] {
            display: none !important;
        }

        /* ======================================== */
        /* CSS Variables - Gradient Design System */
        /* ======================================== */
        :root {
            --gradient-primary: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
            --gradient-hover: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
            --gradient-dark: linear-gradient(135deg, #1a202c, #2d3748);
            --text-dark: #2c3e50;
            --text-secondary: #6c757d;
            --text-light: #a0aec0;
            --bg-color: #f8fafb;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 12px 30px rgba(0, 201, 255, 0.12);
            --money-color: rgb(58, 222, 215);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        /* ======================================== */
        /* Base Layout - Mobile First */
        /* ======================================== */
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding: 16px;
            background-color: var(--bg-color);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
        }

        @media (min-width: 768px) {
            body {
                padding: 24px;
            }
        }

        /* ======================================== */
        /* Page Header - Standardized Layout */
        /* ======================================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header h2 i {
            background-image: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @media (min-width: 768px) {
            .page-header h2 {
                font-size: 1.75rem;
            }
        }

        /* ======================================== */
        /* Navigation Menu */
        /* ======================================== */
        .nav-menu {
            position: relative;
        }

        .nav-menu-btn {
            padding: 10px 18px;
            background: white;
            color: #2c3e50;
            border: 2px solid #e8ecf2;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-menu-btn:hover {
            background: rgba(0, 201, 255, 0.05);
            border-color: rgba(0, 201, 255, 0.3);
            color: rgb(0, 201, 255);
        }

        .nav-menu-btn i {
            font-size: 1.1rem;
        }

        .nav-dropdown {
            position: absolute;
            top: calc(100% + 2px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(0, 201, 255, 0.2);
            min-width: 220px;
            z-index: 100;
            overflow: hidden;
            padding-top: 6px;
        }

        .nav-dropdown::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-image: var(--gradient-primary);
        }

        .nav-dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .nav-dropdown a i {
            width: 18px;
            text-align: center;
            font-size: 1rem;
            color: #6c757d;
        }

        .nav-dropdown a:hover {
            background: linear-gradient(to right, rgba(0, 201, 255, 0.08), transparent);
            border-left-color: rgb(0, 201, 255);
            padding-left: 24px;
        }

        .nav-dropdown a:hover i {
            color: rgb(0, 201, 255);
        }

        .nav-dropdown a.active {
            background: linear-gradient(to right, rgba(0, 201, 255, 0.12), transparent);
            border-left-color: rgb(0, 201, 255);
            color: rgb(0, 201, 255);
            font-weight: 600;
        }

        .nav-dropdown a.active i {
            color: rgb(0, 201, 255);
        }

        .nav-dropdown a.logout {
            border-top: 1px solid var(--border-light);
            margin-top: 6px;
            color: #dc3545;
        }

        .nav-dropdown a.logout:hover {
            background: rgba(220, 53, 69, 0.08);
            border-left-color: #dc3545;
        }

        .nav-dropdown a.logout i {
            color: #dc3545;
        }

        /* ======================================== */
        /* Filter Controls */
        /* ======================================== */
        .controls {
            background: var(--bg-card);
            padding: 16px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            display: flex;
            gap: 12px;
            align-items: flex-end;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        @media (min-width: 768px) {
            .controls {
                padding: 20px;
                gap: 16px;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 140px;
            flex: 1;
        }

        @media (min-width: 768px) {
            .form-group {
                flex: 0 0 auto;
                min-width: 160px;
            }
        }

        .form-group label {
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text-dark);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-group.spacer {
            flex: 1;
            min-width: 0;
        }

        input[type="text"],
        select {
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            width: 100%;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.25s ease;
            background: var(--bg-card);
            color: var(--text-dark);
            height: 42px;
            box-sizing: border-box;
            font-family: inherit;
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: rgb(0, 201, 255);
            box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
        }

        /* Flatpickr preset buttons - 2 column grid */
        .flatpickr-presets {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 10px;
            border-top: 1px solid #e6e6e6;
            background: #f8f9fa;
        }

        .flatpickr-presets button {
            padding: 8px 10px;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            color: var(--text-dark);
        }

        .flatpickr-presets button:hover {
            background: rgba(0, 201, 255, 0.05);
            border-color: rgb(0, 201, 255);
            color: rgb(0, 181, 235);
        }

        /* ======================================== */
        /* Buttons */
        /* ======================================== */
        button {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .btn-gradient {
            background-image: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 201, 255, 0.25);
        }

        .btn-gradient:hover {
            background-image: var(--gradient-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0, 201, 255, 0.3);
        }

        .btn-view {
            background: var(--bg-card);
            border: 2px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 16px;
            border-radius: 10px;
        }

        .btn-view:hover {
            background: rgba(0, 201, 255, 0.05);
            border-color: rgba(0, 201, 255, 0.3);
            color: var(--text-dark);
        }

        .btn-view.active {
            background-image: var(--gradient-dark);
            color: white;
            border-color: transparent;
        }

        .btn-view:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .view-buttons {
            display: flex;
            gap: 6px;
        }

        /* ======================================== */
        /* Stats Summary - Dark Gradient Bar */
        .stats-bar {
            background-image: var(--gradient-dark);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item .label {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .stat-item .value {
            font-size: 1.25rem;
            font-weight: 800;
            color: white;
        }

        .stat-item .value.money {
            color: rgb(146, 254, 157);
            font-size: 1.5rem;
        }

        .stat-divider {
            width: 2px;
            height: 35px;
            background: rgba(255, 255, 255, 0.2);
        }

        /* ======================================== */
        /* Location Block - Summary View */
        /* ======================================== */
        .loc-block {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .loc-header {
            background-image: var(--gradient-dark);
            color: white;
            padding: 14px 20px;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .loc-header i {
            margin-right: 8px;
            opacity: 0.8;
        }

        .sum-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sum-table th {
            background: #f8fafb;
            text-align: left;
            padding: 10px 20px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }

        .sum-table th.col-right {
            text-align: right;
        }

        .sum-table td {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .col-money {
            font-family: monospace;
            font-weight: bold;
            color: var(--money-color);
            text-align: right;
            font-size: 1.1em;
        }

        .col-money.muted {
            color: var(--text-secondary);
        }

        .col-total {
            color: var(--money-color);
            font-size: 1.1em;
        }

        .col-right {
            text-align: right;
        }

        .loc-total-value {
            font-size: 1.15em;
        }

        .loc-total-row {
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.05), rgba(146, 254, 157, 0.05));
        }

        .loc-total-row td {
            font-weight: 700;
            border-bottom: none;
        }

        /* ======================================== */
        /* Coach Card - Detailed View */
        /* ======================================== */
        /* Coach Card Wrapper */
        .coach-card {
            margin-bottom: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 201, 255, 0.1);
        }

        /* Location Header with Gradient - Like reports.php */
        .loc-sub-header {
            background-image: var(--gradient-primary);
            color: white;
            padding: 14px 18px;
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .loc-sub-header i {
            margin-right: 6px;
        }

        .loc-total-badge {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            background: white;
            color: rgb(0, 201, 255);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        /* Detail Table */
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .detail-table th {
            background: #f8fafb;
            color: var(--text-secondary);
            text-align: left;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            white-space: nowrap;
        }

        .detail-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f0;
            color: var(--text-dark);
            vertical-align: middle;
        }

        .detail-table tbody tr:hover {
            background: rgba(0, 201, 255, 0.03);
        }

        .w-date { width: 15%; }
        .w-time { width: 10%; }
        .w-type { width: 15%; }
        .w-det { width: 30%; }
        .w-rate { width: 10%; }
        .w-qty { width: 10%; }
        .w-tot { width: 10%; }

        .col-money {
            font-family: monospace;
            font-weight: bold;
            color: var(--money-color);
            font-size: 1.1em;
        }

        /* Coach Detail Header */
        .coach-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 24px 0;
        }

        .coach-detail-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .coach-detail-title i {
            color: rgb(0, 201, 255);
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-reg {
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.15), rgba(0, 201, 255, 0.05));
            color: rgb(0, 160, 200);
            border: 1px solid rgba(0, 201, 255, 0.3);
        }

        .badge-priv {
            background: linear-gradient(135deg, rgba(255, 152, 0, 0.15), rgba(255, 152, 0, 0.05));
            color: #e65100;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .pdf-only {
            display: none;
            margin-bottom: 24px;
        }

        /* ======================================== */
        /* Calendar View */
        /* ======================================== */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            background: #e9ecef;
            border: 1px solid rgba(0, 201, 255, 0.1);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .calendar-header {
            background: linear-gradient(135deg, #1a202c, #2d3748);
            color: white;
            padding: 12px 6px;
            text-align: center;
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
        }

        .calendar-day {
            background: white;
            min-height: 100px;
            padding: 8px;
            transition: all 0.2s ease;
        }

        .calendar-day.other-month {
            background: #fff;
        }

        .calendar-day.today {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            border: 2px solid #ffc107;
        }

        .day-number {
            font-weight: 800;
            font-size: 0.9rem;
            color: #2c3e50;
            margin-bottom: 6px;
        }

        .day-activities {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .activity-item {
            font-size: 0.7rem;
            padding: 5px 7px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }

        .activity-item.regular {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #0d47a1;
            border-left: 3px solid #1565c0;
        }

        .activity-item.private {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #e65100;
            border-left: 3px solid #f57c00;
        }

        .activity-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .activity-time {
            font-weight: 700;
            white-space: nowrap;
        }

        .activity-desc {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .activity-pay {
            font-weight: 800;
            white-space: nowrap;
        }

        .day-total {
            margin-top: 6px;
            padding-top: 6px;
            font-size: 0.7em;
            font-weight: bold;
            color: var(--money-color);
            text-align: right;
        }

        .location-group {
            margin-bottom: 3px;
        }

        .location-group:last-child {
            margin-bottom: 0;
        }

        .location-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 3px;
            padding: 3px 6px;
            background: rgba(0, 201, 255, 0.06);
            border-radius: 4px;
            letter-spacing: 0.05em;
        }

        /* ======================================== */
        /* PDF Button */
        /* ======================================== */
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-1px);
        }

        .btn-pdf:disabled {
            background: #6c757d;
            cursor: not-allowed;
            box-shadow: none;
        }

        /* ======================================== */
        /* Responsive */
        /* ======================================== */
        @media (min-width: 600px) {
            .stats-bar {
                padding: 18px 24px;
            }

            .stat-item .value {
                font-size: 1.35rem;
            }

            .stat-item .value.money {
                font-size: 1.75rem;
            }
        }

        @media (max-width: 900px) {
            .calendar-grid {
                font-size: 0.85em;
            }

            .calendar-day {
                min-height: 80px;
                padding: 6px;
            }

            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 2px;
            }

            .activity-desc {
                display: none;
            }
        }

        @media (max-width: 600px) {
            .calendar-header {
                padding: 10px 4px;
                font-size: 0.7rem;
            }

            .calendar-day {
                min-height: 60px;
            }

            .day-number {
                font-size: 0.8rem;
            }

            .activity-item {
                padding: 3px 5px;
                font-size: 0.7rem;
            }

            .activity-time {
                display: none;
            }

            .day-total {
                font-size: 0.7rem;
            }

            .stats-bar {
                padding: 12px 14px;
                gap: 10px;
            }

            .stat-item .label {
                font-size: 0.7rem;
            }

            .stat-item .value {
                font-size: 1.1rem;
            }

            .stat-item .value.money {
                font-size: 1.3rem;
            }

            .stat-divider {
                display: none;
            }
        }

        @media print {
            .controls,
            .page-header,
            .btn-pdf {
                display: none !important;
            }

            body {
                background: white;
                padding: 0;
            }

            .loc-block,
            .coach-card {
                box-shadow: none;
                border: 1px solid #ccc;
                break-inside: avoid;
            }
        }
CSS;

require_once 'includes/header.php';
?>

    <!-- Page Header -->
    <div class="page-header">
        <h2><i class="fas fa-file-invoice-dollar"></i> Payroll Report</h2>
        <div class="nav-menu" x-data="{ open: false }" @mouseenter="if(window.innerWidth >= 768) open = true" @mouseleave="if(window.innerWidth >= 768) open = false">
            <button @click="if(window.innerWidth < 768) open = !open" class="nav-menu-btn">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </button>
            <div x-show="open" @click.away="if(window.innerWidth < 768) open = false" @mouseenter="open = true" x-cloak class="nav-dropdown">
                <a href="dashboard.php"><i class="fas fa-calendar-alt"></i> Dashboard</a>
                <a href="reports.php"><i class="fas fa-chart-line"></i> Individual Report</a>
                <?php if (canManage()): ?>
                    <a href="private_classes.php"><i class="fas fa-money-bill-wave"></i> Private Classes</a>
                    <a href="location_reports.php" class="active"><i class="fas fa-file-invoice-dollar"></i> Payroll Reports</a>
                    <a href="coach_payments.php"><i class="fas fa-money-check-alt"></i> Coach Payments</a>
                    <a href="classes.php"><i class="fas fa-graduation-cap"></i> Class Templates</a>
                    <a href="users.php"><i class="fas fa-users"></i> Users</a>
                    <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory</a>
                <?php endif; ?>
                <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <form method="GET" class="controls">
        <div class="form-group">
            <label>Start Date</label>
            <input type="text" name="start" id="start_date" value="<?= $start_date ?>" readonly>
        </div>
        <div class="form-group">
            <label>End Date</label>
            <input type="text" name="end" id="end_date" value="<?= $end_date ?>" readonly>
        </div>
        <div class="form-group">
            <label>Filter by Coach</label>
            <select name="coach_id">
                <option value="">All Coaches</option>
                <?php foreach ($coaches as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_coach_id == $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group spacer"></div>
        <div class="form-group">
            <label>View Mode</label>
            <div class="view-buttons">
                <button type="submit" name="view" value="summary" class="btn-view <?= $view_mode == 'summary' ? 'active' : '' ?>">Summary</button>
                <button type="submit" name="view" value="detailed" class="btn-view <?= $view_mode == 'detailed' ? 'active' : '' ?>">Detailed</button>
                <button type="submit" name="view" value="calendar" id="btn-calendar" class="btn-view <?= $view_mode == 'calendar' ? 'active' : '' ?>" <?= empty($filter_coach_id) ? 'disabled title="Select a coach first"' : '' ?>>Calendar</button>
            </div>
        </div>
        <div class="form-group">
            <button type="submit" class="btn-gradient">Apply</button>
        </div>
    </form>

    <!-- Global Summary Header (shown in both views) -->
    <div class="stats-bar">
        <div class="stat-item">
            <span class="label">Period</span>
            <span class="value"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <span class="label">Hours</span>
            <span class="value"><?= number_format($grand_total_hours, 1) ?></span>
        </div>
        <?php if ($filter_coach_id && isset($master_data[$filter_coach_id])): ?>
            <?php $coach_data = $master_data[$filter_coach_id]; ?>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="label">Regular</span>
                <span class="value money">$<?= number_format($coach_data['regular_pay'], 2) ?></span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="label">Private</span>
                <span class="value money">$<?= number_format($coach_data['private_pay'], 2) ?></span>
            </div>
        <?php endif; ?>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <span class="label">Total Payroll</span>
            <span class="value money">$<?= number_format($grand_total_pay, 2) ?></span>
        </div>
    </div>

    <?php if ($view_mode === 'summary'): ?>
        <?php foreach ($locations as $loc):
            $loc_total = 0;
            $has_data = false;
        ?>
            <?php ob_start(); ?>
            <div class="loc-block">
                <div class="loc-header">
                    <span><i class="fas fa-map-marker-alt"></i> <?= e($loc['name']) ?></span>
                </div>
                <table class="sum-table">
                    <thead>
                        <tr>
                            <th>Coach</th>
                            <th>Reg. Hours</th>
                            <th class="col-right">Reg. Pay</th>
                            <th class="col-right">Priv. Pay</th>
                            <th class="col-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($master_data as $uid => $data): ?>
                            <?php
                            $loc_reg_pay = 0;
                            $loc_reg_hrs = 0;
                            $loc_priv_pay = 0;
                            foreach ($data['activities'] as $act) {
                                if ($act['location_id'] == $loc['id']) {
                                    if ($act['type'] == 'regular') {
                                        $loc_reg_pay += $act['pay'];
                                        $loc_reg_hrs += (float)$act['qty'];
                                    } else {
                                        $loc_priv_pay += $act['pay'];
                                    }
                                }
                            }
                            if ($loc_reg_hrs == 0 && $loc_reg_pay == 0 && $loc_priv_pay == 0) continue;
                            $has_data = true;
                            $loc_total_coach = $loc_reg_pay + $loc_priv_pay;
                            $loc_total += $loc_total_coach;
                            ?>
                            <tr>
                                <td><?= e($data['info']['name']) ?></td>
                                <td><?= number_format($loc_reg_hrs, 2) ?></td>
                                <td class="col-money muted">$<?= number_format($loc_reg_pay, 2) ?></td>
                                <td class="col-money muted">$<?= number_format($loc_priv_pay, 2) ?></td>
                                <td class="col-money col-total">$<?= number_format($loc_total_coach, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="loc-total-row">
                            <td colspan="4" class="col-right">LOCATION TOTAL:</td>
                            <td class="col-money col-total loc-total-value">$<?= number_format($loc_total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php $html = ob_get_clean();
            if ($has_data) echo $html; ?>
        <?php endforeach; ?>

    <?php elseif ($view_mode === 'detailed'): ?>

        <div id="pdf-content">
        <!-- PDF Header (copy of stats bar for PDF - hidden on screen) -->
        <div class="stats-bar pdf-only">
            <div class="stat-item">
                <span class="label">Period</span>
                <span class="value"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="label">Hours</span>
                <span class="value"><?= number_format($grand_total_hours, 1) ?></span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="label">Total Payroll</span>
                <span class="value money">$<?= number_format($grand_total_pay, 2) ?></span>
            </div>
        </div>

        <?php foreach ($master_data as $uid => $data):
            if (empty($data['activities'])) continue;

            // GROUP ACTIVITIES BY LOCATION
            $grouped_activities = [];
            foreach ($data['activities'] as $act) {
                $grouped_activities[$act['location_id']][] = $act;
            }
        ?>
            <div class="coach-detail-header">
                <h3 class="coach-detail-title">
                    <i class="fas fa-user-circle"></i> 
                    <?= e($data['info']['name']) ?>
                </h3>
                <button type="button" class="btn-pdf" onclick="exportPDF()" id="pdf-btn-<?= $uid ?>">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
            <div class="coach-card">
                <?php foreach ($grouped_activities as $loc_id => $acts):
                    // Calculate sub-total for this location block
                    $block_total = 0;
                    $loc_name = '';
                    foreach ($acts as $a) {
                        $block_total += $a['pay'];
                        $loc_name = $a['location'];
                    }
                ?>
                    <div class="loc-sub-header">
                        <span><i class="fas fa-map-marker-alt"></i> <?= e($loc_name) ?></span>
                        <span class="loc-total-badge">Sub-Total: $<?= number_format($block_total, 2) ?></span>
                    </div>
                    <table class="detail-table">
                        <thead>
                            <tr>
                                <th class="w-date">Date</th>
                                <th class="w-time">Time</th>
                                <th class="w-type">Class/Type</th>
                                <th class="w-det">Details</th>
                                <th class="w-rate col-right">Rate</th>
                                <th class="w-qty col-right">Qty</th>
                                <th class="w-tot col-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($acts as $act): ?>
                                <tr>
                                    <td><?= date('D, M d', strtotime($act['date'])) ?></td>
                                    <td><?= $act['time'] ?></td>
                                    <td>
                                        <?php if ($act['type'] == 'regular'): ?>
                                            <span class="badge badge-reg">Class</span> <?= e($act['desc']) ?>
                                        <?php else: ?>
                                            <span class="badge badge-priv">Private</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $act['detail'] ?></td>
                                    <td class="col-right"><?= ($act['rate'] == '-' || $act['rate'] == 0) ? '-' : '$' . number_format($act['rate'], 2) ?></td>
                                    <td class="col-right"><?= $act['qty'] ?></td>
                                    <td class="col-right col-money">$<?= number_format($act['pay'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div><!-- end pdf-content -->

    <?php elseif ($view_mode === 'calendar' && $filter_coach_id): ?>
        <?php
        // Get the selected coach's data
        $coach_data = $master_data[$filter_coach_id] ?? null;

        if ($coach_data):
            // Group activities by date
            $activities_by_date = [];
            foreach ($coach_data['activities'] as $act) {
                $activities_by_date[$act['date']][] = $act;
            }

            // Sort activities within each day by time (convert 12h to 24h for proper sorting)
            foreach ($activities_by_date as $date => &$acts) {
                usort($acts, function($a, $b) {
                    return strtotime($a['time']) - strtotime($b['time']);
                });
            }
            unset($acts);

            // Calculate calendar range based on start_date
            $cal_start = new DateTime($start_date);
            $cal_start->modify('first day of this month');
            $cal_end = new DateTime($start_date);
            $cal_end->modify('last day of this month');

            // Adjust to start on Sunday
            $first_day_of_week = (int)$cal_start->format('w'); // 0 = Sunday
            $cal_start->modify("-{$first_day_of_week} days");

            // Adjust to end on Saturday
            $last_day_of_week = (int)$cal_end->format('w');
            $days_to_add = 6 - $last_day_of_week;
            $cal_end->modify("+{$days_to_add} days");

            $current_month = date('n', strtotime($start_date));
            $today = date('Y-m-d');
        ?>

        <div class="calendar-grid">
            <!-- Header Row -->
            <div class="calendar-header">Sun</div>
            <div class="calendar-header">Mon</div>
            <div class="calendar-header">Tue</div>
            <div class="calendar-header">Wed</div>
            <div class="calendar-header">Thu</div>
            <div class="calendar-header">Fri</div>
            <div class="calendar-header">Sat</div>

            <!-- Calendar Days -->
            <?php
            $current = clone $cal_start;
            while ($current <= $cal_end):
                $date_str = $current->format('Y-m-d');
                $is_other_month = (int)$current->format('n') !== $current_month;
                $is_today = $date_str === $today;
                $day_activities = $activities_by_date[$date_str] ?? [];
                $day_total = array_sum(array_column($day_activities, 'pay'));

                // Group by location
                $by_location = [];
                foreach ($day_activities as $act) {
                    $by_location[$act['location']][] = $act;
                }

                $classes = ['calendar-day'];
                if ($is_other_month) $classes[] = 'other-month';
                if ($is_today) $classes[] = 'today';
            ?>
                <div class="<?= implode(' ', $classes) ?>">
                    <div class="day-number"><?= $current->format('j') ?></div>
                    <?php if (!empty($by_location)): ?>
                        <div class="day-activities">
                            <?php foreach ($by_location as $loc_name => $acts): ?>
                                <div class="location-group">
                                    <div class="location-label"><?= e($loc_name) ?></div>
                                    <?php foreach ($acts as $act): ?>
                                        <div class="activity-item <?= $act['type'] ?>">
                                            <span class="activity-time"><?= $act['time'] ?></span>
                                            <span class="activity-desc"><?= $act['type'] === 'regular' ? e($act['desc']) : 'Private' ?></span>
                                            <span class="activity-pay">$<?= number_format($act['pay'], 2) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="day-total">$<?= number_format($day_total, 2) ?></div>
                    <?php endif; ?>
                </div>
            <?php
                $current->modify('+1 day');
            endwhile;
            ?>
        </div>

        <?php else: ?>
            <div class="bg-yellow-100 p-5 rounded-lg text-center">
                <i class="fas fa-exclamation-triangle text-yellow-600"></i> No data found for the selected coach.
            </div>
        <?php endif; ?>

    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');

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
            { label: 'This Month', value: 'this-month' },
            { label: 'Last Month', value: 'last-month' },
            { label: 'This Week', value: 'this-week' },
            { label: 'Last Week', value: 'last-week' }
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

    if (startInput.value) endPicker.set('minDate', startInput.value);
    if (endInput.value) startPicker.set('maxDate', endInput.value);

    // Enable/disable Calendar button based on coach selection
    const coachSelect = document.querySelector('select[name="coach_id"]');
    const calendarBtn = document.getElementById('btn-calendar');

    if (coachSelect && calendarBtn) {
        coachSelect.addEventListener('change', function() {
            if (this.value) {
                calendarBtn.disabled = false;
                calendarBtn.title = '';
            } else {
                calendarBtn.disabled = true;
                calendarBtn.title = 'Select a coach first';
            }
        });
    }
});

// PDF Export function
function exportPDF() {
    const btn = document.getElementById('pdf-btn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;

    const element = document.getElementById('pdf-content');
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;

    // Show pdf-only elements for export
    const pdfOnlyElements = document.querySelectorAll('.pdf-only');
    pdfOnlyElements.forEach(el => el.style.display = 'flex');

    const opt = {
        margin: [10, 10, 10, 10],
        filename: `payroll-report-${startDate}-to-${endDate}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
            scale: 2,
            useCORS: true,
            letterRendering: true
        },
        jsPDF: {
            unit: 'mm',
            format: 'a4',
            orientation: 'portrait'
        },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };

    html2pdf().set(opt).from(element).save().then(function() {
        // Hide pdf-only elements again
        pdfOnlyElements.forEach(el => el.style.display = 'none');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }).catch(function(err) {
        console.error('PDF generation error:', err);
        pdfOnlyElements.forEach(el => el.style.display = 'none');
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('Error generating PDF. Please try again.');
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>