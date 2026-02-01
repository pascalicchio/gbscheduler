<?php
// generate_schedule.php
require 'db.php';

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);
    $location_id = $_POST['location_id'];

    // 1. Get Templates for this location
    $stmt = $pdo->prepare("SELECT * FROM class_templates WHERE location_id = ?");
    $stmt->execute([$location_id]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    $skipped_count = 0;

    // 2. Loop through every day in the range
    while ($start_date <= $end_date) {
        $current_day_name = $start_date->format('l'); // e.g., "Monday"
        $current_date_str = $start_date->format('Y-m-d');

        foreach ($templates as $tpl) {
            // If the template day matches the current loop day
            if ($tpl['day_of_week'] == $current_day_name) {

                // Construct full DATETIME for start and end
                $start_dt = $current_date_str . ' ' . $tpl['start_time'];
                $end_dt   = $current_date_str . ' ' . $tpl['end_time'];

                // Create Title (e.g., "BJJ - Fundamentals")
                $title = strtoupper($tpl['martial_art']) . ($tpl['class_name'] ? ' - ' . $tpl['class_name'] : '');

                // --- DUPLICATE CHECK: STEP 3 ---
                // Check if an event already exists for this exact time, location, and martial art type
                $check_sql = "
                    SELECT COUNT(*) FROM schedule_events 
                    WHERE location_id = ? AND martial_art = ? AND start_datetime = ?
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$location_id, $tpl['martial_art'], $start_dt]);

                if ($check_stmt->fetchColumn() > 0) {
                    // Event already exists, skip insertion
                    $skipped_count++;
                    continue;
                }

                // Insert into Real Schedule
                $sql = "INSERT INTO schedule_events (location_id, martial_art, title, start_datetime, end_datetime) 
                        VALUES (?, ?, ?, ?, ?)";
                $ins = $pdo->prepare($sql);
                $ins->execute([$location_id, $tpl['martial_art'], $title, $start_dt, $end_dt]);

                $count++;
            }
        }
        // Move to next day
        $start_date->modify('+1 day');
    }

    $message = "Success! Generated $count new classes.";
    if ($skipped_count > 0) {
        $message .= " Skipped $skipped_count existing classes.";
    }
}

// Fetch locations for dropdown
$locations = $pdo->query("SELECT * FROM locations")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Generate Schedule</title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            max-width: 500px;
            margin: auto;
        }

        .alert {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        button {
            background: #6610f2;
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
        }

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
        }

        .flatpickr-presets button:hover {
            background: #e9ecef;
            border-color: #6610f2;
        }
    </style>
</head>

<body>

    <h2>Generate Calendar Events</h2>
    <p>Select a date range to fill your calendar based on your Master Schedule.</p>

    <?php if ($message): ?>
        <div class="alert"><?= $message ?> <a href="dashboard.php">Go to Calendar</a></div>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom:15px;">
            <label>Location:</label><br>
            <select name="location_id" style="width:100%; padding:8px;">
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"><?= $loc['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:10px; margin-bottom:15px;">
            <div style="flex:1">
                <label>From Date:</label>
                <input type="text" name="start_date" id="start_date" required style="width:100%; padding:8px;" readonly>
            </div>
            <div style="flex:1">
                <label>To Date:</label>
                <input type="text" name="end_date" id="end_date" required style="width:100%; padding:8px;" readonly>
            </div>
        </div>

        <button type="submit">Generate Events</button>
        <a href="classes.php" style="margin-left:10px;">Back</a>
    </form>

<script>
document.addEventListener('DOMContentLoaded', function() {
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
            case 'next-month':
                start = new Date(today.getFullYear(), today.getMonth() + 1, 1);
                end = new Date(today.getFullYear(), today.getMonth() + 2, 0);
                break;
            case 'this-week':
                const dayOfWeek = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - dayOfWeek);
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
            case 'next-week':
                const nextWeekDay = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - nextWeekDay + 7);
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
            { label: 'Next Month', value: 'next-month' },
            { label: 'This Week', value: 'this-week' },
            { label: 'Next Week', value: 'next-week' }
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

    const startPicker = flatpickr('#start_date', {
        ...fpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                endPicker.set('minDate', selectedDates[0]);
            }
        }
    });

    const endPicker = flatpickr('#end_date', {
        ...fpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                startPicker.set('maxDate', selectedDates[0]);
            }
        }
    });
});
</script>

</body>

</html>