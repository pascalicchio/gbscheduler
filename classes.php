<?php
// classes.php
require 'db.php';

// Fetch classes with location names
$sql = "SELECT c.*, l.name as location_name 
        FROM class_templates c 
        JOIN locations l ON c.location_id = l.id 
        ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time";
$stmt = $pdo->query($sql);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Master Schedule</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
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

        .btn {
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 4px;
            color: white;
        }

        .btn-add {
            background-color: #28a745;
        }

        .btn-gen {
            background-color: #6610f2;
            margin-left: 10px;
        }

        .btn-del {
            background-color: #dc3545;
        }

        .tag {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            color: white;
        }

        .bjj {
            background-color: #007bff;
        }

        .mt {
            background-color: #dc3545;
        }
    </style>
</head>

<body>

    <a href="/dashboard">Go Back Home</a>
    <h2>Master Weekly Schedule</h2>
    <p>Define your standard classes here. Use the "Generate" button to push these to the actual calendar.</p>

    <a href="class_form.php" class="btn btn-add">+ Add Standard Class</a>
    <a href="generate_schedule.php" class="btn btn-gen">⚡ Generate Calendar Events</a>

    <table>
        <thead>
            <tr>
                <th>Day</th>
                <th>Time</th>
                <th>Location</th>
                <th>Type</th>
                <th>Class Name</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($classes as $class): ?>
                <tr>
                    <td><?= $class['day_of_week'] ?></td>
                    <td><?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?></td>
                    <td><?= htmlspecialchars($class['location_name']) ?></td>
                    <td>
                        <span class="tag <?= $class['martial_art'] ?>">
                            <?= strtoupper($class['martial_art']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($class['class_name']) ?></td>
                    <td>
                        <a href="class_delete.php?id=<?= $class['id'] ?>" class="btn btn-del" onclick="return confirm('Delete this template?')">Remove</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>

</html>