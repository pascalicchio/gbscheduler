<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require 'db.php';
date_default_timezone_set('America/New_York'); // Set your timezone

// --- USER ROLE AND PERMISSION SETUP ---
$user_role = $_SESSION['user_role'] ?? 'guest';
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Coach';

// Roles: 'admin' for full control, 'user' for coach/employee
$is_admin = ($user_role === 'admin');
$is_coach = ($user_role === 'user');
$can_view_schedule = $is_admin || $is_coach;

// 1. Fetch Locations for the filter dropdown
$stmt_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = $stmt_locations->fetchAll(PDO::FETCH_ASSOC);

// --- LOCATION & MARTIAL ART FILTER LOGIC ---
$default_location_id = !empty($locations) ? $locations[0]['id'] : '0';

// Use the location ID from the URL, or the first location's ID as the default
$filter_location_id = $_GET['location'] ?? $default_location_id;

// *** FIX 1: DEFAULT MARTIAL ART FILTER IS 'bjj' ***
$martial_art_filter = $_GET['martial_art'] ?? 'bjj';

// Find the name of the currently selected location for the H1 tag
$current_location_name = "Schedule"; // Fallback name
if (!empty($locations)) {
    foreach ($locations as $loc) {
        if ((string)$loc['id'] === (string)$filter_location_id) {
            $current_location_name = $loc['name'];
            break;
        }
    }
}
// ----------------------------------------

// 2. Fetch Coaches for sidebar (filtered by location) - Admin Only
$coaches = [];
if ($is_admin) {
    // Only fetch users with the 'user' (coach) role for drag-and-drop
    $coach_sql = "SELECT id, name, color_code FROM users WHERE role = 'user' ";
    $coach_params = [];

    if ($filter_location_id !== '0') {
        $coach_sql .= " AND location = :location_id ";
        $coach_params['location_id'] = $filter_location_id;
    }

    // *** NEW: Filter by Martial Art using the coach_type ENUM ***
    if ($martial_art_filter === 'bjj') {
        // Coach must be designated as 'bjj' OR 'both'
        $coach_sql .= " AND coach_type IN ('bjj', 'both') ";
    } elseif ($martial_art_filter === 'mt') {
        // Coach must be designated as 'mt' OR 'both'
        $coach_sql .= " AND coach_type IN ('mt', 'both') ";
    }
    // If $martial_art_filter is 'all', we don't add a filter, so all coaches are shown.
    // ***************************************************************

    $stmt_coaches = $pdo->prepare($coach_sql . " ORDER BY name ASC");
    $stmt_coaches->execute($coach_params);
    $coaches = $stmt_coaches->fetchAll(PDO::FETCH_ASSOC);
}


// --- 3. WEEKLY SCHEDULE DATA PREPARATION & NAVIGATION ---

// Determine the starting Monday for the week being viewed from URL parameter
$requested_date = $_GET['week_start'] ?? date('Y-m-d');

// Calculate the start (Monday) and end (Sunday) of the requested week
$start_of_week = date('Y-m-d', strtotime('monday this week', strtotime($requested_date)));
$end_of_week = date('Y-m-d', strtotime('sunday this week', strtotime($requested_date)));

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];


// --- NAVIGATION LINK GENERATION ---

$prev_week_start = date('Y-m-d', strtotime($start_of_week . ' - 7 days'));
$next_week_start = date('Y-m-d', strtotime($start_of_week . ' + 7 days'));

// Create a common URL parameter string to preserve the filters across navigation
$location_param = ($filter_location_id !== '0') ? '&location=' . urlencode($filter_location_id) : '';
// *** FIX 3: ADD MARTIAL ART PARAMETER ***
$martial_art_param = ($martial_art_filter !== 'all') ? '&martial_art=' . urlencode($martial_art_filter) : '';

$base_url = 'dashboard.php';


