<?php
// reports.php - CORRECTED FOR ROLE-BASED RATES AND 1-HOUR MINIMUM PAY AND ROUNDED TOTALS
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
require 'db.php';

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['user_role'] === 'admin');

// 1. Initial Variables and Filters
$selected_coach_id = $is_admin && isset($_POST['coach_id']) ? $_POST['coach_id'] : $user_id;
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-t');

$report_data = [];
$total_pay = 0.00;
$total_hours = 0.00; // This will now represent the sum of rounded/capped hours

// 2. Fetch Coaches for Admin Filter
$all_coaches = [];
$selected_coach_name = "N/A";
if ($is_admin) {
    $stmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
    $all_coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. Generate Report Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !$is_admin) {

    // SQL to fetch all assignments, class times, and coach rates/position for the selected coach
    $sql = "
        SELECT 
            e.title,
            e.start_datetime,
            e.end_datetime,
            l.name as location_name,
            u.name as coach_name,
            u.rate_head_coach,
            u.rate_helper,
            a.position
        FROM event_assignments a
        JOIN schedule_events e ON a.event_id = e.id
        JOIN locations l ON e.location_id = l.id
        JOIN users u ON a.user_id = u.id
        WHERE a.user_id = ?
          AND e.start_datetime >= ? 
          AND e.start_datetime <= ?
        ORDER BY e.start_datetime
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selected_coach_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If assignments were found, get the coach name and rates from the first result
    if (!empty($assignments)) {
        $coach_info = $assignments[0];
        $selected_coach_name = $coach_info['coach_name'];
    }

    foreach ($assignments as $assignment) {
        $start_time = new DateTime($assignment['start_datetime']);
        $end_time = new DateTime($assignment['end_datetime']);
        $interval = $start_time->diff($end_time);

        // Calculate class duration in seconds
        $duration_seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        // ----------------------------------------------------
        // LOGIC: 1-HOUR MINIMUM PAY & ROUNDED HOURS FOR DISPLAY
        // ----------------------------------------------------

        // 1. Calculate actual duration in fractional hours 
        $actual_duration_hours = $duration_seconds / 3600;

        // 2. Determine the duration to use for PAY and DISPLAY (1.00 hour minimum)
        // This is the key value for both payment calculation and the displayed "Hours".
        $pay_duration_hours = ($duration_seconds < 3600) ? 1.00 : $actual_duration_hours;

        // ----------------------------------------------------

        // DETERMINE THE CORRECT HOURLY RATE BASED ON ASSIGNED POSITION
        $position = $assignment['position'];
        if ($position === 'head') {
            $hourly_rate = $assignment['rate_head_coach'];
        } elseif ($position === 'helper') {
            $hourly_rate = $assignment['rate_helper'];
        } else {
            // Fallback for safety
            $hourly_rate = 0.00;
        }

        $pay = $pay_duration_hours * $hourly_rate;

        // ** CRITICAL CHANGE: Use $pay_duration_hours for the total summation **
        $total_hours += $pay_duration_hours;
        $total_pay += $pay;

        $report_data[] = [
            'date' => $start_time->format('Y-m-d'),
            'time' => $start_time->format('H:i') . ' - ' . $end_time->format('H:i'),
            'location' => $assignment['location_name'],
            'class' => $assignment['title'],
            'position' => ucfirst($position),
            'rate' => $hourly_rate,
            // ** CRITICAL CHANGE: Use $pay_duration_hours for the individual row duration **
            'duration' => $pay_duration_hours,
            'pay' => $pay
        ];
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Reports</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
        }

        .summary {
            background: #e0f7fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
        }

        .summary div {
            flex: 1;
            text-align: center;
            align-content: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
        }

        form {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ccc;
            background: #fff;
        }
    </style>
</head>

<body>

    <a href="/dashboard">Go Back Home</a>
    <h2>💰 Coaching Reports</h2>

    <form method="POST">
        <?php if ($is_admin): ?>
            <label for="coach_id">Select Coach:</label>
            <select name="coach_id">
                <?php foreach ($all_coaches as $coach): ?>
                    <option value="<?= $coach['id'] ?>" <?= $coach['id'] == $selected_coach_id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($coach['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <label for="start_date" style="margin-left: 15px;">From:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>

        <label for="end_date" style="margin-left: 15px;">To:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>

        <button type="submit" style="margin-left: 15px;">Generate Report</button>
    </form>

    <?php if (!empty($assignments)): ?>

        <div class="summary">
            <div>
                <h3>Coach: <?= htmlspecialchars($selected_coach_name) ?></h3>
            </div>
            <div>
                <h3>Total Hours Worked</h3>
                <p style="font-size: 1.5em; font-weight: bold;"><?= number_format($total_hours, 2) ?> hours</p>
            </div>
            <div>
                <h3>Total Estimated Pay</h3>
                <p style="font-size: 1.5em; font-weight: bold; color: green;">$<?= number_format($total_pay, 2) ?></p>
            </div>
        </div>

        <h3>Detail: <?= $start_date ?> to <?= $end_date ?></h3>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Class</th>
                    <th>Location</th>
                    <th>Role</th>
                    <th>Rate</th>
                    <th>Hours</th>
                    <th>Pay</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $row): ?>
                    <tr>
                        <td><?= $row['date'] ?></td>
                        <td><?= $row['time'] ?></td>
                        <td><?= htmlspecialchars($row['class']) ?></td>
                        <td><?= htmlspecialchars($row['location']) ?></td>
                        <td><?= $row['position'] ?></td>
                        <td>$<?= number_format($row['rate'], 2) ?></td>
                        <td><?= number_format($row['duration'], 2) ?></td>
                        <td>$<?= number_format($row['pay'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <p>No assignments found for the selected criteria.</p>
    <?php endif; ?>

</body>

</html>