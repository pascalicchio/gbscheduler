<?php
// reports.php - INDIVIDUAL PAYROLL REPORT (Fixed Logic)
require_once 'includes/config.php';

// Require login
requireAuth();

$is_admin = isAdmin();
$logged_in_user_id = getUserId();

// Determine Target User
$target_user_id = $logged_in_user_id;
if ($is_admin && isset($_GET['coach_id']) && $_GET['coach_id'] !== '') {
    $target_user_id = $_GET['coach_id'];
}

// Date Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// 1. Fetch User Data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$target_user_id]);
$coach = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Regular Classes
$sql_regular = "
    SELECT ea.class_date, ea.position, ct.class_name, ct.start_time, ct.end_time, l.id as location_id, l.name as location_name,
           TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
    FROM event_assignments ea
    JOIN class_templates ct ON ea.template_id = ct.id
    JOIN locations l ON ct.location_id = l.id
    WHERE ea.user_id = :uid AND ea.class_date BETWEEN :start AND :end
    ORDER BY ea.class_date ASC, ct.start_time ASC
";
$stmt_reg = $pdo->prepare($sql_regular);
$stmt_reg->execute(['uid' => $target_user_id, 'start' => $start_date, 'end' => $end_date]);
$regular_classes = $stmt_reg->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Private Classes
$sql_private = "
    SELECT pc.*, l.name as location_name
    FROM private_classes pc
    JOIN locations l ON pc.location_id = l.id
    WHERE pc.user_id = :uid AND pc.class_date BETWEEN :start AND :end
    ORDER BY pc.class_date ASC, pc.class_time ASC
";
$stmt_priv = $pdo->prepare($sql_private);
$stmt_priv->execute(['uid' => $target_user_id, 'start' => $start_date, 'end' => $end_date]);
$private_classes = $stmt_priv->fetchAll(PDO::FETCH_ASSOC);

// --- PROCESS DATA ---
$locations_data = [];
$grand_total_pay = 0;
$grand_total_hours = 0;
$grand_total_classes = 0;

foreach ($regular_classes as $rc) {
    $lid = $rc['location_id'];
    if (!isset($locations_data[$lid])) {
        $locations_data[$lid] = ['name' => $rc['location_name'], 'reg' => [], 'priv' => [], 'totals' => ['reg_pay' => 0, 'priv_pay' => 0]];
    }

    // --- LOGIC FIX START ---
    $hours = (float)$rc['hours'];

    // Apply Minimum 1-Hour Rule (Also fixes negative times like -11.0)
    if ($hours < 1) {
        $hours = 1.0;
    }
    $rc['hours'] = $hours; // Update array for display
    // --- LOGIC FIX END ---

    $rate = ($rc['position'] === 'head') ? $coach['rate_head_coach'] : $coach['rate_helper'];
    $pay = $hours * $rate;
    $rc['pay'] = $pay;

    $locations_data[$lid]['reg'][] = $rc;
    $locations_data[$lid]['totals']['reg_pay'] += $pay;
    $grand_total_hours += $hours;
    $grand_total_classes++;
}

foreach ($private_classes as $pc) {
    $lid = $pc['location_id'];
    if (!isset($locations_data[$lid])) {
        $locations_data[$lid] = ['name' => $pc['location_name'], 'reg' => [], 'priv' => [], 'totals' => ['reg_pay' => 0, 'priv_pay' => 0]];
    }

    $final_pay = $pc['payout'];

    $locations_data[$lid]['priv'][] = $pc;
    $locations_data[$lid]['totals']['priv_pay'] += $final_pay;
    $grand_total_classes++;
}

foreach ($locations_data as $ld) {
    $grand_total_pay += $ld['totals']['reg_pay'] + $ld['totals']['priv_pay'];
}