// Function to retrieve and format the schedule data
// *** FIX 4: ADD martial_art_filter TO FUNCTION SIGNATURE ***
function get_schedule_data($pdo, $location_id, $start_date, $end_date, $user_role, $user_id, $martial_art_filter)
{

    $params = [
        'start_date' => $start_date . ' 00:00:00',
        'end_date' => $end_date . ' 23:59:59'
    ];

    // 3.1. Fetch all events (slots) and their assignments for the week
    $sql = "
        SELECT
            se.id AS event_id,
            se.title AS class_title,
            se.start_datetime,
            se.end_datetime,
            ea.user_id,
            u.name AS coach_name,
            u.color_code,
            ea.position
        FROM
            schedule_events se
        LEFT JOIN 
            event_assignments ea ON se.id = ea.event_id
        LEFT JOIN
            users u ON ea.user_id = u.id
        WHERE
            se.start_datetime BETWEEN :start_date AND :end_date
    ";


    // Apply location filter
    if ($location_id !== '0') {
        $sql .= " AND se.location_id = :location_id";
        $params['location_id'] = $location_id;
    }

    // *** FIX 4b: APPLY MARTIAL ART FILTER TO QUERY ***
    if ($martial_art_filter !== 'all') {
        $sql .= " AND se.martial_art = :martial_art_filter";
        $params['martial_art_filter'] = $martial_art_filter;
    }
    // --------------------------------------------------

    // FIX FOR USER (COACH) VIEW: Filter the schedule to show only classes THIS COACH is assigned to
    if ($user_role === 'user') {
        $sql .= " AND se.id IN (
            SELECT DISTINCT event_id FROM event_assignments WHERE user_id = :coach_id
        )";
        $params['coach_id'] = $user_id; // Use the logged-in user's ID
    }


    $sql .= " ORDER BY se.start_datetime ASC, FIELD(ea.position, 'head', 'helper') ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3.2. Restructure the data into a grid format (rest of the function remains the same)
    $schedule_grid = [];

    foreach ($raw_data as $row) {
        $start_time = date('H:i', strtotime($row['start_datetime']));
        $day_of_week = date('l', strtotime($row['start_datetime'])); // Full day name (e.g., Tuesday)
        $date_key = date('Y-m-d', strtotime($row['start_datetime']));

        $key = $start_time . '|' . $row['class_title'];

        if (!isset($schedule_grid[$key])) {
            $schedule_grid[$key] = [
                'start_time' => $start_time,
                'class_title' => $row['class_title'],
                'data' => []
            ];
            foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
                $schedule_grid[$key]['data'][$day] = null;
            }
        }

        if (!isset($schedule_grid[$key]['data'][$day_of_week])) {
            $schedule_grid[$key]['data'][$day_of_week] = [
                'event_id' => $row['event_id'],
                'date' => $date_key,
                'end_time' => date('H:i', strtotime($row['end_datetime'])),
                'coaches' => []
            ];
        }

        if ($row['user_id']) {
            $schedule_grid[$key]['data'][$day_of_week]['coaches'][] = [
                'id' => $row['user_id'],
                'name' => $row['coach_name'],
                'position' => $row['position'],
                'color' => $row['color_code']
            ];
        }
    }

    $final_schedule = [];
    foreach ($schedule_grid as $row) {
        $has_event = false;
        foreach ($row['data'] as $day_data) {
            if ($day_data !== null) {
                $has_event = true;
                break;
            }
        }
        if ($has_event) {
            $final_schedule[] = $row;
        }
    }

    usort($final_schedule, function ($a, $b) {
        return strtotime($a['start_time']) - strtotime($b['start_time']);
    });

    return $final_schedule;
}

// Get the scheduled data for the current view
// *** FIX 5: PASS THE NEW FILTER VARIABLE TO THE FUNCTION ***
$schedule_data = get_schedule_data($pdo, $filter_location_id, $start_of_week, $end_of_week, $user_role, $user_id, $martial_art_filter);
?>

<!DOCTYPE html>
<html>

