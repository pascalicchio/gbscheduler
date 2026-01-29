<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require 'db.php';
date_default_timezone_set('America/New_York');

$user_role = $_SESSION['user_role'] ?? 'guest';
$user_id = $_SESSION['user_id'];

// PERMISSIONS
$is_admin = ($user_role === 'admin');
$is_manager = ($user_role === 'manager');
$is_coach = ($user_role === 'user');

// "Can Manage" = Access to sidebar tools
$can_view_tools = ($is_admin || $is_manager);

// 1. Fetch Locations
try {
    $stmt_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
    $locations = $stmt_locations->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $locations = [];
}

// Filters
$default_location_id = !empty($locations) ? $locations[0]['id'] : '0';
$filter_location_id = $_GET['location'] ?? $default_location_id;
$martial_art_filter = $_GET['martial_art'] ?? 'bjj';

// Dates
$requested_date = $_GET['week_start'] ?? date('Y-m-d');
$start_of_week = date('Y-m-d', strtotime('monday this week', strtotime($requested_date)));
$end_of_week = date('Y-m-d', strtotime('sunday this week', strtotime($requested_date)));
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$prev_week_start = date('Y-m-d', strtotime($start_of_week . ' - 7 days'));
$next_week_start = date('Y-m-d', strtotime($start_of_week . ' + 7 days'));
$base_url = 'dashboard.php';
$location_param = ($filter_location_id !== '0') ? '&location=' . urlencode($filter_location_id) : '';
$martial_art_param = ($martial_art_filter !== 'all') ? '&martial_art=' . urlencode($martial_art_filter) : '';

// --- NEW LOCK LOGIC ---
$stmt_lock = $pdo->prepare("SELECT is_locked FROM schedule_locks WHERE location_id = ? AND martial_art = ? AND week_start = ?");
$stmt_lock->execute([$filter_location_id, $martial_art_filter, $start_of_week]);
$is_locked_db = $stmt_lock->fetchColumn();
$is_locked = $is_locked_db ? true : false;

$current_location_name = "Schedule";
if (!empty($locations)) {
    foreach ($locations as $loc) {
        if ((string)$loc['id'] === (string)$filter_location_id) {
            $current_location_name = $loc['name'];
            break;
        }
    }
}

$art_display = ($martial_art_filter === 'mt') ? 'Muay Thai' : 'Jiu-Jitsu';

// 2. Fetch Coaches (ONLY needed for Admin sidebar now)
// ADDED: is_active filter
$coaches = [];
if ($is_admin) {
    $coach_sql = "SELECT u.id, u.name, u.color_code 
                  FROM users u 
                  WHERE u.role != 'manager' 
                  AND u.is_active = 1 "; // <-- ADDED THIS LINE
    $coach_params = [];

    if ($filter_location_id !== '0') {
        $coach_sql .= " AND EXISTS (SELECT 1 FROM user_locations ul WHERE ul.user_id = u.id AND ul.location_id = :location_id) ";
        $coach_params['location_id'] = $filter_location_id;
    }

    if ($martial_art_filter === 'bjj') {
        $coach_sql .= " AND u.coach_type IN ('bjj', 'both') ";
    } elseif ($martial_art_filter === 'mt') {
        $coach_sql .= " AND u.coach_type IN ('mt', 'both') ";
    }

    try {
        $stmt_coaches = $pdo->prepare($coach_sql . " ORDER BY u.name ASC");
        $stmt_coaches->execute($coach_params);
        $coaches = $stmt_coaches->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
    }
}

