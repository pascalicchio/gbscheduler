<?php
require_once 'includes/config.php';

// Require login
requireAuth();

$user_role = getUserRole();
$user_id = getUserId();

// PERMISSIONS
$is_admin = isAdmin();
$is_manager = isManager();
$is_coach = ($user_role === 'user');

// "Can Manage" = Access to sidebar tools
$can_view_tools = canManage();

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

$art_display = 'Jiu-Jitsu';
if ($martial_art_filter === 'mt') $art_display = 'Muay Thai';
elseif ($martial_art_filter === 'mma') $art_display = 'MMA';

// 2. Fetch Coaches with rates (needed for Admin sidebar and payroll calculation)
$coaches = [];
$coach_rates = []; // For JS payroll calculation
if ($is_admin) {
    $coach_sql = "SELECT u.id, u.name, u.color_code, u.rate_head_coach, u.rate_helper
                  FROM users u
                  WHERE u.role NOT IN ('manager', 'employee')
                  AND u.is_active = 1 ";
    $coach_params = [];

    if ($filter_location_id !== '0') {
        $coach_sql .= " AND EXISTS (SELECT 1 FROM user_locations ul WHERE ul.user_id = u.id AND ul.location_id = :location_id) ";
        $coach_params['location_id'] = $filter_location_id;
    }

    if ($martial_art_filter === 'bjj') {
        $coach_sql .= " AND FIND_IN_SET('bjj', u.coach_type) > 0 ";
    } elseif ($martial_art_filter === 'mt') {
        $coach_sql .= " AND FIND_IN_SET('mt', u.coach_type) > 0 ";
    } elseif ($martial_art_filter === 'mma') {
        $coach_sql .= " AND FIND_IN_SET('mma', u.coach_type) > 0 ";
    }

    try {
        $stmt_coaches = $pdo->prepare($coach_sql . " ORDER BY u.name ASC");
        $stmt_coaches->execute($coach_params);
        $coaches = $stmt_coaches->fetchAll(PDO::FETCH_ASSOC);

        // Build rates lookup for JavaScript
        foreach ($coaches as $c) {
            $coach_rates[$c['id']] = [
                'head' => (float)$c['rate_head_coach'],
                'helper' => (float)$c['rate_helper']
            ];
        }
    } catch (PDOException $e) {
    }
}

// Calculate last week's payroll for comparison
$last_week_payroll = 0;
if ($is_admin) {
    $last_week_start = date('Y-m-d', strtotime($start_of_week . ' - 7 days'));
    $last_week_end = date('Y-m-d', strtotime($end_of_week . ' - 7 days'));

    try {
        $sql = "SELECT ea.position, u.rate_head_coach, u.rate_helper
                FROM event_assignments ea
                JOIN users u ON ea.user_id = u.id
                JOIN class_templates ct ON ea.template_id = ct.id
                WHERE ea.class_date BETWEEN :start_date AND :end_date";

        $params = [
            'start_date' => $last_week_start,
            'end_date' => $last_week_end
        ];

        if ($filter_location_id !== '0') {
            $sql .= " AND ct.location_id = :location_id";
            $params['location_id'] = $filter_location_id;
        }

        if ($martial_art_filter !== 'all') {
            $sql .= " AND ct.martial_art = :martial_art";
            $params['martial_art'] = $martial_art_filter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignments as $a) {
            if ($a['position'] === 'head') {
                $last_week_payroll += (float)$a['rate_head_coach'];
            } else {
                $last_week_payroll += (float)$a['rate_helper'];
            }
        }
    } catch (PDOException $e) {
        // Silently fail
    }
}