<head>
    <title>GB Schedule</title>
    <style>
        body {
            display: flex;
            font-family: sans-serif;
        }

        #sidebar {
            width: 300px;
            padding: 20px;
            background: #f4f4f4;
            flex-shrink: 0;

            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1000;
        }

        #calendar-container {
            flex-grow: 1;
            padding: 20px;
            overflow-x: auto;
        }

        /* Coach Draggable styling */
        .coach-draggable {
            background: white;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 5px;
            cursor: move;
            font-size: 0.9em;
        }

        /* Custom Schedule Styling */
        #custom-schedule {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        #custom-schedule thead th {
            background: #333;
            color: white;
            padding: 10px;
            border-left: 1px solid #555;
            text-align: center;
            width: calc((100% - 150px) / 7);
        }

        #custom-schedule tbody td {
            border: 1px solid #ddd;
            vertical-align: middle;
            padding: 5px;
            height: 80px;
            position: relative;
        }

        .time-header {
            width: 150px;
            background: #eee;
            border-right: 1px solid #ccc;
            font-weight: bold;
            text-align: center;
            padding: 5px;
            line-height: 1.2;
        }

        /* Class Slot Styling */
        .class-slot {
            background: #f8f8f8;
            border: 1px solid #ccc;
            min-height: 70px;
            padding: 5px;
        }

        .coach-assignment {
            font-size: 0.85em;
            padding: 2px 4px;
            margin-bottom: 2px;
            border-radius: 3px;
            color: #333;
            cursor: move;
        }

        .coach-head {
            font-weight: bold;
            background: #e0f7fa;
            border-left: 3px solid;
        }

        .coach-helper {
            background: #fff3e0;
            border-left: 3px solid;
        }

        .coaches-table-fi td {
            width: 50%;
        }
    </style>

    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
</head>