// --- DATA FETCHING ---
function get_schedule_data($pdo, $location_id, $start_date, $end_date, $user_role, $user_id, $martial_art_filter)
{
    $days_of_week_internal = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $days_map = array_flip($days_of_week_internal);
    $start_timestamp = strtotime($start_date);

    $params = [];
    $where = ["1=1"];
    $sql = "SELECT id AS template_id, class_name, day_of_week, start_time, end_time, location_id FROM class_templates";

    if ($location_id !== '0') {
        $where[] = "location_id = :location_id";
        $params['location_id'] = $location_id;
    }
    if ($martial_art_filter !== 'all') {
        $where[] = "martial_art = :martial_art_filter";
        $params['martial_art_filter'] = $martial_art_filter;
    }

    $sql .= " WHERE " . implode(' AND ', $where) . " ORDER BY start_time ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $schedule_grid = [];
    $required_assignments = [];

    foreach ($templates as $template) {
        $key = $template['start_time'] . '|' . $template['class_name'];
        if (!isset($schedule_grid[$key])) {
            $schedule_grid[$key] = [
                'start_time' => $template['start_time'],
                'class_title' => $template['class_name'],
                'data' => array_fill_keys($days_of_week_internal, null)
            ];
        }

        $day_name = $template['day_of_week'];
        if (!isset($days_map[$day_name])) continue;

        $day_index = $days_map[$day_name];
        $class_date = date('Y-m-d', strtotime("+$day_index days", $start_timestamp));

        $schedule_grid[$key]['data'][$day_name] = [
            'template_id' => $template['template_id'],
            'date' => $class_date,
            'end_time' => $template['end_time'],
            'coaches' => []
        ];
        $required_assignments[] = ['template_id' => $template['template_id'], 'class_date' => $class_date];
    }

    $assigned_coaches = [];
    if (!empty($required_assignments)) {
        $conds = [];
        $aparams = [];
        $i = 0;
        foreach ($required_assignments as $ra) {
            $conds[] = "(ea.template_id = :t$i AND ea.class_date = :d$i)";
            $aparams["t$i"] = $ra['template_id'];
            $aparams["d$i"] = $ra['class_date'];
            $i++;
        }

        if (!empty($conds)) {
            $asql = "SELECT ea.template_id, ea.class_date, ea.user_id, u.name AS coach_name, u.color_code, ea.position 
                     FROM event_assignments ea JOIN users u ON ea.user_id = u.id 
                     WHERE " . implode(' OR ', $conds) . " ORDER BY ea.sort_order ASC, ea.position";
            try {
                $stmt = $pdo->prepare($asql);
                $stmt->execute($aparams);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $assigned_coaches[$row['template_id'] . '|' . $row['class_date']][] = $row;
                }
            } catch (Exception $e) {
            }
        }
    }

    foreach ($schedule_grid as &$row) {
        foreach ($days_of_week_internal as $day) {
            if ($row['data'][$day]) {
                $tid = $row['data'][$day]['template_id'];
                $cdate = $row['data'][$day]['date'];
                $k = "$tid|$cdate";
                if (isset($assigned_coaches[$k])) {
                    $row['data'][$day]['coaches'] = $assigned_coaches[$k];
                }
            }
        }
    }
    unset($row);

    if ($user_role === 'user') {
        $final = [];
        foreach ($schedule_grid as $row) {
            $found = false;
            foreach ($row['data'] as $d) {
                if ($d && !empty($d['coaches'])) {
                    foreach ($d['coaches'] as $c) {
                        if ($c['user_id'] == $user_id) {
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            if ($found) $final[] = $row;
        }
        $schedule_grid = $final;
    } else {
        $schedule_grid = array_values($schedule_grid);
    }
    usort($schedule_grid, function ($a, $b) {
        return strtotime($a['start_time']) - strtotime($b['start_time']);
    });
    return $schedule_grid;
}

$schedule_data = get_schedule_data($pdo, $filter_location_id, $start_of_week, $end_of_week, $user_role, $user_id, $martial_art_filter);
?>

<!DOCTYPE html>
<html>

<head>
    <title>GB Schedule</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>

    <style>
        :root {
            --primary-color: #007bff;
            --primary-dark: #0056b3;
            --secondary-dark: #2c3e50;
            --bg-color: #f4f6f9;
            --sidebar-color: #ffffff;
            --text-color: #333;
        }

        body {
            display: flex;
            font-family: sans-serif;
            margin: 0;
            background: var(--bg-color);
            color: var(--text-color);
            height: 100vh;
            overflow: hidden;
        }

        #sidebar {
            width: 320px;
            padding: 20px;
            background: var(--sidebar-color);
            border-right: 1px solid #e1e4e8;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
            overflow-y: auto;
            flex-shrink: 0;
            z-index: 20;
            transition: all 0.3s ease;
        }

        .filter-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .filter-box label {
            font-size: 0.85em;
            font-weight: bold;
            color: #555;
            text-transform: uppercase;
            display: block;
            margin-bottom: 5px;
        }

        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-bottom: 10px;
            background: white;
        }

        /* Draggable Styles */
        .coaches-table-fi {
            width: 100%;
            border-collapse: separate;
            border-spacing: 5px 0;
        }

        .coach-draggable {
            border: 1px solid #ccc;
            padding: 8px;
            margin-bottom: 5px;
            cursor: grab;
            font-size: 0.9em;
            border-radius: 4px;
            transition: all 0.2s;
            background: white;
            text-align: center;
        }

        .coach-draggable:hover {
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        .coach-head {
            border: 2px solid;
            background: white;
        }

        .coach-helper {
            border: 1px dashed #ccc;
            background: #f0f0f0;
        }

        #calendar-container {
            flex: 1;
            padding: 25px;
            overflow: auto;
            position: relative;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .nav-btn {
            text-decoration: none;
            color: var(--secondary-dark);
            font-weight: bold;
            padding: 8px 16px;
            border-radius: 4px;
            background: #f0f0f0;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
            font-size: 1em;
        }

        .nav-btn:hover {
            background: #e0e0e0;
        }

        /* ADDED: Lock States */
        .btn-lock {
            background: #dc3545;
            color: white;
            margin-left: 15px;
        }

        .btn-unlock {
            background: #ffc107;
            color: #333;
            margin-left: 15px;
        }

        .locked-mode {
            pointer-events: none;
            opacity: 0.6;
            filter: grayscale(80%);
        }

        #custom-schedule {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            table-layout: fixed;
            overflow: hidden;
            height: 1px; /* Trick to make td height:100% work */
        }

        #custom-schedule th {
            background: var(--secondary-dark);
            color: white;
            padding: 15px;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        #custom-schedule tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        #custom-schedule tr:nth-child(odd) {
            background-color: #ffffff;
        }

        #custom-schedule td {
            border-bottom: 1px solid #e8ecf0;
            border-right: 1px solid #e8ecf0;
            vertical-align: top;
            padding: 6px;
            height: 100%;
        }

        #custom-schedule td > .slot {
            height: calc(100% - 0px);
            min-height: 70px;
        }

        .time-col {
            width: 140px;
            position: sticky;
            left: 0;
            z-index: 11;
            border-right: 2px solid #e1e4e8 !important;
            background-color: inherit;
        }

        .time-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70px;
            height: 100%;
            padding: 15px;
        }

        .time-text {
            font-size: 1.1em;
            font-weight: bold;
            color: var(--secondary-dark);
        }

        .class-text {
            font-size: 0.85em;
            color: #666;
            margin-top: 4px;
            text-align: center;
        }

        .bulk-btn {
            margin-top: 8px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            padding: 4px 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .bulk-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        .slot {
            height: 100%;
            min-height: 60px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background-color: #f8fafc;
            border: 2px dashed #d1d9e0;
            border-radius: 6px;
            transition: all 0.2s ease;
            position: relative;
            box-sizing: border-box;
        }

        .slot:empty::before {
            content: "Drop coach here";
            color: #a0aec0;
            font-size: 0.75em;
            text-align: center;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            white-space: nowrap;
        }

        .slot:hover {
            border-color: #93c5fd;
            background-color: #eff6ff;
        }

        .assignment {
            font-size: 0.85em;
            padding: 8px 10px;
            border-radius: 5px;
            background: #fff;
            border: 1px solid #e1e4e8;
            cursor: move;
            position: relative;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .assignment:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .del-btn {
            color: #e74c3c;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .ui-draggable-dragging {
            transition: none !important;
            transform: none !important;
            pointer-events: none;
            z-index: 999999 !important;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .ui-sortable-placeholder {
            border: 1px dashed #ccc;
            visibility: visible !important;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 4px;
            height: 35px;
        }

        .ui-state-hover {
            background: #dbeafe !important;
            border-color: var(--primary-color) !important;
            border-style: solid !important;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.15) !important;
        }

        .ui-state-hover::before {
            content: "Release to drop" !important;
            color: var(--primary-color) !important;
            font-weight: 600;
        }

        .sidebar-link {
            color: var(--secondary-dark);
            text-decoration: none;
            display: block;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            transition: color 0.2s;
        }

        .sidebar-link:hover {
            color: var(--primary-color);
            padding-left: 5px;
        }

        body.screenshot-mode {
            height: auto !important;
            overflow: visible !important;
            background-color: #fff !important;
        }

        body.screenshot-mode #sidebar {
            display: none !important;
        }

        body.screenshot-mode #calendar-container {
            height: auto !important;
            overflow: visible !important;
            position: static !important;
            flex: none !important;
            padding: 20px !important;
            width: 100% !important;
        }

        body.screenshot-mode .del-btn,
        body.screenshot-mode .bulk-btn,
        body.screenshot-mode .clone-dropdown,
        body.screenshot-mode #download-btn,
        body.screenshot-mode #lock-btn {
            /* HIDE LOCK BUTTON IN SCREENSHOT */
            display: none !important;
        }

        /* Clone Dropdown Styles */
        .clone-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 280px;
            z-index: 1000;
            margin-top: 5px;
            overflow: hidden;
        }

        .clone-dropdown-menu.show {
            display: block;
        }

        .clone-option {
            padding: 12px 16px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }

        .clone-option:last-child {
            border-bottom: none;
        }

        .clone-option:hover {
            background: #f8f9fa;
        }

        .clone-option i {
            margin-right: 10px;
            color: #007bff;
            width: 16px;
        }

        .clone-option-desc {
            display: block;
            font-size: 0.8em;
            color: #666;
            margin-top: 4px;
            margin-left: 26px;
        }
    </style>
