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
    SELECT ea.class_date, ea.position, ct.class_name, ct.start_time, ct.end_time, l.id as location_id, l.name as location_name,
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
        :root {
            --primary: #007bff;
            --bg: #f4f6f9;
        }

        body {
            font-family: sans-serif;
            background: var(--bg);
            padding: 15px;
            color: #333;
            margin: 0;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 10px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 1.1em;
            color: #2c3e50;
        }

        .btn-back {
            padding: 8px 12px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85em;
            white-space: nowrap;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
        }

        .filter-row {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .date-inputs-row {
            display: flex;
            gap: 10px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            font-size: 0.7em;
            margin-bottom: 4px;
            text-transform: uppercase;
            color: #777;
        }

        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 38px;
            box-sizing: border-box;
            font-size: 16px;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            height: 38px;
            box-sizing: border-box;
        }

        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .btn-view {
            flex: 1;
            padding: 0 12px;
            border: 1px solid #ccc;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85em;
            color: #555;
            height: 38px;
            box-sizing: border-box;
        }

        .btn-view.active {
            background: #2c3e50;
            color: white;
            border-color: #2c3e50;
        }

        /* Button row for mobile */
        .button-row {
            display: flex;
            gap: 10px;
        }

        .button-row .view-toggle {
            flex: 1;
        }

        .button-row .btn-filter {
            flex: 0 0 auto;
            width: auto;
            padding: 0 25px;
        }

        /* Flatpickr preset buttons */
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
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75em;
            transition: all 0.2s;
        }

        .flatpickr-presets button:hover {
            background: #e9ecef;
            border-color: #007bff;
        }

        /* Stats Summary - Compact Horizontal Bar */
        .stats-bar {
            background: white;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-item .label {
            font-size: 0.75em;
            color: #777;
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-item .value {
            font-size: 1.1em;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-item .value.money {
            color: #28a745;
            font-size: 1.3em;
        }

        .stat-divider {
            width: 1px;
            height: 30px;
            background: #e1e4e8;
        }

        /* Location Sections */
        .location-section {
            margin-bottom: 15px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .loc-header {
            background: #2c3e50;
            color: white;
            padding: 10px 15px;
            font-weight: bold;
            font-size: 0.95em;
        }

        .loc-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .loc-summary {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .summary-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            background: rgba(255,255,255,0.15);
        }

        .summary-badge.total {
            background: #81ffb3;
            color: #2c3e50;
        }

        .sub-header {
            background: #f8f9fa;
            padding: 8px 15px;
            font-weight: bold;
            font-size: 0.75em;
            text-transform: uppercase;
            color: #555;
            border-bottom: 1px solid #eee;
        }

        .sub-header-private {
            background: #e3f2fd;
            color: #1565c0;
        }

        /* Note icon */
        .note-icon {
            cursor: pointer;
            color: #1565c0;
            font-size: 1.1em;
            padding: 4px;
        }

        .note-icon:hover {
            color: #0d47a1;
        }

        /* Note popup */
        .note-popup-overlay {
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

        .note-popup-overlay.active {
            display: flex;
        }

        .note-popup {
            background: white;
            padding: 20px;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .note-popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .note-popup-header h4 {
            margin: 0;
            color: #1565c0;
            font-size: 1em;
        }

        .note-popup-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #999;
            line-height: 1;
        }

        .note-popup-close:hover {
            color: #333;
        }

        .note-popup-content {
            color: #333;
            line-height: 1.5;
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
            font-size: 0.9em;
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #fff;
            color: #999;
            font-size: 0.75em;
            text-transform: uppercase;
            border-bottom: 2px solid #eee;
            white-space: nowrap;
        }

        .money-cell {
            font-family: monospace;
            font-weight: bold;
            color: #28a745;
            text-align: right;
            font-size: 1.1em;
        }

        .badge-role {
            font-size: 0.75em;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            background: #e2e6ea;
            color: #555;
        }

        /* Calendar View */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15px;
        }

        .calendar-header {
            background: #2c3e50;
            color: white;
            padding: 10px 4px;
            text-align: center;
            font-weight: bold;
            font-size: 0.75em;
        }

        .calendar-day {
            background: white;
            min-height: 90px;
            padding: 6px;
        }

        .calendar-day.other-month {
            background: #fafafa;
        }

        .calendar-day.today {
            background: #fff3cd;
        }

        .day-number {
            font-weight: bold;
            font-size: 0.85em;
            color: #495057;
            margin-bottom: 4px;
        }

        .day-activities {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .activity-item {
            font-size: 0.7em;
            padding: 3px 5px;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 3px;
        }

        .activity-item.regular {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 2px solid #1565c0;
        }

        .activity-item.private {
            background: #fff3e0;
            color: #e65100;
            border-left: 2px solid #e65100;
        }

        .activity-time {
            font-weight: 600;
            white-space: nowrap;
        }

        .activity-desc {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .activity-pay {
            font-weight: bold;
            white-space: nowrap;
        }

        .day-total {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px solid #eee;
            font-size: 0.7em;
            font-weight: bold;
            color: #28a745;
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
            margin-bottom: 1px;
            padding: 1px 3px;
            background: #e9ecef;
            border-radius: 2px;
        }

        /* Desktop Enhancements */
        @media (min-width: 600px) {
            body {
                padding: 20px;
            }

            .page-header h2 {
                font-size: 1.3em;
            }

            .filter-row {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: flex-end;
                gap: 12px;
            }

            .date-inputs-row {
                flex: 0 0 auto;
            }

            .form-group {
                flex: 0 0 auto;
            }

            .form-group.coach-select {
                min-width: 150px;
            }

            .button-row {
                display: flex;
                gap: 8px;
                align-items: flex-end;
            }

            .button-row .view-toggle {
                flex: 0 0 auto;
            }

            .btn-filter {
                width: auto;
                padding: 0 20px;
            }

            .stats-bar {
                padding: 15px 20px;
            }

            .stat-item .value {
                font-size: 1.2em;
            }

            .stat-item .value.money {
                font-size: 1.5em;
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

            .page-header h2 {
                font-size: 0.95em;
            }

            .btn-back {
                padding: 6px 10px;
                font-size: 0.8em;
            }

            .stats-bar {
                padding: 10px;
                gap: 8px;
            }

            .stat-item .label {
                font-size: 0.65em;
            }

            .stat-item .value {
                font-size: 1em;
            }

            .stat-item .value.money {
                font-size: 1.15em;
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
                display: block;
                background: transparent;
                border: none;
                gap: 0;
            }

            .calendar-day {
                display: block;
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
                font-size: 1.1em;
                margin-bottom: 10px;
                padding-bottom: 8px;
                border-bottom: 2px solid #e9ecef;
                color: #2c3e50;
            }

            .day-number::before {
                content: attr(data-day-name) ", ";
                font-weight: 600;
                color: #495057;
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
        <h2>Payroll: <?= e($coach['name']) ?></h2>
        <a href="dashboard.php" class="btn-back">Back</a>
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
        <!-- DEBUG: Calendar View Active -->
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
                'desc' => $rc['class_name'],
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
        
        // DEBUG
        echo "<!-- DEBUG: Activities count: " . count($all_activities) . " -->\n";
        echo "<!-- DEBUG: Cal start: " . $cal_start->format('Y-m-d') . " -->\n";
        echo "<!-- DEBUG: Cal end: " . $cal_end->format('Y-m-d') . " -->\n";
        echo "<!-- DEBUG: Current month: " . $current_month . " -->\n";
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
                $day_total = array_sum(array_column($day_activities, 'pay'));

                // Group by location
                $by_location = [];
                foreach ($day_activities as $act) {
                    $by_location[$act['location']][] = $act;
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
            echo "<!-- DEBUG: Total days rendered: " . $day_count . " -->\n";
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