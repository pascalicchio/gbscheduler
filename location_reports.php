<?php
// location_reports.php - ADMIN SUMMARY OF COACH PAY GROUPED BY LOCATION
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: index.php"); // Redirect non-admins or unauthenticated users
    exit();
}
require 'db.php';

$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-t');

$report_data_by_location = [];
$grand_total_pay = 0.00;
$grand_total_hours = 0.00;

// 1. SQL to fetch all assignments, class times, coach rates/position, and location for the date range
// We fetch details first, then group and calculate in PHP.
$sql = "
    SELECT 
        e.start_datetime,
        e.end_datetime,
        l.name as location_name,
        u.id as coach_id,
        u.name as coach_name,
        u.rate_head_coach,
        u.rate_helper,
        a.position
    FROM event_assignments a
    JOIN schedule_events e ON a.event_id = e.id
    JOIN locations l ON e.location_id = l.id
    JOIN users u ON a.user_id = u.id
    WHERE e.start_datetime >= ? 
      AND e.start_datetime <= ?
    ORDER BY l.name, u.name, e.start_datetime
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($assignments)) {

    // 2. Process data and calculate pay/hours for each coach
    $coaches_data = [];

    foreach ($assignments as $assignment) {
        $start_time = new DateTime($assignment['start_datetime']);
        $end_time = new DateTime($assignment['end_datetime']);
        $interval = $start_time->diff($end_time);

        // Calculate class duration in seconds
        $duration_seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        // 1-HOUR MINIMUM PAY & ROUNDED HOURS
        $actual_duration_hours = $duration_seconds / 3600;
        $pay_duration_hours = ($duration_seconds < 3600) ? 1.00 : $actual_duration_hours;

        // DETERMINE HOURLY RATE
        $position = $assignment['position'];
        if ($position === 'head') {
            $hourly_rate = $assignment['rate_head_coach'];
        } elseif ($position === 'helper') {
            $hourly_rate = $assignment['rate_helper'];
        } else {
            $hourly_rate = 0.00;
        }

        $pay = $pay_duration_hours * $hourly_rate;

        $location = $assignment['location_name'];
        $coach_id = $assignment['coach_id'];

        // Initialize coach data if not exists
        if (!isset($coaches_data[$location][$coach_id])) {
            $coaches_data[$location][$coach_id] = [
                'name' => $assignment['coach_name'],
                'total_hours' => 0.00,
                'total_pay' => 0.00,
            ];
        }

        // Aggregate hours and pay (using the rounded/capped values)
        $coaches_data[$location][$coach_id]['total_hours'] += $pay_duration_hours;
        $coaches_data[$location][$coach_id]['total_pay'] += $pay;

        $grand_total_hours += $pay_duration_hours;
        $grand_total_pay += $pay;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Admin Location Reports</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
        }

        .grand-summary {
            background: #ffecb3;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
        }

        .location-group {
            margin-top: 30px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            background: #f9f9f9;
        }

        .location-header {
            background-color: #4a148c;
            /* Deep Purple */
            color: white;
            padding: 10px;
            margin: -15px -15px 15px -15px;
            border-radius: 5px 5px 0 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #e0e0e0;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #eceff1;
        }

        .coach-total {
            font-weight: bold;
            background-color: #fff3e0;
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
    <h2>📍 Admin Payroll Summary by Location</h2>

    <form method="POST">
        <label for="start_date">From:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>

        <label for="end_date" style="margin-left: 15px;">To:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>

        <button type="submit" style="margin-left: 15px;">Generate Report</button>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($assignments)): ?>

        <div class="grand-summary">
            <h3>Summary for <?= $start_date ?> to <?= $end_date ?></h3>
            <p style="font-size: 1.2em;">Total Payable Hours: <strong style="color: #d84315;"><?= number_format($grand_total_hours, 2) ?></strong></p>
            <p style="font-size: 1.5em; font-weight: bold;">Grand Total Payroll: <strong style="color: green;">$<?= number_format($grand_total_pay, 2) ?></strong></p>
        </div>

        <?php if (!empty($coaches_data)): ?>
            <?php foreach ($coaches_data as $location_name => $location_coaches): ?>
                <div class="location-group">
                    <h3 class="location-header"><?= htmlspecialchars($location_name) ?></h3>

                    <table>
                        <thead>
                            <tr>
                                <th>Coach Name</th>
                                <th>Total Payable Hours</th>
                                <th>Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $location_total_hours = 0.00;
                            $location_total_pay = 0.00;
                            ?>
                            <?php foreach ($location_coaches as $coach): ?>
                                <tr>
                                    <td><?= htmlspecialchars($coach['name']) ?></td>
                                    <td><?= number_format($coach['total_hours'], 2) ?></td>
                                    <td>$<?= number_format($coach['total_pay'], 2) ?></td>
                                </tr>
                                <?php
                                $location_total_hours += $coach['total_hours'];
                                $location_total_pay += $coach['total_pay'];
                                ?>
                            <?php endforeach; ?>

                            <tr class="coach-total">
                                <td>**Total for <?= htmlspecialchars($location_name) ?>**</td>
                                <td>**<?= number_format($location_total_hours, 2) ?>**</td>
                                <td>**$<?= number_format($location_total_pay, 2) ?>**</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No coach assignments found for the selected date range.</p>
        <?php endif; ?>

    <?php elseif (!empty($assignments)): ?>
        <p>No assignments found for the current month.</p>
    <?php endif; ?>

</body>

</html> 