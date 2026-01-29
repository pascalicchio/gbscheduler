<?php
// private_classes.php - MANAGER TOOL (Restored Filters + Payout)
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

// 1. Fetch Rates for JS (Auto-fill Logic)
$rates_raw = $pdo->query("SELECT user_id, location_id, rate, discount_percent FROM private_rates")->fetchAll(PDO::FETCH_ASSOC);
$rates_map = [];
foreach ($rates_raw as $r) {
    // Calculate Net Pay (Rate - Fee)
    $net = $r['rate'] - ($r['rate'] * ($r['discount_percent'] / 100));
    $rates_map[$r['user_id'] . '_' . $r['location_id']] = number_format($net, 2, '.', '');
}

// 2. HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $coach_id = $_POST['coach_id'];
        $location_id = $_POST['location_id'];
        $student = $_POST['student_name'];
        $date = $_POST['date'];
        $time = $_POST['time'];
        $payout = $_POST['payout'];

        $check = $pdo->prepare("SELECT id FROM private_classes WHERE user_id=? AND location_id=? AND class_date=? AND student_name=? AND class_time <=> ?");
        $check->execute([$coach_id, $location_id, $date, $student, $time ?: null]);

        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO private_classes (user_id, location_id, student_name, class_date, class_time, payout, created_by) VALUES (?,?,?,?,?,?,?)");
            if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, $_SESSION['user_id']])) {
                $_SESSION['flash_msg'] = "<div class='alert success'><i class='fas fa-check-circle'></i> Recorded! Payout set to $$payout</div>";
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

        $stmt = $pdo->prepare("UPDATE private_classes SET user_id=?, location_id=?, student_name=?, class_date=?, class_time=?, payout=? WHERE id=?");
        if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, $edit_id])) {
            $_SESSION['flash_msg'] = "<div class='alert success'><i class='fas fa-check-circle'></i> Entry updated successfully!</div>";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $del_id = $_POST['delete_id'];
        $pdo->prepare("DELETE FROM private_classes WHERE id = ?")->execute([$del_id]);
        $_SESSION['flash_msg'] = "<div class='alert error'><i class='fas fa-trash'></i> Entry deleted.</div>";
    }
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

$msg = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

// 3. FILTERS
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
?>

<!DOCTYPE html>
<html>

