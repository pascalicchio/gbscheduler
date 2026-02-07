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
    /* Design System */
    :root {
        --gradient-primary: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        --gradient-primary-hover: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
        --gradient-dark: linear-gradient(135deg, #1a202c, #2d3748);
        --text-dark: #2c3e50;
        --text-secondary: #6c757d;
        --border-light: #e8ecf2;
        --bg: #f8fafb;
        --bg-card: white;
        --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
        --shadow-md: 0 6px 20px rgba(0, 0, 0, 0.12);
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: var(--bg);
        padding: 20px;
        color: #2c3e50;
        -webkit-font-smoothing: antialiased;
    }

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        gap: 16px;
        flex-wrap: wrap;
    }

    .page-header-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .page-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
    }

    .page-header h2 i {
        background-image: var(--gradient-primary);
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
        background-image: var(--gradient-primary);
    }

    .nav-dropdown a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        text-decoration: none;
        color: var(--text-dark);
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
        border-top: 1px solid var(--border-light);
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

    [x-cloak] { display: none !important; }

    .inline-block {
        display: inline-block;
    }

    .toggle-container {
        display: flex;
        gap: 8px;
        align-items: center;
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
        background: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
        box-shadow: 0 2px 8px rgba(0, 201, 255, 0.3);
    }

    .toggle-inactive-btn {
        background: white;
        color: #666;
        border: 2px solid #e8ecf2;
    }

    .toggle-inactive-btn:hover {
        background: rgba(0, 201, 255, 0.05);
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
    }

    .inactive-badge {
        background: #dc3545;
        color: white;
        font-size: 0.75em;
        padding: 2px 6px;
        border-radius: 10px;
        font-weight: 700;
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
        margin-bottom: 20px;
        font-size: 1.25rem;
        font-weight: 700;
        color: #2c3e50;
    }

    .form-card label {
        display: block;
        font-weight: 700;
        font-size: 0.75rem;
        margin-bottom: 8px;
        margin-top: 14px;
        text-transform: uppercase;
        color: #2c3e50;
        letter-spacing: 0.05em;
    }

    .form-card label:first-of-type {
        margin-top: 0;
    }

    .form-card input,
    .form-card select,
    .form-card textarea {
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
        margin-bottom: 20px;
    }

    .form-card input:focus,
    .form-card select:focus,
    .form-card textarea:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .form-card textarea {
        resize: vertical;
        min-height: 60px;
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
        background-image: var(--gradient-primary);
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 700;
        font-size: 1rem;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.25);
    }

    button.btn-save:hover {
        background-image: var(--gradient-primary-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 201, 255, 0.35);
    }

    button.btn-reset {
        width: 100%;
        padding: 10px;
        background: #f8f9fa;
        color: #6c757d;
        border: 2px solid #e8ecf2;
        border-radius: 8px;
        cursor: pointer;
        margin-top: 10px;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    button.btn-reset:hover {
        background: white;
        border-color: #6c757d;
        color: #495057;
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
        border-radius: 12px;
        box-shadow: var(--shadow-md);
        overflow: hidden;
        border: 1px solid var(--border-light);
    }

    .loc-header {
        background-image: var(--gradient-dark);
        color: white;
        padding: 15px 20px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.1em;
    }

    .loc-header i {
        color: rgb(146, 254, 157);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9em;
    }

    th {
        background: linear-gradient(to bottom, #fafbfc, #f5f7fa);
        padding: 12px 15px;
        text-align: left;
        font-weight: 700;
        color: var(--text-dark);
        font-size: 0.85em;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid var(--border-light);
    }

    td {
        padding: 14px 15px;
        border-bottom: 1px solid #f0f1f3;
        color: #555;
    }

    tr:hover td {
        background: #fafafa;
    }

    /* Column widths */
    table th:nth-child(1),
    table td:nth-child(1) {
        width: 20%;
    }

    table th:nth-child(2),
    table td:nth-child(2) {
        width: 20%;
    }

    table th:nth-child(3),
    table td:nth-child(3) {
        width: 15%;
    }

    table th:last-child,
    table td:last-child {
        text-align: right;
    }

    /* Filter Inputs inside Table Header */
    .filter-row th {
        background: #fff;
        padding: 10px 15px;
        border-bottom: 2px solid var(--border-light);
    }

    .table-filter {
        width: 100%;
        padding: 6px 10px;
        border: 2px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.85em;
        margin: 0;
        font-weight: 500;
        transition: all 0.25s ease;
    }

    .table-filter:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.08);
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

<div class="page-header">
    <h2><i class="fas fa-graduation-cap"></i> Manage Classes</h2>
    <div class="page-header-right">
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
                    <a href="classes.php" class="active"><i class="fas fa-graduation-cap"></i> Class Templates</a>
                    <a href="users.php"><i class="fas fa-users"></i> Users</a>
                    <a href="inventory.php"><i class="fas fa-boxes"></i> Inventory</a>
                <?php endif; ?>
                <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
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

                <label>Class Name <small class="text-gray-500 font-normal">(optional)</small></label>
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
                            <label for="day_<?= $d ?>" class="m-0 font-normal"><?= $d ?></label>
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
                                <th>Day</th>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Class Name</th>
                                <th>Actions</th>
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
                                    <td class="col-time text-gray-600">
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
                                                <form method="POST" onsubmit="return confirm('Reactivate this class?');" class="inline-block">
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
                                                <form method="POST" onsubmit="return confirmDeactivate(this);" class="inline-block">
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
            <h3><i class="fas fa-power-off text-red-600"></i> Deactivate Class</h3>
            <p>Choose when this class should stop appearing on the schedule. It will still show for dates before this.</p>
            <label class="font-semibold text-sm">Deactivation Date:</label>
            <input type="text" id="deactivate-date" readonly>
            <p class="text-sm text-gray-600 mt-0">
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