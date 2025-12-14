<?php
// class_form.php
require 'db.php';
$locations = $pdo->query("SELECT * FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Define days of the week consistently
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<!DOCTYPE html>
<html>

<head>
    <title>Add Class Template</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            max-width: 500px;
            margin: auto;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        select,
        input {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }

        button {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            cursor: pointer;
        }

        /* Style for the multiple select box to make it clear */
        select[multiple] {
            height: 150px;
            /* Give it enough height to show options */
            border: 1px solid #ccc;
        }

        select[multiple] option:checked {
            background-color: #e6f7ff;
            /* Highlight selected options */
        }
    </style>
</head>

<body>

    <h2>Add Standard Class Slot</h2>
    <form action="class_save.php" method="POST">

        <div class="form-group">
            <label>Location</label>
            <select name="location_id" required>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Day(s) of Week (Hold Ctrl/Cmd to select multiple)</label>
            <select name="days_of_week[]" multiple required>
                <?php foreach ($days_of_week as $day): ?>
                    <option value="<?= $day ?>"><?= $day ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Start Time</label>
            <input type="time" name="start_time" required>
        </div>

        <div class="form-group">
            <label>End Time</label>
            <input type="time" name="end_time" required>
        </div>

        <div class="form-group">
            <label>Martial Art</label>
            <select name="martial_art">
                <option value="bjj">Jiu Jitsu (BJJ)</option>
                <option value="mt">Muay Thai (MT)</option>
            </select>
        </div>

        <div class="form-group">
            <label>Class Name (Optional)</label>
            <input type="text" name="class_name" placeholder="e.g. Fundamentals, Advanced">
        </div>

        <button type="submit">Save Template(s)</button>
        <a href="classes.php" style="margin-left:10px;">Cancel</a>
    </form>
</body>

</html>