<head>
    <title>Private Classes Manager</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #007bff;
            --secondary: #2c3e50;
            --bg: #f4f6f9;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg);
            padding: 20px;
            color: #333;
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
            background: white;
            padding: 10px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .main-layout {
            display: flex;
            gap: 25px;
            align-items: flex-start;
        }

        .form-card {
            flex: 0 0 320px;
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 20px;
        }

        .data-card {
            flex: 1;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 0.9em;
            color: #555;
        }

        input,
        select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button.btn-save {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .alert {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            background: #d4edda;
            color: #155724;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }

        th {
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            padding: 15px 20px;
            text-align: left;
            border-bottom: 2px solid #eee;
        }

        td {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .filter-bar {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e4e8;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-bar select,
        .filter-bar input {
            width: auto;
            flex: 1;
            margin-bottom: 0;
            min-width: 150px;
        }
    </style>
</head>

<body>

    <div class="top-bar">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h2 style="margin:0; color:#2c3e50;">Private Classes Manager</h2>
    </div>

    <div class="main-layout">

        <div class="form-card">
            <h3 id="form-title">Record Class</h3>
            <?= $msg ?>
            <form method="POST" id="class-form">
                <input type="hidden" name="action" id="form-action" value="add">
                <input type="hidden" name="edit_id" id="edit-id" value="">

                <label>Coach</label>
                <select name="coach_id" id="coach_id" required onchange="updatePayout()">
                    <option value="">Select Coach...</option>
                    <?php foreach ($coaches as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Location</label>
                <select name="location_id" id="location_id" required onchange="updatePayout()">
                    <option value="">Select Location...</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= $l['name'] ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Student / Activity Name</label>
                <input type="text" name="student_name" required placeholder="e.g. John Doe OR Cleaning">

                <label>Date</label>
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>

                <label>Time (Optional)</label>
                <input type="time" name="time">

                <label style="color:#28a745">Payout Amount ($)</label>
                <input type="number" step="0.01" name="payout" id="payout" required placeholder="0.00" style="font-weight:bold; color:#28a745; border-color:#28a745;">
                <small style="display:block; margin-top:-10px; margin-bottom:15px; color:#888;">Auto-filled based on settings. Edit for cleaning/seminars.</small>

                <button type="submit" class="btn-save" id="submit-btn">Save Entry</button>
                <button type="button" id="cancel-btn" onclick="cancelEdit()" style="display:none; width:100%; padding:12px; margin-top:10px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer;">Cancel Edit</button>
            </form>
        </div>

        <div class="data-card">
            <form method="GET" class="filter-bar">
                <input type="date" name="start_date" value="<?= $start_date ?>">
                <input type="date" name="end_date" value="<?= $end_date ?>">

                <select name="location_id">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= $filter_loc == $l['id'] ? 'selected' : '' ?>><?= $l['name'] ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="coach_id">
                    <option value="">All Coaches</option>
                    <?php foreach ($coaches as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_coach == $c['id'] ? 'selected' : '' ?>><?= $c['name'] ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-save" style="width:auto; padding: 8px 15px; flex:0 0 auto;">Filter</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Coach</th>
                        <th>Location</th>
                        <th>Activity</th>
                        <th>Payout</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $r): ?>
                        <tr>
                            <td><?= date('M d', strtotime($r['class_date'])) ?></td>
                            <td><?= htmlspecialchars($r['coach_name']) ?></td>
                            <td><?= htmlspecialchars($r['loc_name']) ?></td>
                            <td><?= htmlspecialchars($r['student_name']) ?></td>
                            <td style="font-weight:bold; color:#28a745;">$<?= number_format($r['payout'], 2) ?></td>
                            <td>
                                <button type="button" onclick='editEntry(<?= json_encode($r) ?>)' style="background:none; border:none; color:#007bff; cursor:pointer; margin-right:10px;"><i class="fas fa-edit"></i></button>
                                <form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                    <button style="background:none; border:none; color:red; cursor:pointer;"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const rateMap = <?= json_encode($rates_map) ?>;

        function updatePayout() {
            const cid = document.getElementById('coach_id').value;
            const lid = document.getElementById('location_id').value;
            const payoutBox = document.getElementById('payout');

            if (cid && lid) {
                const key = cid + '_' + lid;
                // JS will auto-fill the NET amount (Rate - Fee)
                if (rateMap[key]) {
                    payoutBox.value = rateMap[key];
                } else {
                    payoutBox.value = '0.00';
                }
            }
        }

        function editEntry(data) {
            // Populate form with existing data
            document.getElementById('form-action').value = 'edit';
            document.getElementById('edit-id').value = data.id;
            document.getElementById('coach_id').value = data.user_id;
            document.getElementById('location_id').value = data.location_id;
            document.querySelector('input[name="student_name"]').value = data.student_name;
            document.querySelector('input[name="date"]').value = data.class_date;
            document.querySelector('input[name="time"]').value = data.class_time || '';
            document.getElementById('payout').value = data.payout;

            // Update UI
            document.getElementById('form-title').textContent = 'Edit Entry';
            document.getElementById('submit-btn').textContent = 'Update Entry';
            document.getElementById('submit-btn').style.background = '#28a745';
            document.getElementById('cancel-btn').style.display = 'block';

            // Scroll to form
            document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
        }

        function cancelEdit() {
            // Reset form
            document.getElementById('class-form').reset();
            document.getElementById('form-action').value = 'add';
            document.getElementById('edit-id').value = '';

            // Reset UI
            document.getElementById('form-title').textContent = 'Record Class';
            document.getElementById('submit-btn').textContent = 'Save Entry';
            document.getElementById('submit-btn').style.background = '#007bff';
            document.getElementById('cancel-btn').style.display = 'none';

            // Reset date to today
            document.querySelector('input[name="date"]').value = '<?= date('Y-m-d') ?>';
        }
    </script>
</body>

</html>