</head>

<body>

    <div id="sidebar">
        <div class="filter-box">
            <label>Location</label>
            <select id="loc-filter">
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $l['id'] == $filter_location_id ? 'selected' : '' ?>><?= $l['name'] ?></option>
                <?php endforeach; ?>
            </select>
            <label>Martial Art</label>
            <select id="art-filter">
                <option value="bjj" <?= $martial_art_filter == 'bjj' ? 'selected' : '' ?>>BJJ</option>
                <option value="mt" <?= $martial_art_filter == 'mt' ? 'selected' : '' ?>>Muay Thai</option>
            </select>
        </div>

        <?php if ($is_admin): ?>
            <h3 style="color:var(--secondary-dark); margin-top:0">Assignments</h3>
            <div style="background:#e8f4fd; padding:10px; border-radius:6px; border:1px dashed #b6d4fe; font-size:0.85em; color:#0c5460; margin-bottom:15px;">
                <i class="fas fa-info-circle"></i> Drag a coach onto the <b>[A] All Week</b> button to assign them to every class in that row.
            </div>
            <table class="coaches-table-fi">
                <thead>
                    <tr>
                        <th>Head Coach</th>
                        <th>Helper</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($coaches as $c): ?>
                        <tr>
                            <td>
                                <div class="coach-draggable coach-head" data-id="<?= $c['id'] ?>" data-role="head" data-color="<?= $c['color_code'] ?>" style="border-color:<?= $c['color_code'] ?>">
                                    <?= $c['name'] ?>
                                </div>
                            </td>
                            <td>
                                <div class="coach-draggable coach-helper" data-id="<?= $c['id'] ?>" data-role="helper" data-color="<?= $c['color_code'] ?>" style="border-color:<?= $c['color_code'] ?>">
                                    <?= $c['name'] ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($can_view_tools): ?>
            <div style="margin-top: 30px;">
                <h4 style="color:#aaa; text-transform:uppercase; font-size:0.8em; letter-spacing:1px;">Management</h4>
                <a href="users.php" class="sidebar-link"><i class="fas fa-users"></i> Manage Users</a>
                <a href="private_classes.php" class="sidebar-link"><i class="fas fa-money-bill-wave"></i> Private Classes</a>

                <?php if ($is_admin): ?>
                    <a href="classes.php" class="sidebar-link"><i class="fas fa-calendar-alt"></i> Manage Classes</a>
                    <a href="location_reports.php" class="sidebar-link"><i class="fas fa-file-invoice-dollar"></i> Payroll Report</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="reports.php" class="sidebar-link"><i class="fas fa-chart-line"></i> My Reports</a>
            <a href="logout.php" class="sidebar-link" style="color:#e74c3c; border:none; margin-top:10px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div id="calendar-container">
        <div class="header-row">
            <div>
                <h2 style="margin:0; color:var(--secondary-dark)"><?= $current_location_name ?> Schedule - <?= $art_display ?></h2>
                <div style="font-size:0.9em; color:#666; margin-top:5px;">Weekly View</div>
            </div>
            <div style="display:flex; align-items:center; gap:15px;">
                <a href="<?= $base_url ?>?week_start=<?= $prev_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn"><i class="fas fa-chevron-left"></i> Prev</a>
                <span style="font-weight:bold; font-size:1.1em; color:var(--secondary-dark)"><?= date('M d', strtotime($start_of_week)) ?> - <?= date('M d', strtotime($end_of_week)) ?></span>
                <a href="<?= $base_url ?>?week_start=<?= $next_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn">Next <i class="fas fa-chevron-right"></i></a>

                <?php if ($is_admin): ?>

                    <?php if ($is_locked): ?>
                        <button id="lock-btn" onclick="toggleLock('unlock')" class="nav-btn btn-lock" title="Unlock Week"><i class="fas fa-lock"></i></button>
                    <?php else: ?>
                        <button id="lock-btn" onclick="toggleLock('lock')" class="nav-btn btn-unlock" title="Lock Week"><i class="fas fa-lock-open"></i></button>
                    <?php endif; ?>

                    <div class="clone-dropdown" style="position:relative; display:inline-block; margin-left:15px;">
                        <button id="clone-dropdown-btn" class="nav-btn" style="background:#007bff; color:white;">
                            <i class="fas fa-copy"></i> Clone Week <i class="fas fa-caret-down" style="margin-left:5px;"></i>
                        </button>
                        <div id="clone-dropdown-menu" class="clone-dropdown-menu">
                            <div class="clone-option" data-mode="current">
                                <i class="fas fa-filter"></i> Clone current view only
                                <span class="clone-option-desc"><?= $current_location_name ?> - <?= $art_display ?></span>
                            </div>
                            <div class="clone-option" data-mode="all">
                                <i class="fas fa-globe"></i> Clone entire schedule
                                <span class="clone-option-desc">All locations & martial arts</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($can_view_tools): ?>
                    <button id="download-btn" class="nav-btn" style="background:#28a745; color:white; margin-left:10px;">
                        <i class="fas fa-download"></i> Download
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <table id="custom-schedule" class="<?= $is_locked ? 'locked-mode' : '' ?>">
            <thead>
                <tr>
                    <th class="time-col">Time</th>
                    <?php foreach ($days_of_week as $d): ?>
                        <th>
                            <?= $d ?>
                            <div style="font-weight:normal; font-size:0.85em; margin-top:4px; opacity:0.8">
                                <?= date('M d', strtotime($start_of_week . " +" . array_search($d, $days_of_week) . " days")) ?>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule_data as $row): ?>
                    <tr>
                        <td class="time-col">
                            <div class="time-wrapper">
                                <div class="time-text"><?= date('g:i A', strtotime($row['start_time'])) ?></div>
                                <div class="class-text"><?= $row['class_title'] ?></div>

                                <?php if ($is_admin): ?>
                                    <div class="bulk-btn"
                                        data-time="<?= $row['start_time'] ?>"
                                        data-name="<?= htmlspecialchars($row['class_title']) ?>">
                                        <i class="fas fa-layer-group"></i> [A] All Week
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php foreach ($days_of_week as $day): $cell = $row['data'][$day] ?? null; ?>
                            <td>
                                <?php if ($cell): ?>
                                    <div class="slot"
                                        id="slot-<?= $cell['template_id'] ?>-<?= $cell['date'] ?>"
                                        data-tid="<?= $cell['template_id'] ?>"
                                        data-date="<?= $cell['date'] ?>">

                                        <?php foreach ($cell['coaches'] as $c): ?>
                                            <?php $fontWeight = ($c['position'] === 'head') ? 'bold' : 'normal'; ?>

                                            <div class="assignment"
                                                data-cid="<?= $c['user_id'] ?>"
                                                data-role="<?= $c['position'] ?>"
                                                style="border-left:4px solid <?= $c['color_code'] ?>">
                                                <span style="font-weight:<?= $fontWeight ?>"><?= $c['coach_name'] ?> (<?= ucfirst($c['position']) ?>)</span>
                                                <?php if ($is_admin): ?><i class="fas fa-times del-btn"></i><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script>
        $(function() {
            const isAdmin = <?= json_encode($is_admin) ?>;
            const weekStart = '<?= $start_of_week ?>';
            const locId = '<?= $filter_location_id ?>';
            const artFilter = '<?= $martial_art_filter ?>';
            const nextWeekStart = '<?= $next_week_start ?>';
            // NEW: Pass lock state to JS
            const isLocked = <?= $is_locked ? 'true' : 'false' ?>;

            $('#loc-filter, #art-filter').change(function() {
                window.location = `dashboard.php?week_start=${weekStart}&location=${$('#loc-filter').val()}&martial_art=${$('#art-filter').val()}`;
            });

            $('#download-btn').click(function() {
                // 1. Temporarily remove the fade/gray effect
                $('#custom-schedule').removeClass('locked-mode');

                $('body').addClass('screenshot-mode');
                window.scrollTo(0, 0);

                setTimeout(function() {
                    html2canvas(document.querySelector("#calendar-container"), {
                        scale: 2,
                        useCORS: true,
                        backgroundColor: "#ffffff",
                        windowWidth: document.documentElement.scrollWidth,
                        windowHeight: document.documentElement.scrollHeight,
                        scrollY: 0,
                        scrollX: 0
                    }).then(canvas => {
                        let link = document.createElement('a');
                        link.download = '<?= $current_location_name ?> Schedule - <?= $art_display ?> - <?= $start_of_week ?>.png';
                        link.href = canvas.toDataURL("image/png");
                        link.click();

                        // 2. Restore everything back to normal
                        $('body').removeClass('screenshot-mode');

                        // If the page was locked, put the lock style back
                        if (isLocked) {
                            $('#custom-schedule').addClass('locked-mode');
                        }
                    });
                }, 300);
            });

            // ONLY INITIALIZE DRAG & DROP IF ADMIN AND NOT LOCKED
            if (isAdmin && !isLocked) {
                $('.coach-draggable').draggable({
                    helper: 'clone',
                    revert: 'invalid',
                    appendTo: 'body',
                    cursorAt: {
                        top: 15,
                        left: 15
                    },
                    start: function(e, ui) {
                        ui.helper.css({
                            'width': '150px',
                            'background': '#fff',
                            'box-shadow': '0 5px 15px rgba(0,0,0,0.2)',
                            'z-index': 999999,
                            'padding': '10px',
                            'border-radius': '4px',
                            'text-align': 'center'
                        });
                    }
                });

                function initSlotInteractions() {
                    $('.slot').sortable({
                        connectWith: '.slot',
                        items: '.assignment',
                        placeholder: 'ui-sortable-placeholder',
                        cancel: '.del-btn',
                        cursor: 'move',
                        stop: function(event, ui) {
                            const slot = ui.item.closest('.slot');
                            const tid = slot.data('tid');
                            const date = slot.data('date');
                            let order = [];
                            slot.find('.assignment').each(function() {
                                order.push($(this).data('cid'));
                            });

                            $.post('api/update_assignment.php', {
                                action: 'update_order',
                                template_id: tid,
                                class_date: date,
                                order: order
                            });
                        },
                        receive: function(event, ui) {
                            const slot = $(this);
                            const tid = slot.data('tid');
                            const date = slot.data('date');
                            const cid = ui.item.data('cid');
                            const role = ui.item.data('role');

                            $.post('api/update_assignment.php', {
                                action: 'reassign_assignment',
                                coach_id: cid,
                                template_id: tid,
                                class_date: date,
                                position: role,
                                old_template_id: ui.sender.data('tid'),
                                old_class_date: ui.sender.data('date')
                            });
                        }
                    }).droppable({
                        accept: '.coach-draggable',
                        hoverClass: 'ui-state-hover',
                        drop: function(e, ui) {
                            if (!ui.draggable.hasClass('coach-draggable')) return;
                            const item = ui.draggable;
                            const slot = $(this);
                            const cid = item.data('id');
                            const role = item.data('role');
                            const tid = slot.data('tid');
                            const date = slot.data('date');

                            if (slot.find(`[data-cid="${cid}"]`).length) return;

                            let data = {
                                action: 'create_assignment',
                                coach_id: cid,
                                template_id: tid,
                                class_date: date,
                                position: role
                            };

                            $.post('api/update_assignment.php', data, function(res) {
                                if (res.success) {
                                    const color = item.data('color') || item.css('border-color');
                                    let name = item.text().trim().split('(')[0].trim();
                                    const roleLabel = role.charAt(0).toUpperCase() + role.slice(1);
                                    const fontWeight = (role === 'head') ? 'bold' : 'normal';

                                    const html = `
                                <div class="assignment" data-cid="${cid}" data-role="${role}" style="border-left:4px solid ${color}">
                                    <span style="font-weight:${fontWeight}">${name} (${roleLabel})</span>
                                    <i class="fas fa-times del-btn"></i>
                                </div>`;
                                    slot.append(html);
                                    $('.slot').sortable('refresh');
                                }
                            }, 'json');
                        }
                    });
                }
                initSlotInteractions();

                $('.bulk-btn').droppable({
                    accept: '.coach-draggable',
                    hoverClass: 'ui-state-hover',
                    tolerance: 'pointer',
                    drop: function(e, ui) {
                        const item = ui.draggable;
                        const btn = $(this);
                        const cid = item.data('id');
                        const role = item.data('role');
                        const name = item.text().trim();
                        const time = btn.data('time');
                        const className = btn.data('name');

                        if (confirm(`Assign ${name} to ALL "${className}" classes at this time for the whole week?`)) {
                            $.post('api/update_assignment.php', {
                                action: 'bulk_assign_by_time',
                                coach_id: cid,
                                position: role,
                                week_start: weekStart,
                                class_time: time,
                                class_name: className,
                                location_id: locId,
                                martial_art: artFilter
                            }, function(res) {
                                if (res.success) location.reload();
                                else alert(res.message || "Failed");
                            }, 'json');
                        }
                    }
                });

                $(document).on('click', '.del-btn', function(e) {
                    e.stopPropagation();
                    if (!confirm('Remove coach?')) return;
                    const el = $(this).closest('.assignment');
                    const slot = el.closest('.slot');
                    $.post('api/update_assignment.php', {
                        action: 'delete_assignment',
                        coach_id: el.data('cid'),
                        template_id: slot.data('tid'),
                        class_date: slot.data('date')
                    }, function() {
                        el.remove();
                    });
                });

            }

            // Clone Dropdown Logic
            $('#clone-dropdown-btn').click(function(e) {
                e.stopPropagation();
                $('#clone-dropdown-menu').toggleClass('show');
            });

            // Close dropdown when clicking outside
            $(document).click(function() {
                $('#clone-dropdown-menu').removeClass('show');
            });

            // Prevent dropdown from closing when clicking inside
            $('#clone-dropdown-menu').click(function(e) {
                e.stopPropagation();
            });

            // Handle clone options
            $('.clone-option').click(async function() {
                const mode = $(this).data('mode');
                const locationName = '<?= addslashes($current_location_name) ?>';
                const artName = '<?= $art_display ?>';

                let confirmMsg, requestBody;

                if (mode === 'current') {
                    confirmMsg = `Clone only ${locationName} ${artName} schedule to next week?`;
                    requestBody = {
                        sourceWeekStart: weekStart,
                        location_id: locId,
                        martial_art: artFilter
                    };
                } else {
                    confirmMsg = `Clone schedule from ${weekStart} to next week?`;
                    requestBody = {
                        sourceWeekStart: weekStart
                    };
                }

                $('#clone-dropdown-menu').removeClass('show');

                if (!confirm(confirmMsg)) return;

                try {
                    const response = await fetch('api/clone_classes.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(requestBody)
                    });
                    const result = await response.json();
                    if (response.ok) {
                        alert(`Cloned ${result.clonedCount} assignments.`);
                        window.location = `dashboard.php?week_start=${nextWeekStart}&location=${$('#loc-filter').val()}&martial_art=${$('#art-filter').val()}`;
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (e) {
                    alert('Clone failed.');
                }
            });
        });

        // NEW: Lock Toggle Function
        function toggleLock(action) {
            const locId = '<?= $filter_location_id ?>';
            const type = '<?= $martial_art_filter ?>';
            const weekStart = '<?= $start_of_week ?>';

            if (!confirm(`Are you sure you want to ${action} this week's schedule?`)) return;

            fetch('toggle_lock.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        location_id: locId,
                        type: type,
                        week_start: weekStart,
                        action: action
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert("Error: " + (data.error || "Unknown"));
                    }
                });
        }
    </script>
</body>

</html>