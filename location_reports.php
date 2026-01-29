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

        input[type="date"],
        select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }

        button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
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

        @media print {

            .controls,
            .top-bar {
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
            <input type="date" name="start" value="<?= $start_date ?>">
        </div>
        <div class="form-group">
            <label>End Date</label>
            <input type="date" name="end" value="<?= $end_date ?>">
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
                <button type="submit" name="view" value="summary" class="btn-outline <?= $view_mode == 'summary' ? 'active' : '' ?>">Summary View</button>
                <button type="submit" name="view" value="detailed" class="btn-outline <?= $view_mode == 'detailed' ? 'active' : '' ?>">Detailed View</button>
            </div>
        </div>
        <button type="submit" class="btn-blue">Apply Filters</button>
    </form>

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

    <?php else: ?>

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

    <?php endif; ?>

<?php require_once 'includes/footer.php'; ?>