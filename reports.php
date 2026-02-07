<?php
// reports.php - INDIVIDUAL PAYROLL REPORT (Fixed Logic)
require_once 'includes/config.php';

// Require login
requireAuth();

$is_admin = isAdmin();
$logged_in_user_id = getUserId();

// Determine Target User
$target_user_id = $logged_in_user_id;
if ($is_admin && isset($_GET['coach_id']) && $_GET['coach_id'] !== '') {
    $target_user_id = $_GET['coach_id'];
}

// Date Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$view_mode = $_GET['view'] ?? 'list';

// 1. Fetch User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$target_user_id]);
$coach = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Regular Classes
$sql_regular = "
    SELECT ea.class_date, ea.position, ct.class_name, ct.martial_art, ct.start_time, ct.end_time, l.id as location_id, l.name as location_name,
           TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
    FROM event_assignments ea
    JOIN class_templates ct ON ea.template_id = ct.id
    JOIN locations l ON ct.location_id = l.id
    WHERE ea.user_id = :uid AND ea.class_date BETWEEN :start AND :end
    AND DAYNAME(ea.class_date) = ct.day_of_week
    ORDER BY ea.class_date ASC, ct.start_time ASC
";
$stmt_reg = $pdo->prepare($sql_regular);
$stmt_reg->execute(['uid' => $target_user_id, 'start' => $start_date, 'end' => $end_date]);
$regular_classes = $stmt_reg->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Private Classes
$sql_private = "
    SELECT pc.*, l.name as location_name
    FROM private_classes pc
    JOIN locations l ON pc.location_id = l.id
    WHERE pc.user_id = :uid AND pc.class_date BETWEEN :start AND :end
    ORDER BY pc.class_date ASC, pc.class_time ASC
";
$stmt_priv = $pdo->prepare($sql_private);
$stmt_priv->execute(['uid' => $target_user_id, 'start' => $start_date, 'end' => $end_date]);
$private_classes = $stmt_priv->fetchAll(PDO::FETCH_ASSOC);

// --- PROCESS DATA ---
$locations_data = [];
$grand_total_pay = 0;
$grand_total_hours = 0;
$grand_total_classes = 0;

foreach ($regular_classes as $rc) {
    $lid = $rc['location_id'];
    if (!isset($locations_data[$lid])) {
        $locations_data[$lid] = ['name' => $rc['location_name'], 'reg' => [], 'priv' => [], 'totals' => ['reg_pay' => 0, 'priv_pay' => 0]];
    }

    // --- LOGIC FIX START ---
    $hours = (float)$rc['hours'];

    // Apply Minimum 1-Hour Rule (Also fixes negative times like -11.0)
    if ($hours < 1) {
        $hours = 1.0;
    }
    $rc['hours'] = $hours; // Update array for display
    // --- LOGIC FIX END ---

    $rate = ($rc['position'] === 'head') ? $coach['rate_head_coach'] : $coach['rate_helper'];
    $pay = $hours * $rate;
    $rc['pay'] = $pay;

    $locations_data[$lid]['reg'][] = $rc;
    $locations_data[$lid]['totals']['reg_pay'] += $pay;
    $grand_total_hours += $hours;
    $grand_total_classes++;
}

foreach ($private_classes as $pc) {
    $lid = $pc['location_id'];
    if (!isset($locations_data[$lid])) {
        $locations_data[$lid] = ['name' => $pc['location_name'], 'reg' => [], 'priv' => [], 'totals' => ['reg_pay' => 0, 'priv_pay' => 0]];
    }

    $final_pay = $pc['payout'];

    $locations_data[$lid]['priv'][] = $pc;
    $locations_data[$lid]['totals']['priv_pay'] += $final_pay;
    $grand_total_classes++;
}

foreach ($locations_data as $ld) {
    $grand_total_pay += $ld['totals']['reg_pay'] + $ld['totals']['priv_pay'];
}

