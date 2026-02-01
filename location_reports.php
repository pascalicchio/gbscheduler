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
    SELECT ea.user_id, ea.class_date, ea.position, ct.class_name, ct.start_time, ct.end_time, l.name as loc_name, l.id as location_id,
           TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
    FROM event_assignments ea
    JOIN class_templates ct ON ea.template_id = ct.id
    JOIN locations l ON ct.location_id = l.id
    WHERE ea.class_date BETWEEN :start AND :end
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
        'desc' => $row['class_name'],
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
        body {
            font-family: 'Segoe UI', sans-serif;
            padding: 20px;
            background-color: #f4f6f9;
            color: #333;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .back-link {
            text-decoration: none;
            color: #666;
            font-weight: bold;
            font-size: 0.9em;
        }

        .controls {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }

        .form-group label {
            font-size: 0.8em;
            font-weight: bold;
            margin-bottom: 4px;
            color: #555;
            text-transform: uppercase;
        }

        input[type="text"],
        select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
            height: 35px;
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
            height: auto;
        }

        .flatpickr-presets button:hover {
            background: #e9ecef;
            border-color: #007bff;
        }

        button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            height: 35px;
            box-sizing: border-box;
        }

        .btn-blue {
            background: #007bff;
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid #ccc;
            color: #555;
        }

        .btn-outline.active {
            background: #2c3e50;
            color: white;
            border-color: #2c3e50;
        }

        .loc-block {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
            overflow: hidden;
            border: 1px solid #e1e4e8;
        }

        .loc-header {
            background: #2c3e50;
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }

        .sum-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sum-table th {
            background: #f8f9fa;
            text-align: left;
            padding: 10px 20px;
            border-bottom: 2px solid #e9ecef;
            font-size: 0.85em;
            color: #777;
            text-transform: uppercase;
        }

        .sum-table th.text-right {
            text-align: right;
        }

        .sum-table td {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .col-money {
            text-align: right;
            font-weight: bold;
            color: #28a745;
            font-family: 'Segoe UI', sans-serif;
        }

        .col-total {
            color: #28a745;
            font-size: 1.1em;
        }

        .global-summary {
            background: #2c3e50;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .global-summary h3 {
            margin: 0 0 5px 0;
            font-size: 1em;
            opacity: 0.8;
            font-weight: normal;
            color: white !important;
        }

        .global-summary .big-number {
            font-size: 1.8em;
            font-weight: bold;
        }

        .coach-card {
            background: white;
            border: 1px solid #e1e4e8;
            border-left: 5px solid #007bff;
            margin-bottom: 30px;
            padding: 0;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .coach-header {
            padding: 12px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
        }

        .coach-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.25em;
        }

        .coach-stats {
            display: flex;
            gap: 8px;
        }

        .header-badge {
            background: #f8f9fa;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            color: #666;
            border: 1px solid #e9ecef;
            font-weight: 500;
        }

        .header-badge.total {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
            font-weight: 700;
        }

        /* LOCATION SUB-HEADER INSIDE DETAILED VIEW */
        .loc-sub-header {
            background: #f1f3f5;
            padding: 8px 15px;
            font-size: 0.9em;
            font-weight: 700;
            color: #495057;
            border-top: 1px solid #dee2e6;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
        }

        .loc-total-badge {
            color: #28a745;
            font-weight: bold;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
            table-layout: fixed;
        }

        .detail-table th {
            background: #f8f9fa;
            text-align: left;
            padding: 10px 15px;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-size: 0.85em;
            text-transform: uppercase;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .detail-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            color: #333;
            vertical-align: middle;
        }

        .w-date {
            width: 15%;
        }

        .w-time {
            width: 10%;
        }

        .w-type {
            width: 15%;
        }

        .w-det {
            width: 30%;
        }

        /* Increased width since location column is gone */
        .w-rate {
            width: 10%;
        }

        .w-qty {
            width: 10%;
        }

        .w-tot {
            width: 10%;
        }

        .text-right {
            text-align: right !important;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-reg {
            background-color: #e3f2fd;
            color: #1565c0;
        }

        .badge-priv {
            background-color: #fff3e0;
            color: #e65100;
        }

        .pdf-only {
            display: none;
        }

        /* Calendar View Styles */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #dee2e6;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .calendar-header {
            background: #2c3e50;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-weight: bold;
            font-size: 0.85em;
        }

        .calendar-day {
            background: white;
            min-height: 120px;
            padding: 8px;
            vertical-align: top;
        }

        .calendar-day.other-month {
            background: #f8f9fa;
        }

        .calendar-day.today {
            background: #fff3cd;
        }

        .day-number {
            font-weight: bold;
            font-size: 0.9em;
            color: #495057;
            margin-bottom: 6px;
        }

        .day-activities {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .activity-item {
            font-size: 0.75em;
            padding: 4px 6px;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 4px;
        }

        .activity-item.regular {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 3px solid #1565c0;
        }

        .activity-item.private {
            background: #fff3e0;
            color: #e65100;
            border-left: 3px solid #e65100;
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
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid #eee;
            font-size: 0.8em;
            font-weight: bold;
            color: #28a745;
            text-align: right;
        }

        .location-group {
            margin-bottom: 4px;
        }

        .location-group:last-child {
            margin-bottom: 0;
        }

        .location-label {
            font-size: 0.65em;
            font-weight: bold;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 2px;
            padding: 2px 4px;
            background: #e9ecef;
            border-radius: 2px;
        }

        .btn-outline:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        @media (max-width: 900px) {
            .calendar-grid {
                font-size: 0.85em;
            }

            .calendar-day {
                min-height: 80px;
                padding: 4px;
            }

            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1px;
            }

            .activity-desc {
                display: none;
            }
        }

        @media (max-width: 600px) {
            .calendar-header {
                padding: 8px 4px;
                font-size: 0.7em;
            }

            .calendar-day {
                min-height: 60px;
            }

            .day-number {
                font-size: 0.8em;
            }

            .activity-item {
                padding: 2px 4px;
                font-size: 0.7em;
            }

            .activity-time {
                display: none;
            }

            .day-total {
                font-size: 0.7em;
            }
        }

        .btn-pdf {
            background: #dc3545;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-pdf:hover {
            background: #c82333;
        }

        .btn-pdf:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        @media print {

            .controls,
            .top-bar,
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

    <div class="top-bar">
        <a href="dashboard.php" class="back-link">&larr; Back to Dashboard</a>
        <h2 style="margin:0; color:#2c3e50;">Payroll Report</h2>
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
        <div class="form-group" style="flex:1"></div>
        <div class="form-group">
            <label>View Mode</label>
            <div style="display:flex; gap:5px;">
                <button type="submit" name="view" value="summary" class="btn-outline <?= $view_mode == 'summary' ? 'active' : '' ?>">Summary</button>
                <button type="submit" name="view" value="detailed" class="btn-outline <?= $view_mode == 'detailed' ? 'active' : '' ?>">Detailed</button>
                <button type="submit" name="view" value="calendar" id="btn-calendar" class="btn-outline <?= $view_mode == 'calendar' ? 'active' : '' ?>" <?= empty($filter_coach_id) ? 'disabled title="Select a coach first"' : '' ?>>Calendar</button>
            </div>
        </div>
        <div class="form-group">
            <button type="submit" class="btn-blue">Apply Filters</button>
        </div>
    </form>

    <!-- Global Summary Header (shown in both views) -->
    <div class="global-summary">
        <div style="text-align: left;">
            <h3>Period</h3>
            <div style="font-size: 1.1em; font-weight: bold;"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></div>
        </div>
        <div>
            <h3>Total Hours</h3>
            <div class="big-number"><?= number_format($grand_total_hours, 1) ?></div>
        </div>
        <div style="text-align: right;">
            <h3>Total Payroll</h3>
            <div class="big-number">$<?= number_format($grand_total_pay, 2) ?></div>
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
                            <th class="text-right" style="color:#666">Reg. Pay</th>
                            <th class="text-right" style="color:#666">Priv. Pay</th>
                            <th class="text-right">Total</th>
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
                                <td class="col-money" style="color:#666">$<?= number_format($loc_reg_pay, 2) ?></td>
                                <td class="col-money" style="color:#666">$<?= number_format($loc_priv_pay, 2) ?></td>
                                <td class="col-money col-total">$<?= number_format($loc_total_coach, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f1f3f5; font-weight:bold;">
                            <td colspan="4" style="text-align:right;">LOCATION TOTAL:</td>
                            <td class="col-money col-total" style="font-size:1.2em;">$<?= number_format($loc_total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php $html = ob_get_clean();
            if ($has_data) echo $html; ?>
        <?php endforeach; ?>

    <?php elseif ($view_mode === 'detailed'): ?>

        <div style="margin-bottom: 20px; text-align: right;">
            <button type="button" class="btn-pdf" onclick="exportPDF()" id="pdf-btn">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
        </div>

        <div id="pdf-content">
        <!-- PDF Header (copy of global summary for PDF - hidden on screen) -->
        <div class="global-summary pdf-only" style="margin-bottom: 25px;">
            <div style="text-align: left;">
                <h3>Period</h3>
                <div style="font-size: 1.1em; font-weight: bold;"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></div>
            </div>
            <div>
                <h3>Total Hours</h3>
                <div class="big-number"><?= number_format($grand_total_hours, 1) ?></div>
            </div>
            <div style="text-align: right;">
                <h3>Total Payroll</h3>
                <div class="big-number">$<?= number_format($grand_total_pay, 2) ?></div>
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
            <div class="coach-card">
                <div class="coach-header">
                    <h3><?= e($data['info']['name']) ?></h3>
                    <div class="coach-stats">
                        <span class="header-badge">Reg: $<?= number_format($data['regular_pay'], 2) ?></span>
                        <span class="header-badge">Priv: $<?= number_format($data['private_pay'], 2) ?></span>
                        <span class="header-badge total">Total: $<?= number_format($data['total_pay'], 2) ?></span>
                    </div>
                </div>

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
                                <th class="w-rate text-right">Rate</th>
                                <th class="w-qty text-right">Qty</th>
                                <th class="w-tot text-right">Total</th>
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
                                    <td class="text-right"><?= ($act['rate'] == '-' || $act['rate'] == 0) ? '-' : '$' . number_format($act['rate'], 2) ?></td>
                                    <td class="text-right"><?= $act['qty'] ?></td>
                                    <td class="text-right" style="font-weight:bold; color:#28a745">$<?= number_format($act['pay'], 2) ?></td>
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
            $first_day_of_week = (int)$cal_start->format('w'); // 0 = Sunday
            $cal_start->modify("-{$first_day_of_week} days");

            // Adjust to end on Saturday
            $last_day_of_week = (int)$cal_end->format('w');
            $days_to_add = 6 - $last_day_of_week;
            $cal_end->modify("+{$days_to_add} days");

            $current_month = date('n', strtotime($start_date));
            $today = date('Y-m-d');
        ?>

        <div class="coach-card" style="margin-bottom: 20px;">
            <div class="coach-header">
                <h3><i class="fas fa-calendar-alt"></i> <?= e($coach_data['info']['name']) ?> - <?= date('F Y', strtotime($start_date)) ?></h3>
                <div class="coach-stats">
                    <span class="header-badge">Reg: $<?= number_format($coach_data['regular_pay'], 2) ?></span>
                    <span class="header-badge">Priv: $<?= number_format($coach_data['private_pay'], 2) ?></span>
                    <span class="header-badge total">Total: $<?= number_format($coach_data['total_pay'], 2) ?></span>
                </div>
            </div>
        </div>

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
                                            <span class="activity-pay">$<?= number_format($act['pay'], 0) ?></span>
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
            <div class="alert" style="background:#fff3cd; padding:20px; border-radius:8px; text-align:center;">
                <i class="fas fa-exclamation-triangle"></i> No data found for the selected coach.
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