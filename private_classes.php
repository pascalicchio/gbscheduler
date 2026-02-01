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

        $check = $pdo->prepare("SELECT id FROM private_classes WHERE user_id=? AND location_id=? AND class_date=? AND student_name=? AND class_time <=> ?");
        $check->execute([$coach_id, $location_id, $date, $student, $time ?: null]);

        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO private_classes (user_id, location_id, student_name, class_date, class_time, payout, created_by) VALUES (?,?,?,?,?,?,?)");
            if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, getUserId()])) {
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

        $stmt = $pdo->prepare("UPDATE private_classes SET user_id=?, location_id=?, student_name=?, class_date=?, class_time=?, payout=? WHERE id=?");
        if ($stmt->execute([$coach_id, $location_id, $student, $date, $time ?: null, $payout, $edit_id])) {
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

// Page setup
$pageTitle = 'Private Classes | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 20px; }

    .form-card { flex: 0 0 320px; }
    .form-card.sticky { position: sticky; top: 20px; }

    .data-card {
        flex: 1;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .payout-input {
        font-weight: bold;
        color: var(--success);
        border-color: var(--success) !important;
    }

    /* Flatpickr preset buttons */
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
        transition: all 0.2s;
    }

    .flatpickr-presets button:hover {
        background: #e9ecef;
        border-color: #007bff;
    }
CSS;

require_once 'includes/header.php';
?>

<div class="top-bar">
    <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <h2 class="page-title"><i class="fas fa-money-bill-wave"></i> Private Classes Manager</h2>
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
                        <td><?= e($r['coach_name']) ?></td>
                        <td><?= e($r['loc_name']) ?></td>
                        <td><?= e($r['student_name']) ?></td>
                        <td class="font-bold text-success">$<?= number_format($r['payout'], 2) ?></td>
                        <td class="table-actions">
                            <button type="button" onclick='editEntry(<?= json_encode($r) ?>)' class="btn-icon"><i class="fas fa-edit"></i></button>
                            <form method="POST" onsubmit="return confirm('Delete?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                <button class="btn-icon danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extraJs = <<<JS
    const rateMap = {$rates_map_json};

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
        document.querySelector('input[name="student_name"]').value = data.student_name;
        document.querySelector('input[name="date"]').value = data.class_date;
        document.querySelector('input[name="time"]').value = data.class_time || '';
        document.getElementById('payout').value = data.payout;

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

        document.getElementById('form-title').textContent = 'Record Class';
        document.getElementById('submit-btn').textContent = 'Save Entry';
        document.getElementById('submit-btn').className = 'btn btn-primary btn-block';
        document.getElementById('cancel-btn').classList.add('hidden');

        document.querySelector('input[name="date"]').value = '{$today}';
    }
JS;

$rates_map_json = json_encode($rates_map);
$today = date('Y-m-d');

// Re-output the JS with proper values
echo "<script>
    const rateMap = " . json_encode($rates_map) . ";

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

        document.getElementById('form-title').textContent = 'Record Class';
        document.getElementById('submit-btn').textContent = 'Save Entry';
        document.getElementById('submit-btn').className = 'btn btn-primary btn-block';
        document.getElementById('cancel-btn').classList.add('hidden');

        document.querySelector('input[name=\"date\"]').value = '" . date('Y-m-d') . "';
        if (typeof entryPicker !== 'undefined') entryPicker.setDate('" . date('Y-m-d') . "');
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
    let filterStartPicker, filterEndPicker;

    const filterFpConfig = {
        dateFormat: 'Y-m-d',
        locale: { firstDayOfWeek: 0 }
    };

    filterStartPicker = flatpickr('#filter_start', {
        ...filterFpConfig,
        onReady: function(selectedDates, dateStr, instance) {
            instance.calendarContainer.appendChild(createPresetButtons(instance, filterStartPicker, filterEndPicker));
        },
        onChange: function(selectedDates) {
            if (selectedDates[0] && filterEndPicker) {
                filterEndPicker.set('minDate', selectedDates[0]);
            }
        }
    });

    filterEndPicker = flatpickr('#filter_end', {
        ...filterFpConfig,
        onReady: function(selectedDates, dateStr, instance) {
            instance.calendarContainer.appendChild(createPresetButtons(instance, filterStartPicker, filterEndPicker));
        },
        onChange: function(selectedDates) {
            if (selectedDates[0] && filterStartPicker) {
                filterStartPicker.set('maxDate', selectedDates[0]);
            }
        }
    });
</script>";

require_once 'includes/footer.php';
?>