$all_coaches = $is_admin ? $pdo->query("SELECT id, name FROM users WHERE role != 'manager' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

// Page setup
$pageTitle = 'Payroll Report: ' . e($coach['name']) . ' | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
        /* Alpine.js cloak */
        [x-cloak] {
            display: none !important;
        }

        :root {
            --gradient-primary: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
            --gradient-hover: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
            --gradient-dark: linear-gradient(135deg, #1a202c, #2d3748);
            --primary: rgb(0, 201, 255);
            --bg: #f8fafb;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            padding: 20px;
            color: #2c3e50;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
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

        /* Navigation Menu */
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

        /* Filter Card */
        .filter-card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            border: 1px solid rgba(0, 201, 255, 0.1);
            position: relative;
        }

        .filter-card::before {
            content: "";
            position: absolute;
            top: -1px;
            left: 6px;
            right: 0;
            width: calc(100% - 10px);
            height: 3px;
            background-image: var(--gradient-primary);
            border-radius: 16px 16px 0 0;
        }

        .filter-row {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .date-inputs-row {
            display: flex;
            gap: 10px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            margin-bottom: 0;
        }

        .form-group.coach-select select {
            padding: 0px 14px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.75rem;
            margin-bottom: 8px;
            text-transform: uppercase;
            color: #2c3e50;
            letter-spacing: 0.05em;
        }

        .form-group input,
        .form-group select {
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            height: 42px;
            box-sizing: border-box;
            font-size: 0.95rem;
            font-weight: 500;
            background: white;
            transition: all 0.25s ease;
            width: 100%;
        }

        .form-group select {
            min-width: 200px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
        }

        .btn-filter {
            background-image: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            height: 42px;
            box-sizing: border-box;
            box-shadow: 0 6px 20px rgba(0, 201, 255, 0.25);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.9rem;
        }

        .btn-filter:hover {
            background-image: var(--gradient-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 201, 255, 0.35);
        }

        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 2px;
            border-radius: 10px;
            overflow: hidden;
            background: #e2e8f0;
        }

        .btn-view {
            flex: 1;
            padding: 0 16px;
            border: none;
            background: #f0f0f0;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            color: #6c757d;
            height: 42px;
            box-sizing: border-box;
            transition: all 0.25s ease;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .btn-view:hover {
            background: rgba(0, 201, 255, 0.1);
            color: rgb(0, 201, 255);
        }

        .btn-view.active {
            background: #2c3e50;
            color: white;
        }

        /* Button row */
        .button-row {
            display: flex;
            gap: 10px;
            align-items: stretch;
            margin-top: 24px;
        }

        .button-row .view-toggle {
            flex: 1;
        }

        .button-row .btn-filter {
            flex: 0 0 auto;
            min-width: 80px;
        }

        /* Flatpickr preset buttons */
        .flatpickr-presets {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px;
            padding: 10px;
            border-top: 1px solid #e6e6e6;
            background: #f5f5f5;
        }

        .flatpickr-presets button {
            padding: 8px 10px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .flatpickr-presets button:hover {
            background: #e9ecef;
            border-color: rgb(0, 201, 255);
            color: rgb(0, 201, 255);
        }

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

        /* Location Sections */
        .location-section {
            margin-bottom: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 201, 255, 0.1);
        }

        .loc-header {
            background-image: var(--gradient-primary);
            color: white;
            padding: 14px 18px;
            font-weight: 700;
            font-size: 1rem;
        }

        .loc-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .loc-summary {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .summary-badge {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.2);
            letter-spacing: 0.02em;
            color: #1f2532;
        }

        .summary-badge.total {
            background: white;
            color: rgb(0, 201, 255);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .sub-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #2c3e50;
            border-bottom: 2px solid rgba(0, 201, 255, 0.1);
            letter-spacing: 0.05em;
        }

        .sub-header-private {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #0d47a1;
        }

        /* Note icon */
        .note-icon {
            cursor: pointer;
            color: rgb(0, 201, 255);
            font-size: 1.1rem;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .note-icon:hover {
            background: rgba(0, 201, 255, 0.1);
            transform: scale(1.1);
        }

        /* Note popup */
        .note-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .note-popup-overlay.active {
            display: flex;
        }

        .note-popup {
            background: white;
            padding: 24px;
            border-radius: 16px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 12px 40px rgba(0, 201, 255, 0.25);
            border: 1px solid rgba(0, 201, 255, 0.2);
            position: relative;
        }

        .note-popup::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-image: var(--gradient-primary);
            border-radius: 16px 16px 0 0;
        }

        .note-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid rgba(0, 201, 255, 0.1);
        }

        .note-popup-header h4 {
            margin: 0;
            color: rgb(0, 201, 255);
            font-size: 1.1rem;
            font-weight: 700;
        }

        .note-popup-close {
            background: none;
            border: none;
            font-size: 1.75rem;
            cursor: pointer;
            color: #999;
            line-height: 1;
            transition: all 0.2s ease;
            padding: 0;
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }

        .note-popup-close:hover {
            background: rgba(0, 201, 255, 0.1);
            color: rgb(0, 201, 255);
        }

        .note-popup-content {
            color: #2c3e50;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        /* Tables */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th, td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        th {
            background: linear-gradient(to bottom, #fafbfc, #f5f7fa);
            color: #6c757d;
            font-size: 0.75rem;
            text-transform: uppercase;
            border-bottom: 2px solid rgba(0, 201, 255, 0.2);
            white-space: nowrap;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        tbody tr:hover {
            background: rgba(0, 201, 255, 0.03);
        }

        .money-cell {
            font-family: monospace;
            font-weight: bold;
            color: #05B8E9;
            text-align: right;
            font-size: 1.1em;
        }

        .badge-role {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.15), rgba(146, 254, 157, 0.15));
            color: rgb(0, 181, 235);
            border: 1px solid rgba(0, 201, 255, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* Calendar View */
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
            background: #2c3e50;
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
            color: #05B8E9;
            text-align: right;
        }

        .location-group {
            margin-bottom: 3px;
        }

        .location-label {
            font-size: 0.6em;
            font-weight: bold;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 2px;
            padding: 4px 6px;
            background: #e9ecef;
            border-radius: 4px;
        }

        /* Desktop Enhancements */
        @media (min-width: 600px) {
            body {
                padding: 20px;
            }

            .filter-row {
                flex-direction: row;
                align-items: flex-start;
                gap: 12px;
            }

            .form-group.coach-select {
                flex: 0 0 200px;
            }

            .date-inputs-row {
                flex: 0 0 auto;
                display: flex;
                gap: 10px;
            }

            .date-inputs-row .form-group {
                flex: 0 0 auto;
                min-width: 140px;
            }

            .button-row {
                flex: 0 0 auto;
                display: flex;
                gap: 10px;
                align-items: flex-start;
            }

            .button-row .view-toggle {
                flex: 0 0 auto;
                width: 180px;
            }

            .button-row .btn-filter {
                flex: 0 0 auto;
                min-width: 80px;
            }

            .stats-bar {
                padding: 18px 24px;
            }

            .stat-item .value {
                font-size: 1.35rem;
            }

            .stat-item .value.money {
                font-size: 1.75rem;
            }

            .calendar-day {
                min-height: 110px;
                padding: 8px;
            }

            .calendar-header {
                font-size: 0.85em;
                padding: 12px 8px;
            }

            .activity-item {
                font-size: 0.75em;
            }
        }

        /* Small Mobile - List-based calendar */
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .page-header {
                flex-wrap: wrap;
            }

            .nav-menu-btn span {
                display: none;
            }

            .nav-menu-btn {
                padding: 10px 14px;
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

            /* Hide calendar grid headers on mobile */
            .calendar-header {
                display: none;
            }

            /* Convert calendar to list view on mobile */
            .calendar-grid {
                display: block !important;
                background: transparent;
                border: none;
                gap: 0;
                padding: 0;
            }

            .calendar-day {
                display: block !important;
                min-height: auto;
                padding: 12px;
                margin-bottom: 8px;
                border-radius: 8px;
                border: 1px solid #dee2e6;
                background: white;
            }

            .calendar-day.other-month {
                display: none; /* Hide other month days on mobile */
            }

            .calendar-day.today {
                border-color: #ffc107;
                background: #fff3cd;
            }

            /* Show day of week on mobile */
            .day-number {
                font-size: 1.15rem;
                margin-bottom: 12px;
                padding-bottom: 10px;
                border-bottom: 2px solid rgba(0, 201, 255, 0.2);
                color: #2c3e50;
                font-weight: 800;
            }

            .day-number::before {
                content: attr(data-day-name) ", ";
                font-weight: 700;
                color: rgb(0, 201, 255);
                margin-right: 4px;
            }

            .activity-item {
                font-size: 0.85em;
                padding: 8px;
                margin-bottom: 4px;
            }

            .activity-time {
                font-size: 1em;
            }

            .activity-desc {
                white-space: normal;
                word-wrap: break-word;
            }

            .activity-pay {
                font-size: 1em;
            }

            .day-total {
                font-size: 0.9em;
                padding: 8px;
                margin-top: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                text-align: center;
            }

            .location-label {
                font-size: 0.7em;
            }

            table {
                font-size: 0.8em;
            }

            th, td {
                padding: 8px;
            }
        }
CSS;

require_once 'includes/header.php';
?>

    <div class="page-header">
        <h2><i class="fas fa-chart-line"></i> Payroll Report: <?= e($coach['name']) ?></h2>
        <div class="nav-menu" x-data="{ open: false }" @mouseenter="if(window.innerWidth >= 768) open = true" @mouseleave="if(window.innerWidth >= 768) open = false">
            <button @click="if(window.innerWidth < 768) open = !open" class="nav-menu-btn">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </button>
            <div x-show="open" @click.away="if(window.innerWidth < 768) open = false" @mouseenter="open = true" x-cloak class="nav-dropdown">
                <a href="dashboard.php"><i class="fas fa-calendar-alt"></i> Dashboard</a>
                <a href="reports.php" class="active"><i class="fas fa-chart-line"></i> Individual Report</a>
                <?php if (canManage()): ?>
                    <a href="private_classes.php"><i class="fas fa-money-bill-wave"></i> Private Classes</a>
                    <a href="location_reports.php"><i class="fas fa-file-invoice-dollar"></i> Payroll Reports</a>
                    <a href="coach_payments.php"><i class="fas fa-money-check-alt"></i> Coach Payments</a>
                    <a href="classes.php"><i class="fas fa-graduation-cap"></i> Class Templates</a>
                    <a href="users.php"><i class="fas fa-users"></i> Users</a>
                    <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory</a>
                <?php endif; ?>
                <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <form class="filter-card" method="GET" id="reportForm">
        <div class="filter-row">
            <?php if ($is_admin): ?>
                <div class="form-group coach-select">
                    <label>Coach</label>
                    <select name="coach_id">
                        <?php foreach ($all_coaches as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($target_user_id == $c['id']) ? 'selected' : '' ?>><?= $c['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="date-inputs-row">
                <div class="form-group">
                    <label>From</label>
                    <input type="text" name="start_date" id="start_date" value="<?= $start_date ?>" readonly>
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="text" name="end_date" id="end_date" value="<?= $end_date ?>" readonly>
                </div>
            </div>
            <div class="button-row">
                <div class="view-toggle">
                    <button type="submit" name="view" value="list" class="btn-view <?= $view_mode == 'list' ? 'active' : '' ?>">List</button>
                    <button type="submit" name="view" value="calendar" class="btn-view <?= $view_mode == 'calendar' ? 'active' : '' ?>">Calendar</button>
                </div>
                <button type="submit" class="btn-filter">Go</button>
            </div>
        </div>
    </form>

    <div class="stats-bar">
        <div class="stat-item">
            <span class="label">Classes</span>
            <span class="value"><?= $grand_total_classes ?></span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <span class="label">Hours</span>
            <span class="value"><?= number_format($grand_total_hours, 1) ?></span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
            <span class="label">Total Payout</span>
            <span class="value money">$<?= number_format($grand_total_pay, 2) ?></span>
        </div>
    </div>

    <?php if ($view_mode === 'list'): ?>

    <?php foreach ($locations_data as $loc_id => $data): ?>
        <div class="location-section">
            <div class="loc-header">
                <div class="loc-header-content">
                    <span><?= $data['name'] ?></span>
                    <span class="loc-summary">
                        <span class="summary-badge">Reg: $<?= number_format($data['totals']['reg_pay'], 2) ?></span>
                        <span class="summary-badge">Priv: $<?= number_format($data['totals']['priv_pay'], 2) ?></span>
                        <span class="summary-badge total">$<?= number_format($data['totals']['reg_pay'] + $data['totals']['priv_pay'], 2) ?></span>
                    </span>
                </div>
            </div>

            <?php if (!empty($data['reg'])): ?>
                <div class="sub-header">Regular Classes</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Class</th>
                                <th>Role</th>
                                <th>Hours</th>
                                <th style="text-align:right">Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['reg'] as $rc): ?>
                                <tr>
                                    <td><?= date('M d', strtotime($rc['class_date'])) ?></td>
                                    <td><?= e($rc['class_name']) ?></td>
                                    <td><span class="badge-role"><?= ucfirst($rc['position']) ?></span></td>
                                    <td><?= number_format($rc['hours'], 1) ?></td>
                                    <td class="money-cell">$<?= number_format($rc['pay'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['priv'])): ?>
                <div class="sub-header sub-header-private">Private Classes / Activities</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student / Activity</th>
                                <th>Time</th>
                                <th></th>
                                <th style="text-align:right">Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['priv'] as $pc): ?>
                                <tr>
                                    <td><?= date('M d', strtotime($pc['class_date'])) ?></td>
                                    <td><?= e($pc['student_name']) ?></td>
                                    <td><?= $pc['class_time'] ? date('g:i A', strtotime($pc['class_time'])) : '-' ?></td>
                                    <td><?php if (!empty($pc['notes'])): ?><span class="note-icon" onclick="showNote(this)" data-note="<?= e($pc['notes']) ?>"><i class="fas fa-sticky-note"></i></span><?php endif; ?></td>
                                    <td class="money-cell">$<?= number_format($pc['payout'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php else: ?>
        <?php
        // Build activities array for calendar view
        $all_activities = [];

        // Add regular classes
        foreach ($regular_classes as $rc) {
            $hours = (float)$rc['hours'];
            if ($hours < 1) $hours = 1.0;
            $rate = ($rc['position'] === 'head') ? $coach['rate_head_coach'] : $coach['rate_helper'];
            $pay = $hours * $rate;

            $all_activities[] = [
                'type' => 'regular',
                'date' => $rc['class_date'],
                'time' => date('g:i A', strtotime($rc['start_time'])),
                'desc' => strtoupper($rc['martial_art']) . ' - ' . $rc['class_name'],
                'location' => $rc['location_name'],
                'pay' => $pay
            ];
        }

        // Add private classes
        foreach ($private_classes as $pc) {
            $all_activities[] = [
                'type' => 'private',
                'date' => $pc['class_date'],
                'time' => $pc['class_time'] ? date('g:i A', strtotime($pc['class_time'])) : '-',
                'desc' => $pc['student_name'],
                'location' => $pc['location_name'],
                'pay' => $pc['payout']
            ];
        }

        // Group activities by date
        $activities_by_date = [];
        foreach ($all_activities as $act) {
            $activities_by_date[$act['date']][] = $act;
        }

        // Sort activities within each day by time
        foreach ($activities_by_date as $date => &$acts) {
            usort($acts, function($a, $b) {
                return strcmp($a['time'], $b['time']);
            });
        }
        unset($acts);

        // Calculate calendar range based on start_date
        $cal_start = new DateTime($start_date);
        $cal_start->modify('first day of this month');
        $cal_end = new DateTime($start_date);
        $cal_end->modify('last day of this month');

        // Adjust to start on Sunday
        $first_day_of_week = (int)$cal_start->format('w');
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
            $day_count = 0;
            while ($current <= $cal_end):
                $day_count++;
                $date_str = $current->format('Y-m-d');
                $is_other_month = (int)$current->format('n') !== $current_month;
                $is_today = $date_str === $today;
                $day_activities = $activities_by_date[$date_str] ?? [];
                
                // Calculate day total safely
                $day_total = 0;
                foreach ($day_activities as $act) {
                    $day_total += isset($act['pay']) ? (float)$act['pay'] : 0;
                }

                // Group by location
                $by_location = [];
                foreach ($day_activities as $act) {
                    $loc = $act['location'] ?? 'Unknown';
                    $by_location[$loc][] = $act;
                }

                $classes = ['calendar-day'];
                if ($is_other_month) $classes[] = 'other-month';
                if ($is_today) $classes[] = 'today';
                $day_name = $current->format('D'); // Mon, Tue, Wed, etc.
            ?>
                <div class="<?= implode(' ', $classes) ?>">
                    <div class="day-number" data-day-name="<?= $day_name ?>"><?= $current->format('j') ?></div>
                    <?php if (!empty($by_location)): ?>
                        <div class="day-activities">
                            <?php foreach ($by_location as $loc_name => $acts): ?>
                                <div class="location-group">
                                    <div class="location-label"><?= e($loc_name) ?></div>
                                    <?php foreach ($acts as $act): ?>
                                        <div class="activity-item <?= $act['type'] ?>">
                                            <span class="activity-time"><?= $act['time'] ?></span>
                                            <span class="activity-desc"><?= e($act['desc']) ?></span>
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

    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');

    // Helper to format date as YYYY-MM-DD
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Calculate preset dates
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
                const dayOfWeek = today.getDay(); // 0 = Sunday
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

    // Create preset buttons container
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

    // Flatpickr config
    const fpConfig = {
        dateFormat: 'Y-m-d',
        locale: {
            firstDayOfWeek: 0 // Sunday
        },
        onReady: function(selectedDates, dateStr, instance) {
            instance.calendarContainer.appendChild(createPresetButtons(instance));
        }
    };

    // Initialize both pickers
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

    // Set initial constraints
    if (startInput.value) {
        endPicker.set('minDate', startInput.value);
    }
    if (endInput.value) {
        startPicker.set('maxDate', endInput.value);
    }
});

// Note popup functionality
function showNote(element) {
    const note = element.getAttribute('data-note');
    document.getElementById('noteContent').textContent = note;
    document.getElementById('notePopupOverlay').classList.add('active');
}

function closeNotePopup() {
    document.getElementById('notePopupOverlay').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('notePopupOverlay');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeNotePopup();
            }
        });
    }
});
</script>

<!-- Note Popup -->
<div class="note-popup-overlay" id="notePopupOverlay">
    <div class="note-popup">
        <div class="note-popup-header">
            <h4><i class="fas fa-sticky-note"></i> Note</h4>
            <button class="note-popup-close" onclick="closeNotePopup()">&times;</button>
        </div>
        <div class="note-popup-content" id="noteContent"></div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>