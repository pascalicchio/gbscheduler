<?php
// classes.php - MANAGE CLASS TEMPLATES (WITH REFRESH FIX)
require_once 'includes/config.php';

// Require admin access
requireAuth(['admin']);

// --- 1. HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // DEACTIVATE (Soft delete - keeps payment history intact)
    if (isset($_POST['action']) && $_POST['action'] === 'deactivate') {
        $id = $_POST['id'];
        $deactivate_date = !empty($_POST['deactivate_date']) ? $_POST['deactivate_date'] : date('Y-m-d');
        $stmt = $pdo->prepare("UPDATE class_templates SET is_active = 0, deactivated_at = ? WHERE id = ?");
        if ($stmt->execute([$deactivate_date, $id])) {
            $formatted_date = date('M j, Y', strtotime($deactivate_date));
            setFlash("Class will be deactivated starting $formatted_date. Payment history preserved.", 'success');
        }
    }

    // REACTIVATE
    elseif (isset($_POST['action']) && $_POST['action'] === 'reactivate') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("UPDATE class_templates SET is_active = 1, deactivated_at = NULL WHERE id = ?");
        if ($stmt->execute([$id])) {
            setFlash("Class reactivated successfully!", 'success');
        }
    }

    // ADD OR UPDATE
    elseif (isset($_POST['action']) && $_POST['action'] === 'save') {
        $id = $_POST['template_id'] ?? '';
        $name = $_POST['class_name'];
        $start = $_POST['start_time'];
        $end = $_POST['end_time'];
        $loc = $_POST['location_id'];
        $art = $_POST['martial_art'];
        $days = $_POST['days'] ?? [];

        if ($id) {
            // UPDATE
            $dayToSave = !empty($days) ? $days[0] : $_POST['original_day'];
            $sql = "UPDATE class_templates SET class_name=?, day_of_week=?, start_time=?, end_time=?, location_id=?, martial_art=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$name, $dayToSave, $start, $end, $loc, $art, $id])) {
                setFlash("Class updated successfully.");
            }
        } else {
            // BULK INSERT
            if (!empty($days)) {
                $sql = "INSERT INTO class_templates (class_name, day_of_week, start_time, end_time, location_id, martial_art) VALUES (?,?,?,?,?,?)";
                $stmt = $pdo->prepare($sql);
                $count = 0;
                foreach ($days as $day) {
                    $stmt->execute([$name, $day, $start, $end, $loc, $art]);
                    $count++;
                }
                setFlash("$count classes added successfully.");
            } else {
                setFlash("Please select at least one day.", 'error');
            }
        }
    }

    // PRG REDIRECT (Prevents refresh resubmission)
    // Preserve the show_inactive parameter if it was set
    $redirect = "classes.php";
    if (isset($_GET['show_inactive']) && $_GET['show_inactive'] == '1') {
        $redirect .= "?show_inactive=1";
    }
    header("Location: $redirect");
    exit();
}

// --- 2. DISPLAY FLASH MESSAGE ---
$msg = getFlash();

// --- 3. FETCH DATA ---
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Check if showing inactive classes
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] == '1';

// Fetch Class Templates Grouped by Location
$sql_classes = "
    SELECT ct.*, l.name as loc_name
    FROM class_templates ct
    JOIN locations l ON ct.location_id = l.id
    " . ($show_inactive ? "" : "WHERE ct.is_active = 1") . "
    ORDER BY l.name, ct.is_active DESC, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time
";
$all_classes = $pdo->query($sql_classes)->fetchAll(PDO::FETCH_ASSOC);

// Count inactive classes
$inactive_count = $pdo->query("SELECT COUNT(*) FROM class_templates WHERE is_active = 0")->fetchColumn();

