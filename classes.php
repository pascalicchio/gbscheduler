<?php
// classes.php - MANAGE CLASS TEMPLATES (WITH REFRESH FIX)
require_once 'includes/config.php';

// Require admin access
requireAuth(['admin']);

// --- 1. HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // DELETE
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = $_POST['id'];

        // 1. Delete associated assignments first
        $delDeps = $pdo->prepare("DELETE FROM event_assignments WHERE template_id = ?");
        $delDeps->execute([$id]);

        // 2. Delete template
        $stmt = $pdo->prepare("DELETE FROM class_templates WHERE id = ?");
        if ($stmt->execute([$id])) {
            setFlash("Class deleted successfully.", 'error');
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
    header("Location: classes.php");
    exit();
}

// --- 2. DISPLAY FLASH MESSAGE ---
$msg = getFlash();

// --- 3. FETCH DATA ---
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Class Templates Grouped by Location
$sql_classes = "
    SELECT ct.*, l.name as loc_name 
    FROM class_templates ct
    JOIN locations l ON ct.location_id = l.id
    ORDER BY l.name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time
";
$all_classes = $pdo->query($sql_classes)->fetchAll(PDO::FETCH_ASSOC);

// Group classes by Location ID
$grouped_classes = [];
foreach ($all_classes as $c) {
    $grouped_classes[$c['location_id']]['name'] = $c['loc_name'];
    $grouped_classes[$c['location_id']]['classes'][] = $c;
}

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Page setup
$pageTitle = 'Manage Classes | GB Scheduler';
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

    <div class="top-bar">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h2 style="margin:0; color:#2c3e50;">Manage Classes</h2>
    </div>

    <div class="main-layout">

        <div class="form-card">
            <h3 id="form-title"><i class="fas fa-calendar-plus"></i> Add New Class</h3>
            <?= $msg ?>
            <form method="POST" id="classForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="template_id" id="template_id">
                <input type="hidden" name="original_day" id="original_day">

                <label>Class Name</label>
                <input type="text" name="class_name" id="class_name" required placeholder="e.g. Fundamentals">

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
                                    </select>
                                </th>
                                <th><input type="text" class="table-filter" onkeyup="filterTable(<?= $lid ?>)" id="filter-name-<?= $lid ?>" placeholder="Filter Name..."></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['classes'] as $c): ?>
                                <tr class="data-row">
                                    <td class="col-day"><?= $c['day_of_week'] ?></td>
                                    <td class="col-time" style="color:#666;">
                                        <?= date('g:i A', strtotime($c['start_time'])) ?>
                                    </td>
                                    <td class="col-type">
                                        <span class="tag <?= $c['martial_art'] == 'bjj' ? 'tag-bjj' : 'tag-mt' ?>">
                                            <?= strtoupper($c['martial_art']) ?>
                                        </span>
                                    </td>
                                    <td class="col-name"><?= e($c['class_name']) ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn-icon btn-edit" onclick='editClass(<?= json_encode($c) ?>)' title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Delete this class template?\nThis will remove all scheduled coaches for this class.');" style="display:inline;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="btn-icon btn-del" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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
    </script>

<?php require_once 'includes/footer.php'; ?>