$all_coaches = $is_admin ? $pdo->query("SELECT id, name FROM users WHERE role != 'manager' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

// Page setup
$pageTitle = 'Payroll Report: ' . e($coach['name']) . ' | GB Scheduler';
$extraCss = <<<CSS
        :root {
            --primary: #007bff;
            --bg: #f4f6f9;
        }

        body {
            font-family: sans-serif;
            background: var(--bg);
            padding: 20px;
            color: #333;
            margin: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .btn-back {
            padding: 10px 15px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }

        .filter-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            font-size: 0.8em;
            margin-bottom: 5px;
            text-transform: uppercase;
            color: #777;
        }

        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 35px;
            box-sizing: border-box;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            height: 35px;
            box-sizing: border-box;
        }

        .date-presets {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #eee;
            flex-wrap: wrap;
        }

        .date-presets button {
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.2s;
        }

        .date-presets button:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }

        .date-presets button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .page-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .page-header h2 {
                font-size: 1.3em;
            }

            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .form-group {
                width: 100%;
            }

            .form-group input,
            .form-group select {
                width: 100%;
            }

            .btn-filter {
                width: 100%;
                margin-top: 5px;
            }

            .date-presets {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .stat-card .value {
                font-size: 1.8em;
            }

            .loc-header {
                flex-direction: column;
                gap: 5px;
            }

            .loc-summary {
                font-size: 0.8em;
            }

            table {
                font-size: 0.85em;
            }

            th, td {
                padding: 8px 10px;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            border: 1px solid #e1e4e8;
        }

        .stat-card h3 {
            margin: 0 0 5px 0;
            font-size: 0.85em;
            text-transform: uppercase;
            color: #777;
            letter-spacing: 1px;
        }

        .stat-card .value {
            font-size: 2.2em;
            font-weight: 700;
            color: #2c3e50;
        }

        .money {
            color: #28a745;
        }

        .location-section {
            margin-bottom: 40px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid #e1e4e8;
        }

        .loc-header {
            background: #2c3e50;
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            font-size: 1.1em;
        }

        .loc-summary {
            font-size: 0.9em;
            opacity: 0.9;
            font-weight: normal;
        }

        .loc-summary strong {
            color: #81ffb3;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #fff;
            color: #999;
            font-size: 0.8em;
            text-transform: uppercase;
            border-bottom: 2px solid #eee;
        }

        .money-cell {
            font-family: monospace;
            font-weight: bold;
            color: #28a745;
            text-align: right;
        }

        .sub-header {
            background: #f8f9fa;
            padding: 8px 20px;
            font-weight: bold;
            font-size: 0.8em;
            text-transform: uppercase;
            color: #555;
            border-bottom: 1px solid #eee;
            margin-top: 0;
        }

        .badge-role {
            font-size: 0.8em;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            background: #e2e6ea;
            color: #555;
        }
CSS;

require_once 'includes/header.php';
?>

    <div class="page-header">
        <h2 style="margin:0; color:#2c3e50;">Payroll Report: <?= e($coach['name']) ?></h2>
        <a href="dashboard.php" class="btn-back">Back to Schedule</a>
    </div>

    <form class="filter-card" method="GET" id="reportForm">
        <div class="filter-row">
            <?php if ($is_admin): ?>
                <div class="form-group">
                    <label>Select Coach</label>
                    <select name="coach_id">
                        <?php foreach ($all_coaches as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($target_user_id == $c['id']) ? 'selected' : '' ?>><?= $c['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date" id="start_date" value="<?= $start_date ?>">
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date" id="end_date" value="<?= $end_date ?>">
            </div>
            <div class="form-group">
                <button type="submit" class="btn-filter">Generate Report</button>
            </div>
        </div>
        <div class="date-presets">
            <button type="button" onclick="setPreset('this-month')">This Month</button>
            <button type="button" onclick="setPreset('last-month')">Last Month</button>
            <button type="button" onclick="setPreset('this-week')">This Week</button>
            <button type="button" onclick="setPreset('last-week')">Last Week</button>
        </div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Classes</h3>
            <div class="value"><?= $grand_total_classes ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Regular Hours</h3>
            <div class="value"><?= number_format($grand_total_hours, 1) ?> <small style="font-size:0.4em; color:#999; vertical-align:middle;">HRS</small></div>
        </div>
        <div class="stat-card">
            <h3>Total Payout</h3>
            <div class="value money">$<?= number_format($grand_total_pay, 2) ?></div>
        </div>
    </div>

    <?php foreach ($locations_data as $loc_id => $data): ?>
        <div class="location-section">
            <div class="loc-header">
                <span><i class="fas fa-map-marker-alt"></i> <?= $data['name'] ?></span>
                <span class="loc-summary">Regular: $<?= number_format($data['totals']['reg_pay'], 2) ?> &nbsp;|&nbsp; Privates: $<?= number_format($data['totals']['priv_pay'], 2) ?> &nbsp;|&nbsp; <strong>Total: $<?= number_format($data['totals']['reg_pay'] + $data['totals']['priv_pay'], 2) ?></strong></span>
            </div>

            <?php if (!empty($data['reg'])): ?>
                <div class="sub-header">Regular Classes</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Class</th>
                            <th>Role</th>
                            <th>Hours</th>
                            <th style="text-align:right">Pay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['reg'] as $rc): ?>
                            <tr>
                                <td><?= date('M d', strtotime($rc['class_date'])) ?></td>
                                <td><?= e($rc['class_name']) ?></td>
                                <td><span class="badge-role"><?= ucfirst($rc['position']) ?></span></td>
                                <td><?= number_format($rc['hours'], 1) ?></td>
                                <td class="money-cell">$<?= number_format($rc['pay'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($data['priv'])): ?>
                <div class="sub-header">Private Classes / Activities</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student / Activity</th>
                            <th>Time</th>
                            <th style="text-align:right">Payout</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['priv'] as $pc): ?>
                            <tr>
                                <td><?= date('M d', strtotime($pc['class_date'])) ?></td>
                                <td><?= e($pc['student_name']) ?></td>
                                <td><?= $pc['class_time'] ? date('g:i A', strtotime($pc['class_time'])) : '-' ?></td>
                                <td class="money-cell">$<?= number_format($pc['payout'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<script>
function setPreset(preset) {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const today = new Date();
    let start, end;

    // Clear active state from all buttons
    document.querySelectorAll('.date-presets button').forEach(btn => btn.classList.remove('active'));

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
            // Week starts on Sunday
            const dayOfWeek = today.getDay(); // 0 = Sunday
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

    startInput.value = formatDate(start);
    endInput.value = formatDate(end);

    // Mark preset button as active
    event.target.classList.add('active');
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Highlight active preset on page load
document.addEventListener('DOMContentLoaded', function() {
    const startVal = document.getElementById('start_date').value;
    const endVal = document.getElementById('end_date').value;
    const today = new Date();

    // Check if current dates match any preset
    const thisMonthStart = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
    const thisMonthEnd = formatDate(new Date(today.getFullYear(), today.getMonth() + 1, 0));

    if (startVal === thisMonthStart && endVal === thisMonthEnd) {
        document.querySelector('.date-presets button:nth-child(1)').classList.add('active');
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>