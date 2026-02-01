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
            padding: 20px;
            color: #333;
            margin: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .btn-back {
            padding: 10px 15px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }

        .filter-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            font-size: 0.8em;
            margin-bottom: 5px;
            text-transform: uppercase;
            color: #777;
        }

        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 35px;
            box-sizing: border-box;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            height: 35px;
            box-sizing: border-box;
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

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .page-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .page-header h2 {
                font-size: 1.3em;
            }

            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .form-group {
                width: 100%;
            }

            .form-group input,
            .form-group select {
                width: 100%;
            }

            .btn-filter {
                width: 100%;
                margin-top: 5px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .stat-card .value {
                font-size: 1.8em;
            }

            .loc-header {
                flex-direction: column;
                gap: 5px;
            }

            .loc-summary {
                font-size: 0.8em;
            }

            table {
                font-size: 0.85em;
            }

            th, td {
                padding: 8px 10px;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            border: 1px solid #e1e4e8;
        }

        .stat-card h3 {
            margin: 0 0 5px 0;
            font-size: 0.85em;
            text-transform: uppercase;
            color: #777;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 2.2em;
            font-weight: 700;
            color: #2c3e50;
        }

        .money {
            color: #28a745;
        }

        .location-section {
            margin-bottom: 40px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #e1e4e8;
        }

        .loc-header {
            background: #2c3e50;
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            font-size: 1.1em;
        }

        .loc-summary {
            font-size: 0.9em;
            opacity: 0.9;
            font-weight: normal;
        }

        .loc-summary strong {
            color: #81ffb3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #fff;
            color: #999;
            font-size: 0.8em;
            text-transform: uppercase;
            border-bottom: 2px solid #eee;
        }

        .money-cell {
            font-family: monospace;
            font-weight: bold;
            color: #28a745;
            text-align: right;
        }

        .sub-header {
            background: #f8f9fa;
            padding: 8px 20px;
            font-weight: bold;
            font-size: 0.8em;
            text-transform: uppercase;
            color: #555;
            border-bottom: 1px solid #eee;
            margin-top: 0;
        }

        .badge-role {
            font-size: 0.8em;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            background: #e2e6ea;
            color: #555;
        }

        /* View Mode Buttons */
        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .btn-view {
            padding: 8px 15px;
            border: 1px solid #ccc;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85em;
            color: #555;
        }

        .btn-view.active {
            background: #2c3e50;
            color: white;
            border-color: #2c3e50;
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
CSS;

require_once 'includes/header.php';
?>

    <div class="page-header">
        <h2 style="margin:0; color:#2c3e50;">Payroll Report: <?= e($coach['name']) ?></h2>
        <a href="dashboard.php" class="btn-back">Back to Schedule</a>
    </div>

    <form class="filter-card" method="GET" id="reportForm">
        <div class="filter-row">
            <?php if ($is_admin): ?>
                <div class="form-group">
                    <label>Select Coach</label>
                    <select name="coach_id">
                        <?php foreach ($all_coaches as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($target_user_id == $c['id']) ? 'selected' : '' ?>><?= $c['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Start Date</label>
                <input type="text" name="start_date" id="start_date" value="<?= $start_date ?>" readonly>
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="text" name="end_date" id="end_date" value="<?= $end_date ?>" readonly>
            </div>
            <div class="form-group">
                <label>View</label>
                <div class="view-toggle">
                    <button type="submit" name="view" value="list" class="btn-view <?= $view_mode == 'list' ? 'active' : '' ?>">List</button>
                    <button type="submit" name="view" value="calendar" class="btn-view <?= $view_mode == 'calendar' ? 'active' : '' ?>">Calendar</button>
                </div>
            </div>
            <div class="form-group">
                <button type="submit" class="btn-filter">Generate Report</button>
            </div>
        </div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Classes</h3>
            <div class="value"><?= $grand_total_classes ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Regular Hours</h3>
            <div class="value"><?= number_format($grand_total_hours, 1) ?> <small style="font-size:0.4em; color:#999; vertical-align:middle;">HRS</small></div>
        </div>
        <div class="stat-card">
            <h3>Total Payout</h3>
            <div class="value money">$<?= number_format($grand_total_pay, 2) ?></div>
        </div>
    </div>

    <?php if ($view_mode === 'list'): ?>

    <?php foreach ($locations_data as $loc_id => $data): ?>
        <div class="location-section">
            <div class="loc-header">
                <span><i class="fas fa-map-marker-alt"></i> <?= $data['name'] ?></span>
                <span class="loc-summary">Regular: $<?= number_format($data['totals']['reg_pay'], 2) ?> &nbsp;|&nbsp; Privates: $<?= number_format($data['totals']['priv_pay'], 2) ?> &nbsp;|&nbsp; <strong>Total: $<?= number_format($data['totals']['reg_pay'] + $data['totals']['priv_pay'], 2) ?></strong></span>
            </div>

            <?php if (!empty($data['reg'])): ?>
                <div class="sub-header">Regular Classes</div>
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
            <?php endif; ?>

            <?php if (!empty($data['priv'])): ?>
                <div class="sub-header">Private Classes / Activities</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student / Activity</th>
                            <th>Time</th>
                            <th style="text-align:right">Payout</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['priv'] as $pc): ?>
                            <tr>
                                <td><?= date('M d', strtotime($pc['class_date'])) ?></td>
                                <td><?= e($pc['student_name']) ?></td>
                                <td><?= $pc['class_time'] ? date('g:i A', strtotime($pc['class_time'])) : '-' ?></td>
                                <td class="money-cell">$<?= number_format($pc['payout'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
</script>

<?php require_once 'includes/footer.php'; ?>