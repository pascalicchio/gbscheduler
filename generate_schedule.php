<?php
// generate_schedule.php
require_once 'includes/config.php';

requireAuth(['admin', 'manager']);

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

$pageTitle = 'Generate Schedule | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 20px; }

    [x-cloak] { display: none !important; }

    /* Page Header */
    .page-header {
        max-width: 600px;
        margin: 0 auto 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .page-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
    }

    .page-header h2 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    @media (min-width: 768px) {
        .page-header h2 {
            font-size: 1.75rem;
        }
    }

    /* Navigation Menu */
    .nav-menu {
        position: relative;
    }

    .nav-menu-btn {
        padding: 10px 18px;
        background: white;
        color: #2c3e50;
        border: 2px solid #e8ecf2;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.25s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .nav-menu-btn:hover {
        background: rgba(0, 201, 255, 0.05);
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
    }

    .nav-menu-btn i {
        font-size: 1.1rem;
    }

    .nav-dropdown {
        position: absolute;
        top: calc(100% + 2px);
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(0, 201, 255, 0.2);
        min-width: 220px;
        z-index: 100;
        overflow: hidden;
        padding-top: 6px;
    }

    .nav-dropdown::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
    }

    .nav-dropdown a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        text-decoration: none;
        color: #2c3e50;
        font-weight: 500;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        border-left: 3px solid transparent;
    }

    .nav-dropdown a i {
        width: 18px;
        text-align: center;
        font-size: 1rem;
        color: #6c757d;
    }

    .nav-dropdown a:hover {
        background: linear-gradient(to right, rgba(0, 201, 255, 0.08), transparent);
        border-left-color: rgb(0, 201, 255);
        padding-left: 24px;
    }

    .nav-dropdown a:hover i {
        color: rgb(0, 201, 255);
    }

    .nav-dropdown a.active {
        background: linear-gradient(to right, rgba(0, 201, 255, 0.12), transparent);
        border-left-color: rgb(0, 201, 255);
        color: rgb(0, 201, 255);
        font-weight: 600;
    }

    .nav-dropdown a.active i {
        color: rgb(0, 201, 255);
    }

    .nav-dropdown a.logout {
        border-top: 1px solid #e8ecf2;
        margin-top: 6px;
        color: #dc3545;
    }

    .nav-dropdown a.logout:hover {
        background: rgba(220, 53, 69, 0.08);
        border-left-color: #dc3545;
    }

    .nav-dropdown a.logout i {
        color: #dc3545;
    }

    /* Form Card */
    .form-card {
        max-width: 600px;
        margin: 0 auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        padding: 30px;
    }

    .form-card p {
        color: #6c757d;
        margin: 0 0 24px;
        font-size: 0.95rem;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #2c3e50;
        margin-bottom: 8px;
    }

    .form-card input, .form-card select {
        width: 100%;
        padding: 12px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        box-sizing: border-box;
        font-size: 0.95rem;
        font-weight: 500;
        background: white;
        transition: all 0.25s ease;
        font-family: inherit;
    }

    .form-card input:focus, .form-card select:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .date-row {
        display: flex;
        gap: 16px;
    }

    .date-row .form-group {
        flex: 1;
    }

    .form-actions {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-top: 24px;
    }

    .btn-generate {
        padding: 12px 24px;
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-generate:hover {
        background-image: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.3);
    }

    .btn-cancel {
        padding: 12px 18px;
        background: white;
        color: #2c3e50;
        border: 2px solid #e8ecf2;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
    }

    .btn-cancel:hover {
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
    }

    .alert {
        max-width: 600px;
        margin: 0 auto 20px;
        background: #d4edda;
        color: #155724;
        padding: 14px 18px;
        border-radius: 10px;
        border: 1px solid #c3e6cb;
        font-size: 0.95rem;
    }

    .alert a {
        color: #155724;
        font-weight: 700;
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
        color: #2c3e50;
    }

    .flatpickr-presets button:hover {
        background: #e9ecef;
        border-color: rgb(0, 201, 255);
    }
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-calendar-plus"></i> Generate Schedule</h2>
    <div class="nav-menu" x-data="{ open: false }" @mouseenter="if(window.innerWidth >= 768) open = true" @mouseleave="if(window.innerWidth >= 768) open = false">
        <button @click="if(window.innerWidth < 768) open = !open" class="nav-menu-btn">
            <i class="fas fa-bars"></i>
            <span>Menu</span>
        </button>
        <div x-show="open" @click.away="if(window.innerWidth < 768) open = false" @mouseenter="open = true" x-cloak class="nav-dropdown">
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

<?php if ($message): ?>
    <div class="alert"><?= $message ?> <a href="dashboard.php">Go to Calendar</a></div>
<?php endif; ?>

<div class="form-card">
    <p>Select a date range to fill your calendar based on your Master Schedule.</p>

    <form method="POST">
        <div class="form-group">
            <label>Location</label>
            <select name="location_id">
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"><?= $loc['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="date-row">
            <div class="form-group">
                <label>From Date</label>
                <input type="text" name="start_date" id="start_date" required readonly>
            </div>
            <div class="form-group">
                <label>To Date</label>
                <input type="text" name="end_date" id="end_date" required readonly>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-generate"><i class="fas fa-cogs"></i> Generate Events</button>
            <a href="classes.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

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

<?php require_once 'includes/footer.php'; ?>