<body>

    <div id="sidebar">

        <div style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; background: #fff;">
            <label for="location-filter">Filter by Location:</label>
            <select id="location-filter" class="form-control" style="width: 100%;padding:5px;margin-top:10px;">
                <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo htmlspecialchars($loc['id']); ?>" <?= $loc['id'] == $filter_location_id ? 'selected' : '' ?>>
                        <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div style="margin-top: 15px; margin-bottom: 10px;">
                <label for="martial-art-filter">Filter by Martial Art:</label>
                <select id="martial-art-filter" style="width: 100%;padding:5px;margin-top:10px;">
                    <option value="bjj" <?= $martial_art_filter == 'bjj' ? 'selected' : '' ?>>BJJ</option>
                    <option value="mt" <?= $martial_art_filter == 'mt' ? 'selected' : '' ?>>Muay Thai</option>
                </select>
            </div>
        </div>

        <?php if ($is_admin): ?>
            <h3>Coaches (Admin View)</h3>

            <div id="external-events">
                <h5>Define Role Before Dragging</h5>

                <table class="coaches-table-fi" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="font-size: 0.8em;">Head Coach</th>
                            <th style="font-size: 0.8em;">Helper</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coaches as $coach): ?>
                            <tr>
                                <td style="padding: 5px; border: none;">
                                    <div class='coach-draggable coach-head'
                                        data-id='<?= $coach['id'] ?>'
                                        data-role='head'
                                        data-color='<?= $coach['color_code'] ?>'
                                        style="border: 2px solid <?= $coach['color_code'] ?>; background: white; padding: 5px; cursor: move;">
                                        <?= htmlspecialchars($coach['name']) ?>
                                    </div>
                                </td>

                                <td style="padding: 5px; border: none;">
                                    <div class='coach-draggable coach-helper'
                                        data-id='<?= $coach['id'] ?>'
                                        data-role='helper'
                                        data-color='<?= $coach['color_code'] ?>'
                                        style="border: 1px dashed #ccc; background: #f0f0f0; padding: 5px; cursor: move;">
                                        <?= htmlspecialchars($coach['name']) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <hr>
            <p><a href="users.php">Manage Users/Rates</a></p>
            <p><a href="classes.php">Manage Master Schedule</a></p>
            <p><a href="location_reports.php">View Payroll</a></p>
        <?php else: ?>
            <h3>Welcome, <?= htmlspecialchars($user_name) ?></h3>
        <?php endif; ?>

        <p><a href="reports.php">View My Financial Report</a></p>
        <p><a href="logout.php">Log Out</a></p>
    </div>

    <div id="calendar-container">

        <h1><?= htmlspecialchars($current_location_name) ?> Schedule - <? echo ($_GET['martial_art'] == 'mt' ? 'Muay Thai' : 'Jiu-Jitsu'); ?></h1>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">

            <a href="<?= $base_url ?>?week_start=<?= $prev_week_start ?><?= $location_param ?><?= $martial_art_param ?>"
                style="padding: 10px 15px; border: 1px solid #ccc; text-decoration: none; background: #f9f9f9; border-radius: 4px; color: #333;">
                &lt; Previous Week
            </a>

            <div style="display: flex; gap: 20px; align-items: center;">
                <h2 id="schedule-title" data-week-start="<?= $start_of_week ?>" style="margin: 0;">
                    Weekly View: <?= date('M d', strtotime($start_of_week)) ?> - <?= date('M d', strtotime($end_of_week)) ?>
                </h2>

                <div class="schedule-controls">
                    <?php if ($is_admin): ?>
                        <button id="clone-week-btn" class="btn btn-primary" style="cursor: pointer; padding: 10px 15px; border: 1px solid #ccc; text-decoration: none; background: #f9f9f9; border-radius: 4px; color: #333;">
                            <i class="fas fa-copy"></i> Clone to Next Week
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <a href="<?= $base_url ?>?week_start=<?= $next_week_start ?><?= $location_param ?><?= $martial_art_param ?>"
                style="padding: 10px 15px; border: 1px solid #ccc; text-decoration: none; background: #f9f9f9; border-radius: 4px; color: #333;">
                Next Week &gt;
            </a>
        </div>

        <?php if ($can_view_schedule): // Only display the table if the user is Admin or Coach 
        ?>
            <table id="custom-schedule" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th class="time-header">Time / Class</th>
                        <?php
                        $current_date = strtotime($start_of_week);
                        foreach ($days_of_week as $day):
                        ?>
                            <th style="padding: 10px; border-left: 1px solid #555;">
                                <?= $day ?><br>
                                <small><?= date('M d', $current_date) ?></small>
                            </th>
                        <?php
                            $current_date = strtotime('+1 day', $current_date);
                        endforeach;
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedule_data as $row_key => $row_data): ?>
                        <tr>
                            <td class="time-header">
                                <?= date('g:i A', strtotime($row_data['start_time'])) ?><br>
                                <small><?= htmlspecialchars($row_data['class_title']) ?></small>
                            </td>

                            <?php
                            foreach ($days_of_week as $day):
                                $event = $row_data['data'][$day];
                            ?>
                                <td class="schedule-cell"
                                    data-date="<?= $event ? $event['date'] : '' ?>"
                                    data-event-id="<?= $event ? $event['event_id'] : '' ?>"
                                    data-day="<?= $day ?>"
                                    style="border: 1px solid #ddd; vertical-align: top; padding: 5px; height: 80px;">

                                    <?php if ($event): ?>
                                        <div class="class-slot droppable-slot" id="slot-<?= $event['event_id'] ?>">
                                            <?php
                                            // List the coaches assigned to this slot
                                            foreach ($event['coaches'] as $coach):
                                                $class = 'coach-' . $coach['position']; // coach-head or coach-helper
                                            ?>
                                                <div class="coach-assignment <?= $class ?>" data-coach-id="<?= $coach['id'] ?>" data-event-id="<?= $event['event_id'] ?>" data-role="<?= $coach['position'] ?>" data-color="<?= $coach['color'] ?>"
                                                    style="border-left-color: <?= $coach['color'] ?>;">
                                                    <?= htmlspecialchars($coach['name']) ?> (<?= ucfirst($coach['position']) ?>)

                                                    <?php if ($is_admin): // Only show delete button for admins 
                                                    ?>
                                                        <span class="delete-coach"
                                                            style="float: right; cursor: pointer; color: #a00; margin-left: 5px; font-weight: bold;"
                                                            title="Remove Coach">&times;</span>
                                                    <?php endif; ?>
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
        <?php else: ?>
            <p>You do not have the required role to view the schedule.</p>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

    <script>
        $(document).ready(function() {
            var isAdmin = <?php echo json_encode($is_admin); ?>;
            var nextWeekStart = '<?php echo $next_week_start; ?>';
            var currentWeekStart = $('#schedule-title').data('week-start');

            // --- NEW: Selectors for the filters ---
            const locationFilter = $('#location-filter');
            const martialArtFilter = $('#martial-art-filter');

            // Helper function for capitalizing position
            function ucfirst(string) {
                return string.charAt(0).toUpperCase() + string.slice(1);
            }

            // Helper function to build the URL parameters, including the new Martial Art filter
            function buildParams() {
                // Get Location filter value (if selected)
                const locationParam = locationFilter.val() && locationFilter.val() !== '0' ? `&location=${locationFilter.val()}` : '';

                // Get Martial Art filter value (if not 'all')
                const martialArtParam = martialArtFilter.val() && martialArtFilter.val() !== 'all' ? `&martial_art=${martialArtFilter.val()}` : '';

                return locationParam + martialArtParam;
            }

            // --- 7. NEW FILTER CHANGE LOGIC ---
            // Combine both filter listeners into one simple call using buildParams()
            locationFilter.on('change', function() {
                window.location.href = 'dashboard.php?week_start=' + currentWeekStart + buildParams();
            });

            martialArtFilter.on('change', function() {
                window.location.href = 'dashboard.php?week_start=' + currentWeekStart + buildParams();
            });
            // --- END NEW FILTER LOGIC ---


            // --- 1. INITIALIZE DRAG (Coaches from Sidebar) ---
            function initDraggableCoaches() {
                $('.coach-draggable').draggable({
                    revert: 'invalid',
                    helper: 'clone',
                    opacity: 0.7,
                    zIndex: 1000
                });
            }

            // --- 2. INITIALIZE REASSIGNMENT DRAG (Coaches in Slots) ---
            function initCoachReassignment() {
                // Make the assigned coaches draggable for re-assignment
                $('.coach-assignment').draggable({
                    revert: 'invalid',
                    helper: 'clone',
                    opacity: 0.7,
                    zIndex: 1000,
                    cursor: 'move',
                    start: function(event, ui) {
                        $(this).data('original-slot', $(this).parent().attr('id'));
                        $(this).css('opacity', 0);
                    },
                    stop: function(event, ui) {
                        if ($(this).css('opacity') == 0) {
                            $(this).css('opacity', 1);
                        }
                    }
                });
            }

            // --- 3. INITIALIZE DROP (Class Slots) ---
            function initDroppableSlots() {
                $('.droppable-slot').droppable({
                    accept: '.coach-draggable, .coach-assignment',
                    hoverClass: 'ui-state-hover',
                    drop: function(event, ui) {
                        var droppedElement = ui.draggable;
                        var isNewAssignment = droppedElement.hasClass('coach-draggable');
                        var targetSlot = $(this);
                        var targetEventId = targetSlot.attr('id').replace('slot-', '');
                        var coachId = droppedElement.data('id') || droppedElement.data('coach-id');
                        var position = droppedElement.data('role') || (droppedElement.hasClass('coach-head') ? 'head' : 'helper');
                        var coachName = isNewAssignment ? droppedElement.text().trim() : droppedElement.text().trim().replace(/ \(Head\)|\(Helper\)/g, '').trim();
                        var color = droppedElement.data('color') || droppedElement.css('border-left-color');
                        var originalSlotId = droppedElement.data('original-slot');

                        // Check for Duplicates in the Target Slot
                        if (targetSlot.find('.coach-assignment[data-coach-id="' + coachId + '"]').length) {
                            alert(coachName + ' is already assigned to this class.');
                            if (!isNewAssignment) {
                                droppedElement.css('opacity', 1);
                            }
                            return;
                        }

                        // --- Determine Action ---
                        var actionType = isNewAssignment ? 'create_assignment' : 'reassign_assignment';

                        // 1. Prepare AJAX data
                        var ajaxData = {
                            action: actionType,
                            coach_id: coachId,
                            event_id: targetEventId, // The new slot
                            position: position
                        };

                        // Add old event ID if it's a reassign action
                        if (actionType === 'reassign_assignment') {
                            var oldEventId = originalSlotId.replace('slot-', '');
                            ajaxData.old_event_id = oldEventId;
                        }


                        // 2. AJAX call to database
                        $.ajax({
                            url: 'api/update_assignment.php', // Assuming this file exists and handles the DB logic
                            type: 'POST',
                            data: ajaxData,
                            success: function(response) {
                                // 3. SUCCESS: Update the UI
                                var newAssignmentHtml = '<div class="coach-assignment coach-' + position + '" data-coach-id="' + coachId + '" data-event-id="' + targetEventId + '" data-role="' + position + '" data-color="' + color + '" style="border-left-color: ' + color + ';">' +
                                    coachName + ' (' + ucfirst(position) + ')' +
                                    '<span class="delete-coach" style="float: right; cursor: pointer; color: #a00; margin-left: 5px; font-weight: bold;" title="Remove Coach">&times;</span>' +
                                    '</div>';

                                targetSlot.append(newAssignmentHtml); // Add to new slot

                                if (actionType === 'reassign_assignment') {
                                    droppedElement.remove();
                                } else {
                                    // If it was dragged from the sidebar, it's a new element, nothing to remove
                                }

                                // Re-initialize handlers for all dynamic elements
                                initCoachReassignment();
                                initDeleteHandler();
                            },
                            error: function(xhr) {
                                alert('Database Error: Failed to reassign coach. ' + xhr.responseText);
                                if (!isNewAssignment) {
                                    droppedElement.css('opacity', 1);
                                }
                            }
                        });
                    }
                });
            }

            // --- 4. DELETE Handler (remains the same) ---
            function initDeleteHandler() {
                $(document).off('click', '.delete-coach');

                $(document).on('click', '.delete-coach', function(e) {
                    e.stopPropagation();

                    var deleteButton = $(this);
                    var assignmentDiv = deleteButton.parent('.coach-assignment');
                    var coachId = assignmentDiv.data('coach-id');
                    var eventId = assignmentDiv.data('event-id');
                    var coachInfo = assignmentDiv.text().trim().replace(/×$/, '').trim();

                    if (!confirm('Are you sure you want to remove ' + coachInfo + ' from this class?')) {
                        return;
                    }

                    $.ajax({
                        url: 'api/update_assignment.php',
                        type: 'POST',
                        data: {
                            action: 'delete_assignment',
                            coach_id: coachId,
                            event_id: eventId
                        },
                        success: function(response) {
                            assignmentDiv.fadeOut(300, function() {
                                $(this).remove();
                            });
                        },
                        error: function(xhr) {
                            alert('Database Error: Failed to delete assignment. ' + xhr.responseText);
                        }
                    });
                });
            }

            if (isAdmin) {
                // --- Initial Load for Admin ---
                initDraggableCoaches();
                initCoachReassignment();
                initDroppableSlots();
                initDeleteHandler();

                // --- CLONE WEEK LOGIC (Admin only) ---
                document.getElementById('clone-week-btn').addEventListener('click', async () => {

                    const sourceDate = $('#schedule-title').data('week-start');

                    if (!sourceDate) {
                        alert("Could not determine the start date of the current week.");
                        return;
                    }

                    // Confirmation is CRUCIAL to prevent accidental cloning
                    if (!confirm(`Are you sure you want to clone the schedule from the week starting ${sourceDate} to the following week?`)) {
                        return;
                    }

                    try {
                        const response = await fetch('api/clone_classes.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                sourceWeekStart: sourceDate
                            })
                        });

                        const result = await response.json();

                        if (response.ok) {
                            console.log('Clone Result:', result);

                            let alertMessage = `Success! Cloned ${result.clonedCount} classes to the next week.\n\n`;

                            if (result.debug && result.debug.length > 0) {
                                alertMessage += "--- DEBUG LOG ---\n" + result.debug.join('\n');
                            } else if (result.message) {
                                alertMessage += "Message: " + result.message;
                            }


                            alert(alertMessage);

                            // Navigate to the next week, preserving ALL current filters (location and martial art)
                            // *** FIX 8: Use buildParams() and nextWeekStart for navigation after cloning ***
                            if (result.clonedCount > 0) {
                                window.location.href = 'dashboard.php?week_start=' + nextWeekStart + buildParams();
                            }

                        } else {
                            let errorMessage = `Error cloning schedule: ${result.message || 'Unknown error'}\n\n`;
                            if (result.debug && result.debug.length > 0) {
                                errorMessage += "--- DEBUG LOG ---\n" + result.debug.join('\n');
                            }
                            alert(errorMessage);
                        }
                    } catch (error) {
                        console.error('Network error during cloning:', error);
                        alert('An unexpected error occurred. Check console.');
                    }
                });
            }
        });
    </script>
</body>

</html>