// --- DATA FETCHING ---
function get_schedule_data($pdo, $location_id, $start_date, $end_date, $user_role, $user_id, $martial_art_filter)
{
    $days_of_week_internal = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $days_map = array_flip($days_of_week_internal);
    $start_timestamp = strtotime($start_date);

    $params = [];
    // Show classes that:
    // 1. Were active by this week (active_from <= week_end)
    // 2. AND haven't been deactivated before this week (deactivated_at IS NULL OR > week_start)
    $where = [
        "active_from <= :week_end",
        "(deactivated_at IS NULL OR deactivated_at > :week_start)"
    ];
    $params['week_start'] = $start_date;
    $params['week_end'] = $end_date;
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
            'start_time' => $template['start_time'],
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
    <link rel="icon" type="image/png" href="assets/favicon.png">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>

    <style>
        :root {
            /* Use design system gradient colors */
            --gradient-primary: linear-gradient(90deg, rgb(0, 201, 255), rgb(146, 254, 157));
            --gradient-hover: linear-gradient(90deg, rgb(0, 181, 235), rgb(126, 234, 137));
            --primary-color: rgb(0, 201, 255);
            --primary-dark: rgb(0, 181, 235);
            --secondary-dark: #2c3e50;
            --bg-color: #f8fafb;
            --sidebar-color: #ffffff;
            --text-color: #2c3e50;
        }

        body {
            display: flex;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0;
            background: var(--bg-color);
            color: var(--text-color);
            height: 100vh;
            overflow: hidden;
            font-weight: 400;
            -webkit-font-smoothing: antialiased;
        }

        #sidebar {
            width: 320px;
            padding: 24px;
            background: var(--sidebar-color);
            border-right: 1px solid rgba(0, 201, 255, 0.1);
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.04);
            overflow-y: auto;
            flex-shrink: 0;
            z-index: 20;
            transition: all 0.3s ease;
            position: relative;
        }

        /* Gradient accent bar on sidebar */
        #sidebar::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-image: var(--gradient-primary);
        }

        .filter-box {
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.03), rgba(146, 254, 157, 0.03));
            border: 2px solid rgba(0, 201, 255, 0.15);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        /* Payroll Summary Box - Gradient Design */
        .payroll-box {
            background-image: var(--gradient-primary);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 20px rgba(0, 201, 255, 0.25);
            position: relative;
            overflow: hidden;
        }

        .payroll-box::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1));
            pointer-events: none;
        }

        .payroll-label {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.9);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .payroll-label i {
            margin-right: 8px;
            color: white;
            opacity: 0.9;
        }

        .payroll-value {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
            min-width: 150px;
        }

        .payroll-amount {
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.02em;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .payroll-comparison {
            font-size: 0.8rem;
            color: white;
            margin-top: 4px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            justify-content: flex-end;
            white-space: nowrap;
        }

        .payroll-comparison i {
            font-size: 0.75rem;
        }

        .payroll-up {
            color: white;
        }

        .payroll-up i {
            color: #ff6b6b;
            background: rgba(255, 107, 107, 0.2);
            padding: 4px;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .payroll-down {
            color: white;
        }

        .payroll-down i {
            color: #51cf66;
            background: rgba(81, 207, 102, 0.2);
            padding: 4px;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .payroll-count {
            font-size: 0.75em;
            color: rgba(255,255,255,0.5);
        }

        .filter-box label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--secondary-dark);
            text-transform: uppercase;
            display: block;
            margin-bottom: 8px;
            letter-spacing: 0.05em;
        }

        select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 12px;
            background: white;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--secondary-dark);
            transition: all 0.25s ease;
            cursor: pointer;
        }

        select:hover {
            border-color: rgba(0, 201, 255, 0.3);
        }

        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
        }

        /* Draggable Styles */
        .coaches-table-fi {
            width: 100%;
            border-collapse: separate;
            border-spacing: 5px 0;
        }

        .coach-draggable {
            border: 1px solid #ccc;
            padding: 10px 12px;
            margin-bottom: 6px;
            cursor: grab;
            font-size: 0.9rem;
            border-radius: 8px;
            transition: all 0.2s;
            background: white;
            text-align: left;
            font-weight: 500;
        }

        .coach-draggable:hover {
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        .coach-head {
            border: 2px solid;
            background: white;
        }

        .coach-helper {
            border: 1px solid #ddd;
            background: #f8f9fa;
            opacity: 0.85;
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
            margin-bottom: 24px;
            background: white;
            padding: 20px 28px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 201, 255, 0.1);
            position: relative;
            z-index: 10;
        }

        /* Gradient accent on header */
        .header-row::before {
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

        .nav-btn {
            text-decoration: none;
            color: var(--secondary-dark);
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 10px;
            background: #f5f7fa;
            transition: all 0.25s ease;
            border: 2px solid transparent;
            cursor: pointer;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn:hover {
            background: #e8ecf2;
            border-color: rgba(0, 201, 255, 0.2);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
        }

        /* Dark gradient buttons for clone/download */
        .nav-btn.btn-dark {
            background-image: linear-gradient(135deg, #1a202c, #2d3748);
            color: white;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            border: none;
        }

        .nav-btn.btn-dark:hover {
            background-image: linear-gradient(135deg, #2d3748, #4a5568);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
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

        /* Locked mode - gray for admins only, coaches see full color */
        <?php if ($is_admin): ?>
        .locked-mode {
            pointer-events: none;
            opacity: 0.6;
            filter: grayscale(80%);
        }
        <?php else: ?>
        .locked-mode {
            pointer-events: none;
        }
        <?php endif; ?>

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
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            margin: 4px 0;
            border-radius: 10px;
            transition: all 0.25s ease;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .sidebar-link::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background-image: var(--gradient-primary);
            transform: scaleY(0);
            transition: transform 0.25s ease;
        }

        .sidebar-link:hover {
            color: var(--primary-color);
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.05), rgba(146, 254, 157, 0.05));
            transform: translateX(4px);
        }

        .sidebar-link:hover::before {
            transform: scaleY(1);
        }

        .sidebar-link i {
            width: 18px;
            text-align: center;
            font-size: 1rem;
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
        body.screenshot-mode #lock-btn,
        body.screenshot-mode #payroll-summary {
            display: none !important;
        }

        /* Clone Dropdown Styles */
        .clone-dropdown {
            position: relative;
        }

        .clone-dropdown-menu {
            display: none;
            position: fixed;
            background: white;
            border: 2px solid rgba(0, 201, 255, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.3);
            min-width: 280px;
            z-index: 99999 !important;
            margin-top: 8px;
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

        /* Alpine.js Loading States */
        [x-cloak] { display: none !important; }

        .filter-loading {
            opacity: 0.6;
            pointer-events: none;
        }

        .filter-loading-indicator {
            margin-top: 12px;
            padding: 10px;
            background: linear-gradient(135deg, rgba(0, 201, 255, 0.1), rgba(146, 254, 157, 0.1));
            border-radius: 8px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .filter-loading-indicator i {
            margin-right: 8px;
        }

        /* ============================================
           Alpine.js Modal Styles
           ============================================ */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            position: relative;
            background: white;
            border-radius: 20px;
            padding: 32px;
            max-width: 460px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            border: 2px solid rgba(0, 201, 255, 0.2);
        }

        .modal-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .modal-icon.icon-confirm {
            background-image: var(--gradient-primary);
            color: white;
        }

        .modal-icon.icon-warning {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: white;
        }

        .modal-icon.icon-danger {
            background: linear-gradient(135deg, #f44336, #e91e63);
            color: white;
        }

        .modal-icon.icon-success {
            background: linear-gradient(135deg, #4caf50, #8bc34a);
            color: white;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--secondary-dark);
            margin: 0 0 12px 0;
            letter-spacing: -0.02em;
        }

        .modal-message {
            font-size: 1rem;
            color: #666;
            margin: 0 0 28px 0;
            line-height: 1.6;
            font-weight: 400;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .modal-btn {
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            border: none;
            min-width: 120px;
        }

        .modal-btn-cancel {
            background: #f5f7fa;
            color: var(--secondary-dark);
            border: 2px solid #e8ecf2;
        }

        .modal-btn-cancel:hover {
            background: #e8ecf2;
            transform: translateY(-1px);
        }

        .modal-btn-confirm {
            background-image: var(--gradient-primary);
            color: white;
            box-shadow: 0 6px 20px rgba(0, 201, 255, 0.3);
        }

        .modal-btn-confirm:hover {
            background-image: var(--gradient-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 201, 255, 0.4);
        }

        .modal-btn-confirm:active {
            transform: translateY(0);
        }

        @media (max-width: 480px) {
            .modal-content {
                padding: 24px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-btn {
                width: 100%;
            }
        }

        /* ======================================== */
        /* Mobile Layout (≤768px) */
        /* ======================================== */
        [x-cloak] { display: none !important; }

        .mobile-header {
            display: none;
        }

        .mobile-filters {
            display: none;
        }

        .mobile-payroll {
            display: none;
        }

        .mobile-calendar {
            display: none;
        }

        .mobile-week-nav {
            display: none;
        }

        @media (max-width: 768px) {
            body {
                display: block;
                height: auto;
                overflow: auto;
            }

            #sidebar {
                display: none !important;
            }

            #calendar-container {
                display: none !important;
            }

            .mobile-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                background: white;
                border-bottom: 1px solid rgba(0, 201, 255, 0.1);
                position: sticky;
                top: 0;
                z-index: 100;
            }

            .mobile-header::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 3px;
                background-image: var(--gradient-primary);
            }

            .mobile-header h2 {
                margin: 0;
                font-size: 1.25rem;
                font-weight: 700;
                color: var(--secondary-dark);
            }

            /* Nav Menu - Standard Pattern */
            .nav-menu {
                position: relative;
            }

            .nav-menu-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 18px;
                background-image: linear-gradient(135deg, #1a202c, #2d3748);
                color: white;
                border: none;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.9rem;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .nav-menu-btn:hover {
                background-image: linear-gradient(135deg, #2d3748, #4a5568);
                transform: translateY(-1px);
            }

            .nav-dropdown {
                position: absolute;
                right: 0;
                top: 100%;
                margin-top: 8px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
                border: 1px solid #e8ecf2;
                min-width: 220px;
                z-index: 1000;
                overflow: hidden;
            }

            .nav-dropdown a {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 20px;
                color: var(--text-color);
                text-decoration: none;
                font-weight: 500;
                font-size: 0.95rem;
                transition: all 0.2s ease;
                border-left: 3px solid transparent;
            }

            .nav-dropdown a:hover {
                background: linear-gradient(to right, rgba(0,201,255,0.08), transparent);
                border-left-color: rgb(0, 201, 255);
                padding-left: 24px;
            }

            .nav-dropdown a i {
                width: 18px;
                text-align: center;
                color: #a0aec0;
                font-size: 0.95rem;
            }

            .nav-dropdown a:hover i {
                color: rgb(0, 201, 255);
            }

            .nav-dropdown a.logout {
                border-top: 1px solid #f0f0f0;
                color: #e53e3e;
            }

            .nav-dropdown a.logout:hover {
                background: linear-gradient(to right, rgba(229, 62, 62, 0.06), transparent);
                border-left-color: #e53e3e;
            }

            .nav-dropdown a.logout i {
                color: #e53e3e;
            }

            /* Mobile Filters */
            .mobile-filters {
                display: block;
                padding: 16px 20px;
                background: white;
                border-bottom: 1px solid #f0f0f0;
            }

            .mobile-filter-row {
                display: flex;
                gap: 12px;
                align-items: center;
            }

            .mobile-filter-row .filter-group {
                flex: 1;
            }

            .mobile-filter-row label {
                display: block;
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #a0aec0;
                margin-bottom: 6px;
            }

            .mobile-filter-row select {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                font-size: 0.9rem;
                font-weight: 500;
                background: white;
                color: var(--text-color);
                transition: all 0.2s;
                font-family: inherit;
            }

            .mobile-filter-row select:focus {
                outline: none;
                border-color: rgb(0, 201, 255);
                box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
            }

            /* Mobile Payroll */
            .mobile-payroll {
                display: block;
                margin: 12px 16px;
            }

            /* Mobile Week Navigation */
            .mobile-week-nav {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px 20px;
                background: white;
                border-bottom: 1px solid #f0f0f0;
            }

            .mobile-week-nav .week-label {
                font-weight: 700;
                font-size: 1rem;
                color: var(--secondary-dark);
            }

            .mobile-week-nav .nav-btn {
                padding: 8px 14px;
                font-size: 0.85rem;
            }

            /* Mobile Calendar */
            .mobile-calendar {
                display: block;
                padding: 0 16px 24px;
            }

            .mobile-day-card {
                background: white;
                border-radius: 12px;
                margin-bottom: 12px;
                border: 1px solid #e8ecf2;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }

            .mobile-day-card.today {
                border-color: #ffc107;
                box-shadow: 0 2px 12px rgba(255, 193, 7, 0.2);
            }

            .mobile-day-header {
                padding: 12px 16px;
                background: linear-gradient(135deg, #1a202c, #2d3748);
                color: white;
                font-weight: 700;
                font-size: 0.95rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .mobile-day-header .day-date {
                font-weight: 400;
                opacity: 0.8;
                font-size: 0.85rem;
            }

            .mobile-day-card.today .mobile-day-header {
                background: linear-gradient(135deg, #d69e2e, #ecc94b);
            }

            .mobile-class-item {
                padding: 12px 16px;
                border-bottom: 1px solid #f5f5f5;
                display: flex;
                align-items: flex-start;
                gap: 12px;
            }

            .mobile-class-item:last-child {
                border-bottom: none;
            }

            .mobile-class-time {
                font-weight: 700;
                font-size: 0.8rem;
                color: rgb(0, 201, 255);
                min-width: 70px;
                padding-top: 2px;
            }

            .mobile-class-info {
                flex: 1;
            }

            .mobile-class-name {
                font-weight: 600;
                font-size: 0.9rem;
                color: var(--secondary-dark);
                margin-bottom: 4px;
            }

            .mobile-coach-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 0.75rem;
                font-weight: 600;
                margin-right: 4px;
                margin-bottom: 4px;
                border-left: 3px solid;
            }

            .mobile-coach-badge.head {
                background: rgba(0, 201, 255, 0.08);
                color: #0d47a1;
                border-left-color: rgb(0, 201, 255);
            }

            .mobile-coach-badge.helper {
                background: rgba(146, 254, 157, 0.1);
                color: #2e7d32;
                border-left-color: rgb(146, 254, 157);
            }

            .mobile-empty-day {
                padding: 16px;
                text-align: center;
                color: #a0aec0;
                font-size: 0.85rem;
                font-style: italic;
            }
        }
    </style>
</head>

<body>

    <!-- Mobile Header + Menu (hidden on desktop) -->
    <div class="mobile-header">
        <h2><i class="fas fa-calendar-alt" style="background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-right: 8px;"></i> GB Schedule</h2>
        <div class="nav-menu" x-data="{ open: false }">
            <button @click="open = !open" class="nav-menu-btn">
                <i class="fas fa-bars"></i>
            </button>
            <div x-show="open" @click.away="open = false" x-cloak class="nav-dropdown">
                <a href="dashboard.php"><i class="fas fa-calendar-alt"></i> Dashboard</a>
                <a href="reports.php"><i class="fas fa-chart-line"></i> Individual Report</a>
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

    <!-- Mobile Filters (hidden on desktop) -->
    <div class="mobile-filters" x-data="dashboardFilters()">
        <div class="mobile-filter-row">
            <div class="filter-group">
                <label>Location</label>
                <select x-model="location" @change="applyFilters()">
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= $l['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Martial Art</label>
                <select x-model="martialArt" @change="applyFilters()">
                    <option value="bjj">BJJ</option>
                    <option value="mt">Muay Thai</option>
                    <option value="mma">MMA</option>
                </select>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <!-- Mobile Payroll Preview (hidden on desktop) -->
    <div class="mobile-payroll">
        <div class="payroll-box">
            <div class="payroll-label">
                <i class="fas fa-dollar-sign"></i> Payroll Preview
            </div>
            <div class="payroll-value">
                <div class="payroll-amount">$<span class="payroll-total-mobile">0.00</span></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mobile Week Nav (hidden on desktop) -->
    <div class="mobile-week-nav">
        <a href="<?= $base_url ?>?week_start=<?= $prev_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn"><i class="fas fa-chevron-left"></i></a>
        <span class="week-label">
            <?= date('M d', strtotime($start_of_week)) ?> - <?= date('M d', strtotime($end_of_week)) ?>
        </span>
        <a href="<?= $base_url ?>?week_start=<?= $next_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn"><i class="fas fa-chevron-right"></i></a>
    </div>

    <!-- Mobile Calendar View (hidden on desktop) -->
    <div class="mobile-calendar">
        <?php
        $today = date('Y-m-d');
        foreach ($days_of_week as $idx => $day):
            $day_date = date('Y-m-d', strtotime($start_of_week . " +{$idx} days"));
            $is_today = ($day_date === $today);
            $day_classes = [];
            foreach ($schedule_data as $row) {
                $cell = $row['data'][$day] ?? null;
                if ($cell) {
                    $cell['class_title'] = $row['class_title'];
                    $day_classes[] = $cell;
                }
            }
        ?>
            <div class="mobile-day-card <?= $is_today ? 'today' : '' ?>">
                <div class="mobile-day-header">
                    <span><?= $day ?></span>
                    <span class="day-date"><?= date('M d', strtotime($day_date)) ?></span>
                </div>
                <?php if (empty($day_classes)): ?>
                    <div class="mobile-empty-day">No classes scheduled</div>
                <?php else: ?>
                    <?php foreach ($day_classes as $cell): ?>
                        <div class="mobile-class-item">
                            <div class="mobile-class-time"><?= date('g:i A', strtotime($cell['start_time'])) ?></div>
                            <div class="mobile-class-info">
                                <div class="mobile-class-name"><?= htmlspecialchars($cell['class_title'] ?? '') ?></div>
                                <?php foreach ($cell['coaches'] as $c): ?>
                                    <span class="mobile-coach-badge <?= $c['position'] ?>" style="border-left-color: <?= $c['color_code'] ?>">
                                        <?= $c['coach_name'] ?> (<?= ucfirst($c['position']) ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Alpine.js Modal Component -->
    <div x-data
         x-show="$store.modal.isOpen"
         x-cloak
         @keydown.escape.window="$store.modal.close()"
         class="modal-overlay"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">

        <div class="modal-backdrop" @click="$store.modal.close()"></div>

        <div class="modal-content"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform scale-90"
             x-transition:enter-end="opacity-100 transform scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 transform scale-100"
             x-transition:leave-end="opacity-0 transform scale-90">

            <!-- Modal Icon -->
            <div class="modal-icon" :class="$store.modal.getIconClass()">
                <i :class="$store.modal.getIcon()"></i>
            </div>

            <!-- Modal Header -->
            <h3 class="modal-title" x-text="$store.modal.title"></h3>

            <!-- Modal Message -->
            <p class="modal-message" x-text="$store.modal.message"></p>

            <!-- Modal Actions -->
            <div class="modal-actions">
                <button @click="$store.modal.close()" class="modal-btn modal-btn-cancel">
                    Cancel
                </button>
                <button @click="$store.modal.confirm()" class="modal-btn modal-btn-confirm">
                    <span x-text="$store.modal.confirmText"></span>
                </button>
            </div>
        </div>
    </div>

    <div id="sidebar" x-data="dashboardFilters()" x-cloak>
        <div class="filter-box" :class="{ 'filter-loading': isLoading }">
            <label>Location</label>
            <select id="loc-filter" x-model="location" @change="applyFilters()" :disabled="isLoading">
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>"><?= $l['name'] ?></option>
                <?php endforeach; ?>
            </select>
            <label>Martial Art</label>
            <select id="art-filter" x-model="martialArt" @change="applyFilters()" :disabled="isLoading">
                <option value="bjj">BJJ</option>
                <option value="mt">Muay Thai</option>
                <option value="mma">MMA</option>
            </select>

            <!-- Loading indicator -->
            <div x-show="isLoading" x-transition class="filter-loading-indicator">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>

        <?php if ($is_admin): ?>
            <!-- Payroll Summary Box -->
            <div id="payroll-summary" class="payroll-box">
                <div class="payroll-label">
                    <i class="fas fa-dollar-sign"></i> Payroll Preview
                </div>
                <div class="payroll-value">
                    <div class="payroll-amount">$<span id="payroll-total">0.00</span></div>
                    <div id="payroll-comparison" class="payroll-comparison" style="display: none;">
                        <!-- Week-over-week comparison will be injected here -->
                    </div>
                </div>
            </div>

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
                <a href="inventory.php" class="sidebar-link"><i class="fas fa-boxes"></i> Inventory</a>

                <?php if ($is_admin): ?>
                    <a href="classes.php" class="sidebar-link"><i class="fas fa-calendar-alt"></i> Manage Classes</a>
                    <a href="location_reports.php" class="sidebar-link"><i class="fas fa-file-invoice-dollar"></i> Payroll Report</a>
                    <a href="coach_payments.php" class="sidebar-link"><i class="fas fa-money-check-alt"></i> Coach Payments</a>
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
                <h2 style="margin:0; color:var(--secondary-dark); font-weight:700; font-size:1.75rem; letter-spacing:-0.02em;">
                    <span style="font-weight:300;"><?= $current_location_name ?></span> Schedule
                    <span style="font-weight:800; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">·</span>
                    <span style="font-weight:700;"><?= $art_display ?></span>
                </h2>
                <div style="font-size:0.875rem; color:#a0aec0; margin-top:8px; font-weight:300; letter-spacing:0.01em;">Weekly View</div>
            </div>
            <div style="display:flex; align-items:center; gap:15px;">
                <a href="<?= $base_url ?>?week_start=<?= $prev_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn"><i class="fas fa-chevron-left"></i> Prev</a>
                <span style="font-weight:700; font-size:1.15rem; color:var(--secondary-dark); letter-spacing:-0.01em;">
                    <span style="font-weight:300;"><?= date('M', strtotime($start_of_week)) ?></span> <?= date('d', strtotime($start_of_week)) ?>
                    <span style="font-weight:300; opacity:0.5;">-</span>
                    <span style="font-weight:300;"><?= date('M', strtotime($end_of_week)) ?></span> <?= date('d', strtotime($end_of_week)) ?>
                </span>
                <a href="<?= $base_url ?>?week_start=<?= $next_week_start ?><?= $location_param ?><?= $martial_art_param ?>" class="nav-btn">Next <i class="fas fa-chevron-right"></i></a>

                <?php if ($is_admin): ?>

                    <?php if ($is_locked): ?>
                        <button id="lock-btn" onclick="showLockModal('unlock')" class="nav-btn btn-lock" title="Unlock Week"><i class="fas fa-lock"></i></button>
                    <?php else: ?>
                        <button id="lock-btn" onclick="showLockModal('lock')" class="nav-btn btn-unlock" title="Lock Week"><i class="fas fa-lock-open"></i></button>
                    <?php endif; ?>

                    <div class="clone-dropdown" style="position:relative; display:inline-block; margin-left:15px;">
                        <button id="clone-dropdown-btn" class="nav-btn btn-dark">
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
                    <button id="download-btn" class="nav-btn btn-dark" style="margin-left:10px;">
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
                                        data-date="<?= $cell['date'] ?>"
                                        data-start="<?= $cell['start_time'] ?>"
                                        data-end="<?= $cell['end_time'] ?>">

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
            const isLocked = <?= $is_locked ? 'true' : 'false' ?>;

            // Coach rates for payroll calculation
            const coachRates = <?= !empty($coach_rates) ? json_encode($coach_rates) : '{}' ?>;

            // Calculate hours between two time strings (HH:MM:SS)
            function getHours(startTime, endTime) {
                try {
                    const [sh, sm] = String(startTime).split(':').map(Number);
                    const [eh, em] = String(endTime).split(':').map(Number);
                    let hours = (eh + em/60) - (sh + sm/60);
                    if (hours < 0) hours += 24;
                    return Math.max(hours, 1);
                } catch(e) {
                    return 1;
                }
            }

            // Calculate total payroll from all assignments on screen
            const lastWeekPayroll = <?= $last_week_payroll ?>;

            function calculatePayroll() {
                if (!isAdmin) return;

                let total = 0;
                let assignmentCount = 0;

                $('.slot').each(function() {
                    const slot = $(this);
                    const startTime = slot.data('start');
                    const endTime = slot.data('end');

                    if (!startTime || !endTime) return;

                    const hours = getHours(startTime, endTime);

                    slot.find('.assignment').each(function() {
                        const assignment = $(this);
                        const coachId = assignment.data('cid');
                        const role = assignment.data('role');

                        if (coachRates && coachRates[coachId]) {
                            const rate = coachRates[coachId][role] || 0;
                            total += hours * rate;
                        }
                        assignmentCount++;
                    });
                });

                $('#payroll-total').text(total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('.payroll-total-mobile').text(total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));

                // Calculate week-over-week comparison
                if (lastWeekPayroll > 0) {
                    const diff = total - lastWeekPayroll;
                    const percentChange = ((diff / lastWeekPayroll) * 100).toFixed(1);
                    const isIncrease = diff > 0;
                    const arrow = isIncrease ? 'fa-arrow-up' : 'fa-arrow-down';
                    const colorClass = isIncrease ? 'payroll-up' : 'payroll-down';
                    const sign = isIncrease ? '+' : '';

                    const comparisonHtml = `
                        <span class="${colorClass}">
                            <i class="fas ${arrow}"></i>
                            ${sign}${Math.abs(percentChange)}% vs last week
                        </span>
                    `;

                    $('#payroll-comparison').html(comparisonHtml).show();
                } else {
                    $('#payroll-comparison').hide();
                }
            }

            // Calculate on page load
            calculatePayroll();

            // Filter changes now handled by Alpine.js
            // $('#loc-filter, #art-filter').change(function() {
            //     window.location = `dashboard.php?week_start=${weekStart}&location=${$('#loc-filter').val()}&martial_art=${$('#art-filter').val()}`;
            // });

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
                                if (res && res.success) {
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
                                    calculatePayroll();
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

                        Alpine.store('modal').open({
                            title: 'Assign to All Week',
                            message: `Assign ${name} as ${role} to ALL "${className}" classes at this time for the whole week?`,
                            confirmText: 'Assign All',
                            type: 'confirm',
                            onConfirm: () => {
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
                                    if (res.success) {
                                        location.reload();
                                    } else {
                                        Alpine.store('modal').open({
                                            title: 'Error',
                                            message: res.message || 'Assignment failed',
                                            confirmText: 'OK',
                                            type: 'danger',
                                            onConfirm: () => {}
                                        });
                                    }
                                }, 'json');
                            }
                        });
                    }
                });

                $(document).on('click', '.del-btn', function(e) {
                    e.stopPropagation();
                    const el = $(this).closest('.assignment');
                    const slot = el.closest('.slot');
                    const coachName = el.find('.cname').text() || 'this coach';

                    Alpine.store('modal').open({
                        title: 'Remove Coach',
                        message: `Remove ${coachName} from this class?`,
                        confirmText: 'Remove',
                        type: 'danger',
                        onConfirm: () => {
                            $.post('api/update_assignment.php', {
                                action: 'delete_assignment',
                                coach_id: el.data('cid'),
                                template_id: slot.data('tid'),
                                class_date: slot.data('date')
                            }, function() {
                                el.remove();
                                calculatePayroll(); // Update payroll
                            });
                        }
                    });
                });

            }

            // Clone Dropdown Logic - Move to body for proper z-index
            const $cloneMenu = $('#clone-dropdown-menu');
            $cloneMenu.appendTo('body'); // Move to body to escape stacking context

            $('#clone-dropdown-btn').click(function(e) {
                e.stopPropagation();
                $cloneMenu.toggleClass('show');

                // Position the fixed dropdown below the button
                if ($cloneMenu.hasClass('show')) {
                    const btnRect = this.getBoundingClientRect();
                    $cloneMenu.css({
                        top: btnRect.bottom + 8 + 'px',
                        right: (window.innerWidth - btnRect.right) + 'px'
                    });
                }
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
            $('.clone-option').click(function() {
                const mode = $(this).data('mode');
                const locationName = '<?= addslashes($current_location_name) ?>';
                const artName = '<?= $art_display ?>';

                let confirmMsg, modalTitle, requestBody;

                if (mode === 'current') {
                    modalTitle = 'Clone Current View';
                    confirmMsg = `Clone only ${locationName} ${artName} schedule to next week?`;
                    requestBody = {
                        sourceWeekStart: weekStart,
                        location_id: locId,
                        martial_art: artFilter
                    };
                } else {
                    modalTitle = 'Clone Entire Schedule';
                    confirmMsg = `Clone the entire schedule from ${weekStart} to next week? This includes all locations and martial arts.`;
                    requestBody = {
                        sourceWeekStart: weekStart
                    };
                }

                $cloneMenu.removeClass('show');

                // Show confirmation modal
                Alpine.store('modal').open({
                    title: modalTitle,
                    message: confirmMsg,
                    confirmText: 'Clone Now',
                    type: 'confirm',
                    onConfirm: async () => {
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
                                // Show success modal
                                Alpine.store('modal').open({
                                    title: 'Success!',
                                    message: `Cloned ${result.clonedCount} assignments successfully.`,
                                    confirmText: 'View Next Week',
                                    type: 'success',
                                    onConfirm: () => {
                                        window.location = `dashboard.php?week_start=${nextWeekStart}&location=${$('#loc-filter').val()}&martial_art=${$('#art-filter').val()}`;
                                    }
                                });
                            } else {
                                Alpine.store('modal').open({
                                    title: 'Error',
                                    message: result.message || 'Failed to clone schedule.',
                                    confirmText: 'OK',
                                    type: 'danger',
                                    onConfirm: () => {}
                                });
                            }
                        } catch (e) {
                            Alpine.store('modal').open({
                                title: 'Error',
                                message: 'Clone failed. Please try again.',
                                confirmText: 'OK',
                                type: 'danger',
                                onConfirm: () => {}
                            });
                        }
                    }
                });
            });
        });

        // Helper to show lock/unlock modal
        function showLockModal(action) {
            Alpine.store('modal').open({
                title: action === 'lock' ? 'Lock Week' : 'Unlock Week',
                message: action === 'lock'
                    ? 'This will prevent any changes to coach assignments for this week. Continue?'
                    : 'This will allow coaches to be edited for this week. Continue?',
                confirmText: action === 'lock' ? 'Lock' : 'Unlock',
                type: 'warning',
                onConfirm: () => toggleLock(action)
            });
        }

        // NEW: Lock Toggle Function
        function toggleLock(action) {
            const locId = '<?= $filter_location_id ?>';
            const type = '<?= $martial_art_filter ?>';
            const weekStart = '<?= $start_of_week ?>';

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

        // ============================================
        // Alpine.js Global Modal Store
        // ============================================
        document.addEventListener('alpine:init', () => {
            Alpine.store('modal', {
                isOpen: false,
                title: '',
                message: '',
                confirmText: 'Confirm',
                type: 'confirm',
                onConfirm: null,

                open(options) {
                    this.title = options.title || 'Confirm Action';
                    this.message = options.message || 'Are you sure?';
                    this.confirmText = options.confirmText || 'Confirm';
                    this.type = options.type || 'confirm';
                    this.onConfirm = options.onConfirm || null;
                    this.isOpen = true;
                    document.body.style.overflow = 'hidden';
                },

                close() {
                    this.isOpen = false;
                    document.body.style.overflow = '';
                },

                confirm() {
                    if (this.onConfirm && typeof this.onConfirm === 'function') {
                        this.onConfirm();
                    }
                    this.close();
                },

                getIcon() {
                    const icons = {
                        confirm: 'fas fa-question-circle',
                        warning: 'fas fa-exclamation-triangle',
                        danger: 'fas fa-exclamation-circle',
                        success: 'fas fa-check-circle'
                    };
                    return icons[this.type] || icons.confirm;
                },

                getIconClass() {
                    return `icon-${this.type}`;
                }
            });
        });

        // ============================================
        // Alpine.js Dashboard Filters Component
        // ============================================
        function dashboardFilters() {
            return {
                location: '<?= $filter_location_id ?>',
                martialArt: '<?= $martial_art_filter ?>',
                isLoading: false,
                weekStart: '<?= $start_of_week ?>',

                init() {
                    // Save preferences to localStorage
                    this.$watch('location', value => localStorage.setItem('gb_location', value));
                    this.$watch('martialArt', value => localStorage.setItem('gb_martial_art', value));
                },

                applyFilters() {
                    this.isLoading = true;

                    // Smooth transition with small delay for visual feedback
                    setTimeout(() => {
                        const url = `dashboard.php?week_start=${this.weekStart}&location=${this.location}&martial_art=${this.martialArt}`;
                        window.location = url;
                    }, 300);
                }
            }
        }

    </script>
</body>

</html>