// Group classes by Location ID
$grouped_classes = [];
foreach ($all_classes as $c) {
    $grouped_classes[$c['location_id']]['name'] = $c['loc_name'];
    $grouped_classes[$c['location_id']]['classes'][] = $c;
}

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Page setup
$pageTitle = 'Manage Classes | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
        /* --- PREMIUM DESIGN SYSTEM --- */
        :root {
            --primary: #007bff;
            --primary-dark: #0056b3;
            --secondary: #2c3e50;
            --bg: #f4f6f9;
            --border: #e1e4e8;
            --success: #28a745;
            --danger: #dc3545;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg);
            padding: 20px;
            color: #333;
            margin: 0;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .nav-link {
            text-decoration: none;
            color: var(--secondary);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 10px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .nav-link:hover {
            color: var(--primary);
        }

        .main-layout {
            display: flex;
            gap: 25px;
            align-items: flex-start;
        }

        /* LEFT COLUMN: FORM */
        .form-card {
            flex: 0 0 350px;
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 20px;
        }

        .form-card h3 {
            margin-top: 0;
            color: var(--secondary);
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 0.9em;
            color: #555;
        }

        input[type="text"],
        input[type="time"],
        select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 0.95em;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        /* Checkbox Grid */
        .days-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 15px;
        }

        .day-option {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            cursor: pointer;
        }

        .day-option input {
            width: auto;
            margin: 0;
            cursor: pointer;
        }

        .row {
            display: flex;
            gap: 10px;
        }

        .col {
            flex: 1;
        }

        button.btn-save {
            width: 100%;
            padding: 12px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.2s;
        }

        button.btn-save:hover {
            background: #218838;
        }

        button.btn-reset {
            width: 100%;
            padding: 10px;
            background: #e2e6ea;
            color: #555;
            border: 1px solid #d6d8db;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
            font-weight: bold;
        }

        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* RIGHT COLUMN: LIST */
        .list-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .loc-group {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border: 1px solid #e1e4e8;
        }

        .loc-header {
            background: var(--secondary);
            color: white;
            padding: 15px 20px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1em;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }

        th {
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85em;
            padding: 12px 20px;
            text-align: left;
            border-bottom: 2px solid #eee;
        }

        td {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
        }

        tr:hover td {
            background: #fafafa;
        }

        /* Filter Inputs inside Table Header */
        .filter-row th {
            background: #fff;
            padding: 10px 20px;
            border-bottom: 2px solid #e1e4e8;
        }

        .table-filter {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
            margin: 0;
        }

        .tag {
            font-size: 0.8em;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }

        .tag-bjj {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .tag-mt {
            background: #ffebee;
            color: #c62828;
        }

        .tag-mma {
            background: #fff3e0;
            color: #e65100;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            color: #aaa;
            transition: color 0.2s;
            font-size: 1.1em;
        }

        .btn-edit:hover {
            color: var(--primary);
        }

        .btn-del:hover {
            color: var(--danger);
        }

        .btn-reactivate:hover {
            color: var(--success);
        }

        /* Inactive class styling */
        .row-inactive {
            background: #f8f8f8 !important;
            opacity: 0.6;
        }

        .row-inactive td {
            color: #999 !important;
        }

        .tag-inactive {
            background: #e0e0e0;
            color: #666;
            font-size: 0.7em;
            margin-left: 5px;
        }

        /* Toggle switch */
        .toggle-container {
            display: flex;
            align-items: center;
            background: #e9ecef;
            padding: 4px;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .toggle-container a,
        .toggle-container span.toggle-btn {
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .toggle-active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }

        .toggle-inactive-btn {
            background: transparent;
            color: #666;
        }

        .toggle-inactive-btn:hover {
            background: rgba(0,0,0,0.05);
        }

        .inactive-badge {
            background: #ffc107;
            color: #333;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75em;
            font-weight: bold;
        }

        /* Deactivation modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-box {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 90%;
        }

        .modal-box h3 {
            margin-top: 0;
            color: var(--secondary);
        }

        .modal-box p {
            color: #666;
            font-size: 0.9em;
        }

        .modal-box input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 15px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-cancel {
            background: #e9ecef;
            color: #666;
        }

        .btn-confirm {
            background: var(--danger);
            color: white;
        }

        .deactivation-date {
            font-size: 0.75em;
            color: #e65100;
            display: block;
            margin-top: 2px;
        }

        @media (max-width: 900px) {
            .main-layout {
                flex-direction: column;
            }

            .form-card {
                width: 100%;
                position: static;
            }
        }
CSS;

require_once 'includes/header.php';
?>

    <script>
        // Persist filter preference
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            const hasShowInactive = urlParams.has('show_inactive');
            const savedPref = localStorage.getItem('classes_show_inactive');

            // Only redirect if no URL param and we have a saved preference for showing inactive
            if (!hasShowInactive && savedPref === '1') {
                window.location.href = 'classes.php?show_inactive=1';
            }
        })();
    </script>

    <div class="top-bar">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h2 style="margin:0; color:#2c3e50;">Manage Classes</h2>
        <div class="toggle-container">
            <?php if ($show_inactive): ?>
                <a href="classes.php" class="toggle-btn toggle-inactive-btn" onclick="localStorage.setItem('classes_show_inactive', '0');">
                    <i class="fas fa-check-circle"></i> Active Only
                </a>
                <span class="toggle-btn toggle-active"><i class="fas fa-eye"></i> Showing All</span>
            <?php else: ?>
                <span class="toggle-btn toggle-active"><i class="fas fa-check-circle"></i> Active Only</span>
                <a href="classes.php?show_inactive=1" class="toggle-btn toggle-inactive-btn" onclick="localStorage.setItem('classes_show_inactive', '1');">
                    <i class="fas fa-eye"></i> Show Inactive
                    <?php if ($inactive_count > 0): ?>
                        <span class="inactive-badge"><?= $inactive_count ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="main-layout">

        <div class="form-card">
            <h3 id="form-title"><i class="fas fa-calendar-plus"></i> Add New Class</h3>
            <?= $msg ?>
            <form method="POST" id="classForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_id" id="template_id">
                <input type="hidden" name="original_day" id="original_day">

                <label>Class Name <small style="color:#999; font-weight:normal;">(optional)</small></label>
                <input type="text" name="class_name" id="class_name" placeholder="e.g. Fundamentals">

                <div class="row">
                    <div class="col">
                        <label>Location</label>
                        <select name="location_id" id="location_id" required>
                            <option value="">Select...</option>
                            <?php foreach ($locations as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= $l['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label>Type</label>
                        <select name="martial_art" id="martial_art">
                            <option value="bjj">BJJ</option>
                            <option value="mt">Muay Thai</option>
                            <option value="mma">MMA</option>
                        </select>
                    </div>
                </div>

                <label>Days of Week (Select multiple for bulk add)</label>
                <div class="days-grid">
                    <?php foreach ($days_of_week as $d): ?>
                        <div class="day-option">
                            <input type="checkbox" name="days[]" value="<?= $d ?>" id="day_<?= $d ?>">
                            <label for="day_<?= $d ?>" style="margin:0; font-weight:normal;"><?= $d ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col">
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="start_time" required>
                    </div>
                    <div class="col">
                        <label>End Time</label>
                        <input type="time" name="end_time" id="end_time" required>
                    </div>
                </div>

                <button type="submit" class="btn-save" id="btn-submit">Save Classes</button>
                <button type="button" class="btn-reset" onclick="resetForm()">Clear Form</button>
            </form>
        </div>

        <div class="list-container">

            <?php foreach ($grouped_classes as $lid => $group): ?>
                <div class="loc-group">
                    <div class="loc-header">
                        <i class="fas fa-map-marker-alt"></i> <?= e($group['name']) ?>
                    </div>
                    <table id="table-<?= $lid ?>">
                        <thead>
                            <tr>
                                <th style="width:20%">Day</th>
                                <th style="width:20%">Time</th>
                                <th style="width:15%">Type</th>
                                <th>Class Name</th>
                                <th style="text-align:right">Actions</th>
                            </tr>
                            <tr class="filter-row">
                                <th>
                                    <select class="table-filter" onchange="filterTable(<?= $lid ?>)" id="filter-day-<?= $lid ?>">
                                        <option value="">All Days</option>
                                        <?php foreach ($days_of_week as $d): ?>
                                            <option value="<?= $d ?>"><?= $d ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </th>
                                <th><input type="text" class="table-filter" onkeyup="filterTable(<?= $lid ?>)" id="filter-time-<?= $lid ?>" placeholder="Filter Time..."></th>
                                <th>
                                    <select class="table-filter" onchange="filterTable(<?= $lid ?>)" id="filter-type-<?= $lid ?>">
                                        <option value="">All Types</option>
                                        <option value="BJJ">BJJ</option>
                                        <option value="MT">MT</option>
                                        <option value="MMA">MMA</option>
                                    </select>
                                </th>
                                <th><input type="text" class="table-filter" onkeyup="filterTable(<?= $lid ?>)" id="filter-name-<?= $lid ?>" placeholder="Filter Name..."></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['classes'] as $c):
                                $isInactive = !$c['is_active'];
                            ?>
                                <tr class="data-row <?= $isInactive ? 'row-inactive' : '' ?>">
                                    <td class="col-day">
                                        <?= $c['day_of_week'] ?>
                                        <?php if ($isInactive): ?>
                                            <span class="tag tag-inactive">INACTIVE</span>
                                            <?php if (!empty($c['deactivated_at'])): ?>
                                                <span class="deactivation-date">from <?= date('M j', strtotime($c['deactivated_at'])) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-time" style="color:#666;">
                                        <?= date('g:i A', strtotime($c['start_time'])) ?>
                                    </td>
                                    <td class="col-type">
                                        <?php
                                        $tagClass = 'tag-bjj';
                                        if ($c['martial_art'] == 'mt') $tagClass = 'tag-mt';
                                        elseif ($c['martial_art'] == 'mma') $tagClass = 'tag-mma';
                                        ?>
                                        <span class="tag <?= $tagClass ?>">
                                            <?= strtoupper($c['martial_art']) ?>
                                        </span>
                                    </td>
                                    <td class="col-name"><?= e($c['class_name']) ?></td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($isInactive): ?>
                                                <!-- Reactivate button for inactive classes -->
                                                <form method="POST" onsubmit="return confirm('Reactivate this class?');" style="display:inline;">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                    <button type="submit" class="btn-icon btn-reactivate" title="Reactivate">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <!-- Edit and Deactivate for active classes -->
                                                <button class="btn-icon btn-edit" onclick='editClass(<?= json_encode($c) ?>)' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirmDeactivate(this);" style="display:inline;">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                    <input type="hidden" name="deactivate_date" class="deactivate-date-input">
                                                    <button type="submit" class="btn-icon btn-del" title="Deactivate">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function filterTable(lid) {
            // Get filter values
            const dayFilter = document.getElementById('filter-day-' + lid).value.toLowerCase();
            const timeFilter = document.getElementById('filter-time-' + lid).value.toLowerCase();
            const typeFilter = document.getElementById('filter-type-' + lid).value.toLowerCase();
            const nameFilter = document.getElementById('filter-name-' + lid).value.toLowerCase();

            // Get rows
            const table = document.getElementById('table-' + lid);
            const rows = table.getElementsByClassName('data-row');

            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const day = row.querySelector('.col-day').innerText.toLowerCase();
                const time = row.querySelector('.col-time').innerText.toLowerCase();
                const type = row.querySelector('.col-type').innerText.toLowerCase();
                const name = row.querySelector('.col-name').innerText.toLowerCase();

                if (
                    (dayFilter === "" || day.includes(dayFilter)) &&
                    (timeFilter === "" || time.includes(timeFilter)) &&
                    (typeFilter === "" || type.includes(typeFilter)) &&
                    (nameFilter === "" || name.includes(nameFilter))
                ) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            }
        }

        function editClass(data) {
            document.getElementById('form-title').innerHTML = "<i class='fas fa-edit'></i> Edit Class";
            document.getElementById('template_id').value = data.id;
            document.getElementById('original_day').value = data.day_of_week;
            document.getElementById('class_name').value = data.class_name;
            document.getElementById('location_id').value = data.location_id;
            document.getElementById('martial_art').value = data.martial_art;
            document.getElementById('start_time').value = data.start_time;
            document.getElementById('end_time').value = data.end_time;

            // Clear check boxes
            document.querySelectorAll('input[name="days[]"]').forEach(el => el.checked = false);

            // Check the specific day
            const dayCheck = document.getElementById('day_' + data.day_of_week);
            if (dayCheck) dayCheck.checked = true;

            document.getElementById('btn-submit').innerText = "Update Class";

            // Scroll to top
            if (window.innerWidth < 900) {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        }

        function resetForm() {
            document.getElementById('classForm').reset();
            document.getElementById('template_id').value = "";
            document.getElementById('form-title').innerHTML = "<i class='fas fa-calendar-plus'></i> Add New Class";
            document.getElementById('btn-submit').innerText = "Save Classes";
        }

        // Deactivation modal
        let pendingDeactivateForm = null;

        function confirmDeactivate(form) {
            pendingDeactivateForm = form;
            if (typeof deactivatePicker !== 'undefined') {
                deactivatePicker.setDate(new Date(), true);
            }
            document.getElementById('deactivate-modal').classList.add('show');
            return false;
        }

        function closeModal() {
            document.getElementById('deactivate-modal').classList.remove('show');
            pendingDeactivateForm = null;
        }

        function submitDeactivation() {
            if (pendingDeactivateForm) {
                const date = document.getElementById('deactivate-date').value;
                pendingDeactivateForm.querySelector('.deactivate-date-input').value = date;
                pendingDeactivateForm.submit();
            }
        }

    </script>

    <!-- Deactivation Modal -->
    <div id="deactivate-modal" class="modal-overlay" onclick="if(event.target === this) closeModal();">
        <div class="modal-box">
            <h3><i class="fas fa-power-off" style="color: var(--danger);"></i> Deactivate Class</h3>
            <p>Choose when this class should stop appearing on the schedule. It will still show for dates before this.</p>
            <label style="font-weight: 600; font-size: 0.9em;">Deactivation Date:</label>
            <input type="text" id="deactivate-date" readonly>
            <p style="font-size: 0.85em; color: #888; margin-top: 0;">
                <i class="fas fa-info-circle"></i> Payment history will be preserved. You can reactivate anytime.
            </p>
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-confirm" onclick="submitDeactivation()">Deactivate</button>
            </div>
        </div>
    </div>

    <script>
        // Initialize Flatpickr for deactivation date (must be after modal HTML)
        const deactivatePicker = flatpickr('#deactivate-date', {
            dateFormat: 'Y-m-d',
            locale: { firstDayOfWeek: 0 },
            defaultDate: new Date()
        });
    </script>

<?php require_once 'includes/footer.php'; ?>