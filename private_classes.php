<?php
// private_classes.php - MANAGER TOOL
require_once 'includes/config.php';

// Require admin or manager access
requireAuth(['admin', 'manager']);

// Fetch Rates for JS (Auto-fill Logic)
$rates_raw = $pdo->query("SELECT user_id, location_id, rate, discount_percent FROM private_rates")->fetchAll(PDO::FETCH_ASSOC);
$rates_map = [];
foreach ($rates_raw as $r) {
    $net = $r['rate'] - ($r['rate'] * ($r['discount_percent'] / 100));
    $rates_map[$r['user_id'] . '_' . $r['location_id']] = number_format($net, 2, '.', '');
}

// HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $coach_id = $_POST['coach_id'];
        $location_id = $_POST['location_id'];
        $student = $_POST['student_name'];
        $date = $_POST['date'];
        $time = $_POST['time'];
        $payout = $_POST['payout'];
        $notes = trim($_POST['notes'] ?? '');

        $check = $pdo->prepare("SELECT id FROM private_classes WHERE user_id=? AND location_id=? AND class_date=? AND student_name=? AND class_time <=> ?");
        $check->execute([$coach_id, $location_id, $date, $student, $time ?: null]);

        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO private_classes (user_id, location_id, student_name, class_date, class_time, payout, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
            if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, $notes ?: null, getUserId()])) {
                setFlash("Recorded! Payout set to $$payout", 'success');
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $edit_id = $_POST['edit_id'];
        $coach_id = $_POST['coach_id'];
        $location_id = $_POST['location_id'];
        $student = $_POST['student_name'];
        $date = $_POST['date'];
        $time = $_POST['time'];
        $payout = $_POST['payout'];
        $notes = trim($_POST['notes'] ?? '');

        $stmt = $pdo->prepare("UPDATE private_classes SET user_id=?, location_id=?, student_name=?, class_date=?, class_time=?, payout=?, notes=? WHERE id=?");
        if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, $notes ?: null, $edit_id])) {
            setFlash("Entry updated successfully!", 'success');
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $del_id = $_POST['delete_id'];
        $pdo->prepare("DELETE FROM private_classes WHERE id = ?")->execute([$del_id]);
        setFlash("Entry deleted.", 'error');
    }
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

$msg = getFlash();

// FILTERS
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$filter_loc = $_GET['location_id'] ?? '';
$filter_coach = $_GET['coach_id'] ?? '';

$query = "
    SELECT pc.*, u.name as coach_name, l.name as loc_name
    FROM private_classes pc
    JOIN users u ON pc.user_id = u.id
    JOIN locations l ON pc.location_id = l.id
    WHERE pc.class_date BETWEEN :start AND :end
";
$params = ['start' => $start_date, 'end' => $end_date];

if ($filter_loc) {
    $query .= " AND pc.location_id = :loc";
    $params['loc'] = $filter_loc;
}
if ($filter_coach) {
    $query .= " AND pc.user_id = :coach";
    $params['coach'] = $filter_coach;
}

$query .= " ORDER BY pc.class_date DESC, pc.class_time DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$coaches = $pdo->query("SELECT id, name FROM users WHERE role != 'manager' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate total payout
$total_payout = array_sum(array_column($history, 'payout'));

// Page setup
$pageTitle = 'Private Classes | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    :root {
        --gradient-primary: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        --gradient-hover: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
        --gradient-dark: linear-gradient(135deg, #1a202c, #2d3748);
        --primary: rgb(0, 201, 255);
        --bg: #f8fafb;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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

    /* Main Layout */
    .main-layout {
        display: flex;
        gap: 24px;
        align-items: flex-start;
    }

    /* Form Card */
    .form-card {
        flex: 0 0 340px;
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 201, 255, 0.1);
        position: relative;
    }

    .form-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background-image: var(--gradient-primary);
        border-radius: 16px 16px 0 0;
    }

    .form-card.sticky {
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
    }

    .form-card input:focus,
    .form-card select:focus,
    .form-card textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .form-card textarea {
        resize: vertical;
        min-height: 60px;
    }

    .payout-input {
        font-weight: 700;
        color: rgb(58, 222, 215);
        border-color: rgb(58, 222, 215) !important;
        background: linear-gradient(135deg, rgba(58, 222, 215, 0.05), rgba(58, 222, 215, 0.1));
    }

    .payout-input:focus {
        border-color: rgb(48, 202, 195) !important;
    }

    .form-text {
        font-size: 0.8rem;
        color: #6c757d;
        margin: 6px 0 0 0;
        font-weight: 400;
    }

    .text-success {
        color: rgb(58, 222, 215) !important;
    }

    /* Buttons */
    .btn {
        padding: 12px 20px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        border: none;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .btn-block {
        width: 100%;
        display: block;
    }

    .btn-primary {
        background-image: var(--gradient-primary);
        color: white;
        box-shadow: 0 6px 20px rgba(0, 201, 255, 0.25);
    }

    .btn-primary:hover {
        background-image: var(--gradient-hover);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 201, 255, 0.35);
    }

    .btn-success {
        background: #51cf66;
        color: white;
        box-shadow: 0 6px 20px rgba(81, 207, 102, 0.25);
    }

    .btn-success:hover {
        background: #40c057;
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(81, 207, 102, 0.35);
    }

    .btn-secondary {
        background-image: var(--gradient-dark);
        color: white;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .btn-secondary:hover {
        background-image: linear-gradient(135deg, #0f1419, #1f2937);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
    }

    .btn-sm {
        padding: 10px 18px;
        font-size: 0.85rem;
    }

    .mt-1 {
        margin-top: 10px;
    }

    .hidden {
        display: none !important;
    }

    /* Data Card */
    .data-card {
        flex: 1;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(0, 201, 255, 0.1);
        overflow: hidden;
    }

    /* Filter Bar */
    .filter-bar {
        display: flex;
        gap: 12px;
        padding: 20px;
        background: linear-gradient(to bottom, #fafbfc, #f5f7fa);
        border-bottom: 2px solid rgba(0, 201, 255, 0.1);
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-bar input[type="text"] {
        max-width: 140px;
        padding: 10px 12px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        transition: all 0.25s ease;
    }

    .filter-bar input[type="text"]:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.1);
    }

    .filter-bar select {
        padding: 10px 12px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        background: white;
        cursor: pointer;
        transition: all 0.25s ease;
    }

    .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.1);
    }

    /* Row count and total display */
    .filter-summary {
        margin-left: auto;
        display: flex;
        gap: 10px;
    }

    .row-count,
    .total-amount {
        padding: 8px 16px;
        font-size: 0.85rem;
        color: white;
        white-space: nowrap;
        background-image: var(--gradient-dark);
        border-radius: 8px;
        font-weight: 600;
    }

    .row-count strong,
    .total-amount strong {
        color: rgb(146, 254, 157);
        font-weight: 800;
    }

    /* Table */
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }

    th, td {
        padding: 14px 16px;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }

    th {
        background: linear-gradient(to bottom, #fafbfc, #f5f7fa);
        color: #6c757d;
        font-size: 0.75rem;
        text-transform: uppercase;
        border-bottom: 2px solid rgba(0, 201, 255, 0.2);
        white-space: nowrap;
        font-weight: 700;
        letter-spacing: 0.05em;
    }

    tbody tr:nth-child(even) {
        background: #fafbfc;
    }

    tbody tr:hover {
        background: rgba(0, 201, 255, 0.03);
    }

    .font-bold {
        font-weight: 700;
    }

    .payout-amount {
        color: #05B8E9;
        font-weight: 700;
    }

    /* Table Actions */
    .table-actions {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .btn-icon {
        padding: 8px 10px;
        border: none;
        background: rgba(0, 201, 255, 0.1);
        color: rgb(0, 201, 255);
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.9rem;
    }

    .btn-icon:hover {
        background: rgba(0, 201, 255, 0.2);
        transform: scale(1.05);
    }

    .btn-icon.danger {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    .btn-icon.danger:hover {
        background: rgba(239, 68, 68, 0.2);
    }

    /* Flatpickr preset buttons */
    .flatpickr-presets {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 6px;
        padding: 10px;
        border-top: 1px solid #e6e6e6;
        background: #f5f5f5;
    }

    .flatpickr-presets button {
        padding: 8px 10px;
        border: 1px solid #ddd;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: 600;
        transition: all 0.2s;
    }

    .flatpickr-presets button:hover {
        background: #e9ecef;
        border-color: rgb(0, 201, 255);
        color: rgb(0, 201, 255);
    }

    /* Flash Messages */
    .alert {
        padding: 14px 18px;
        border-radius: 12px;
        margin-bottom: 16px;
        font-size: 0.9rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .alert-success {
        background: linear-gradient(135deg, #f0fff4 0%, #e6ffed 100%);
        color: #22543d;
        border: 2px solid rgba(146, 254, 157, 0.3);
    }

    .alert-error {
        background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
        color: #c53030;
        border: 2px solid rgba(239, 68, 68, 0.2);
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .main-layout {
            flex-direction: column;
        }

        .form-card {
            flex: 1;
            width: 100%;
            position: static !important;
        }

        .data-card {
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        body {
            padding: 12px;
        }

        .page-header {
            flex-wrap: wrap;
        }

        .page-header h2 {
            font-size: 1.5rem;
            flex: 1 1 100%;
        }

        .nav-actions {
            flex: 1 1 100%;
            justify-content: flex-end;
        }

        .nav-menu-btn span {
            display: none;
        }

        .nav-menu-btn {
            padding: 10px 14px;
        }

        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-bar input[type="text"],
        .filter-bar select {
            max-width: 100%;
        }

        .filter-summary {
            margin-left: 0;
            flex-direction: column;
            width: 100%;
        }

        .row-count,
        .total-amount {
            text-align: center;
        }

        table {
            font-size: 0.85rem;
        }

        th, td {
            padding: 10px 12px;
        }
    }
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-money-bill-wave"></i> Private Classes</h2>
    <div class="nav-menu" x-data="{ open: false }" @mouseenter="if(window.innerWidth >= 768) open = true" @mouseleave="if(window.innerWidth >= 768) open = false">
        <button @click="if(window.innerWidth < 768) open = !open" class="nav-menu-btn">
            <i class="fas fa-bars"></i>
            <span>Menu</span>
        </button>
        <div x-show="open" @click.away="if(window.innerWidth < 768) open = false" @mouseenter="open = true" x-cloak class="nav-dropdown">
            <a href="dashboard.php"><i class="fas fa-calendar-alt"></i> Dashboard</a>
            <a href="reports.php"><i class="fas fa-chart-line"></i> Individual Report</a>
            <?php if (canManage()): ?>
                <a href="private_classes.php" class="active"><i class="fas fa-money-bill-wave"></i> Private Classes</a>
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

<div class="main-layout">
    <div class="form-card sticky">
        <h3 id="form-title" class="mt-0">Record Class</h3>
        <?= $msg ?>
        <form method="POST" id="class-form">
            <input type="hidden" name="action" id="form-action" value="add">
            <input type="hidden" name="edit_id" id="edit-id" value="">

            <label>Coach</label>
            <select name="coach_id" id="coach_id" required onchange="updatePayout()">
                <option value="">Select Coach...</option>
                <?php foreach ($coaches as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Location</label>
            <select name="location_id" id="location_id" required onchange="updatePayout()">
                <option value="">Select Location...</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Student / Activity Name</label>
            <input type="text" name="student_name" required placeholder="e.g. John Doe OR Cleaning">

            <label>Date</label>
            <input type="text" name="date" id="entry_date" value="<?= date('Y-m-d') ?>" required readonly>

            <label>Time (Optional)</label>
            <input type="time" name="time">

            <label class="text-success">Payout Amount ($)</label>
            <input type="number" step="0.01" name="payout" id="payout" required placeholder="0.00" class="payout-input">
            <p class="form-text">Auto-filled based on settings. Edit for cleaning/seminars.</p>

            <label>Notes (Optional)</label>
            <textarea name="notes" id="notes" rows="2" placeholder="e.g. Package of privates paid last month"></textarea>

            <button type="submit" class="btn btn-primary btn-block" id="submit-btn">Save Entry</button>
            <button type="button" id="cancel-btn" onclick="cancelEdit()" class="btn btn-secondary btn-block mt-1 hidden">Cancel Edit</button>
        </form>
    </div>

    <div class="data-card">
        <form method="GET" class="filter-bar">
            <input type="text" name="start_date" id="filter_start" value="<?= $start_date ?>" readonly>
            <input type="text" name="end_date" id="filter_end" value="<?= $end_date ?>" readonly>

            <select name="location_id">
                <option value="">All Locations</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filter_loc == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="coach_id">
                <option value="">All Coaches</option>
                <?php foreach ($coaches as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_coach == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>

            <div class="filter-summary">
                <div class="row-count">
                    Showing <strong><?= count($history) ?></strong> <?= count($history) === 1 ? 'entry' : 'entries' ?>
                </div>
                <div class="total-amount">
                    Total: <strong>$<?= number_format($total_payout, 2) ?></strong>
                </div>
            </div>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Coach</th>
                    <th>Location</th>
                    <th>Activity</th>
                    <th>Notes</th>
                    <th>Payout</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $r): ?>
                    <tr>
                        <td><?= date('M d', strtotime($r['class_date'])) ?></td>
                        <td><?= e($r['coach_name']) ?></td>
                        <td><?= e($r['loc_name']) ?></td>
                        <td><?= e($r['student_name']) ?></td>
                        <td style="text-align:center;"><?php if ($r['notes']): ?><span class="note-trigger" data-note="<?= htmlspecialchars($r['notes'], ENT_QUOTES) ?>" style="cursor:pointer; font-size:1.1em; color: rgb(0, 201, 255); transition: all 0.2s;"><i class="fas fa-sticky-note"></i></span><?php endif; ?></td>
                        <td class="payout-amount">$<?= number_format($r['payout'], 2) ?></td>
                        <td class="table-actions">
                            <button type="button" onclick='editEntry(<?= json_encode($r) ?>)' class="btn-icon"><i class="fas fa-edit"></i></button>
                            <form method="POST" id="delete-form-<?= $r['id'] ?>" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                <button type="button" data-delete-id="<?= $r['id'] ?>" class="btn-icon danger delete-trigger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }

    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }

    .modal-overlay.is-open {
        display: flex;
    }
</style>

<script>
document.addEventListener('alpine:init', function() {
    Alpine.store('deleteModal', {
        isOpen: false,
        deleteId: null,
        open: function(id) {
            this.deleteId = id;
            this.isOpen = true;
        },
        close: function() {
            this.isOpen = false;
            this.deleteId = null;
        },
        confirm: function() {
            if (this.deleteId) {
                var form = document.getElementById('delete-form-' + this.deleteId);
                if (form) form.submit();
            }
        }
    });

    Alpine.store('noteModal', {
        isOpen: false,
        content: '',
        open: function(noteText) {
            this.content = noteText;
            this.isOpen = true;
        },
        close: function() {
            this.isOpen = false;
            this.content = '';
        }
    });
});
</script>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay" x-data x-bind:class="{ 'is-open': $store.deleteModal.isOpen }" @keydown.escape.window="$store.deleteModal.close()">
    <div @click.away="$store.deleteModal.close()" style="background: white; padding: 32px; border-radius: 16px; max-width: 450px; width: 90%; box-shadow: 0 12px 40px rgba(0, 201, 255, 0.25); border: 1px solid rgba(0, 201, 255, 0.2); position: relative;">
        <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157)); border-radius: 16px 16px 0 0;"></div>

        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
            <div style="width: 48px; height: 48px; background: rgba(239, 68, 68, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #ef4444; font-size: 1.5rem;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 style="margin: 0; font-size: 1.5rem; font-weight: 800; color: #2c3e50;">Delete Entry?</h3>
        </div>

        <p style="color: #6c757d; margin-bottom: 24px; line-height: 1.6;">Are you sure you want to delete this entry? This action cannot be undone.</p>

        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button @click="$store.deleteModal.close()" type="button" style="padding: 12px 24px; background: #e9ecef; color: #2c3e50; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: all 0.2s;">
                Cancel
            </button>
            <button @click="$store.deleteModal.confirm()" type="button" style="padding: 12px 24px; background: #ef4444; color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);">
                Delete
            </button>
        </div>
    </div>
</div>

<!-- Note Modal -->
<div id="noteModal" class="modal-overlay" style="z-index: 1001;" x-data x-bind:class="{ 'is-open': $store.noteModal.isOpen }" @keydown.escape.window="$store.noteModal.close()" @click="$store.noteModal.close()">
    <div @click.stop style="background: white; padding: 24px; border-radius: 16px; max-width: 450px; width: 90%; box-shadow: 0 12px 40px rgba(0, 201, 255, 0.25); border: 1px solid rgba(0, 201, 255, 0.2); position: relative;">
        <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157)); border-radius: 16px 16px 0 0;"></div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid rgba(0, 201, 255, 0.1);">
            <h4 style="margin: 0; color: rgb(0, 201, 255); font-size: 1.1rem; font-weight: 700;">
                <i class="fas fa-sticky-note"></i> Note
            </h4>
            <button @click="$store.noteModal.close()" type="button" style="background: none; border: none; font-size: 1.75rem; cursor: pointer; color: #999; line-height: 1; transition: all 0.2s; padding: 0; width: 32px; height: 32px; border-radius: 8px;">&times;</button>
        </div>

        <div style="color: #2c3e50; line-height: 1.6; font-size: 0.95rem;" x-text="$store.noteModal.content"></div>
    </div>
</div>

<?php
// Prepare JS variables
$rates_map_json = json_encode($rates_map);
$today_date = date('Y-m-d');
?>
<script>
    const rateMap = <?php echo $rates_map_json; ?>;

    function updatePayout() {
        const cid = document.getElementById('coach_id').value;
        const lid = document.getElementById('location_id').value;
        const payoutBox = document.getElementById('payout');

        if (cid && lid) {
            const key = cid + '_' + lid;
            if (rateMap[key]) {
                payoutBox.value = rateMap[key];
            } else {
                payoutBox.value = '0.00';
            }
        }
    }

    function editEntry(data) {
        document.getElementById('form-action').value = 'edit';
        document.getElementById('edit-id').value = data.id;
        document.getElementById('coach_id').value = data.user_id;
        document.getElementById('location_id').value = data.location_id;
        document.querySelector('input[name=\"student_name\"]').value = data.student_name;
        document.querySelector('input[name=\"date\"]').value = data.class_date;
        document.querySelector('input[name=\"time\"]').value = data.class_time || '';
        document.getElementById('payout').value = data.payout;
        document.getElementById('notes').value = data.notes || '';

        document.getElementById('form-title').textContent = 'Edit Entry';
        document.getElementById('submit-btn').textContent = 'Update Entry';
        document.getElementById('submit-btn').className = 'btn btn-success btn-block';
        document.getElementById('cancel-btn').classList.remove('hidden');

        document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
    }

    function cancelEdit() {
        document.getElementById('class-form').reset();
        document.getElementById('form-action').value = 'add';
        document.getElementById('edit-id').value = '';
        document.getElementById('notes').value = '';

        document.getElementById('form-title').textContent = 'Record Class';
        document.getElementById('submit-btn').textContent = 'Save Entry';
        document.getElementById('submit-btn').className = 'btn btn-primary btn-block';
        document.getElementById('cancel-btn').classList.add('hidden');

        document.querySelector('input[name="date"]').value = '<?php echo $today_date; ?>';
        if (typeof entryPicker !== 'undefined') entryPicker.setDate('<?php echo $today_date; ?>');
    }

    // Flatpickr initialization
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function getPresetDates(preset) {
        const today = new Date();
        let start, end;

        switch(preset) {
            case 'this-month':
                start = new Date(today.getFullYear(), today.getMonth(), 1);
                end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                break;
            case 'last-month':
                start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                end = new Date(today.getFullYear(), today.getMonth(), 0);
                break;
            case 'this-week':
                const dayOfWeek = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - dayOfWeek);
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
            case 'last-week':
                const lastWeekDay = today.getDay();
                start = new Date(today);
                start.setDate(today.getDate() - lastWeekDay - 7);
                end = new Date(start);
                end.setDate(start.getDate() + 6);
                break;
        }
        return { start, end };
    }

    function createPresetButtons(fp, startPicker, endPicker) {
        const presetContainer = document.createElement('div');
        presetContainer.className = 'flatpickr-presets';

        const presets = [
            { label: 'This Month', value: 'this-month' },
            { label: 'Last Month', value: 'last-month' },
            { label: 'This Week', value: 'this-week' },
            { label: 'Last Week', value: 'last-week' }
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

    // Entry date picker (no presets, just calendar with Sunday first)
    const entryPicker = flatpickr('#entry_date', {
        dateFormat: 'Y-m-d',
        locale: { firstDayOfWeek: 0 }
    });

    // Filter date pickers with presets
    const filterFpConfig = {
        dateFormat: 'Y-m-d',
        locale: { firstDayOfWeek: 0 }
    };

    const filterStartPicker = flatpickr('#filter_start', {
        ...filterFpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                filterEndPicker.set('minDate', selectedDates[0]);
            }
        }
    });

    const filterEndPicker = flatpickr('#filter_end', {
        ...filterFpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                filterStartPicker.set('maxDate', selectedDates[0]);
            }
        }
    });

    // Add preset buttons after both pickers are initialized
    filterStartPicker.calendarContainer.appendChild(createPresetButtons(filterStartPicker, filterStartPicker, filterEndPicker));
    filterEndPicker.calendarContainer.appendChild(createPresetButtons(filterEndPicker, filterStartPicker, filterEndPicker));

    // Event delegation for modals
    document.addEventListener('click', function(e) {
        // Delete modal trigger
        var deleteBtn = e.target.closest('.delete-trigger');
        if (deleteBtn) {
            var id = deleteBtn.getAttribute('data-delete-id');
            if (typeof Alpine !== 'undefined' && Alpine.store('deleteModal')) {
                Alpine.store('deleteModal').open(id);
            }
            return;
        }

        // Note modal trigger
        var noteBtn = e.target.closest('.note-trigger');
        if (noteBtn) {
            var note = noteBtn.getAttribute('data-note');
            if (typeof Alpine !== 'undefined' && Alpine.store('noteModal')) {
                Alpine.store('noteModal').open(note);
            }
            return;
        }
    });
</script>

<!-- Simple delete modal handler - runs after DOM ready -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Find all delete buttons and attach click handlers
    var deleteButtons = document.querySelectorAll('.delete-trigger');
    deleteButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var id = this.getAttribute('data-delete-id');
            console.log('Delete button clicked, ID:', id);
            Alpine.store('deleteModal').open(id);
        });
    });
    console.log('Delete handlers attached to', deleteButtons.length, 'buttons');
});
</script>
<?php

require_once 'includes/footer.php';
?>
