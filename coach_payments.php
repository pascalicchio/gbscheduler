<?php
// coach_payments.php - PAYMENT TRACKING SYSTEM
require_once 'includes/config.php';

// Require admin access
requireAuth(['admin']);

// Get current tab
$tab = $_GET['tab'] ?? 'weekly';
$valid_tabs = ['weekly', 'biweekly', 'monthly', 'history'];
if (!in_array($tab, $valid_tabs)) $tab = 'weekly';

// Calculate default date ranges based on tab
$today = new DateTime();

if ($tab === 'weekly') {
    // Current week (Sunday to Saturday)
    $day_of_week = (int)$today->format('w');
    $week_start = (clone $today)->modify("-{$day_of_week} days");
    $week_end = (clone $week_start)->modify('+6 days');
    $default_start = $week_start->format('Y-m-d');
    $default_end = $week_end->format('Y-m-d');
} elseif ($tab === 'biweekly') {
    // Current 2-week period (starting from a reference Sunday)
    $reference = new DateTime('2024-01-07'); // A known Sunday
    $diff = $today->diff($reference)->days;
    $weeks_since = floor($diff / 14) * 14;
    $period_start = (clone $reference)->modify("+{$weeks_since} days");
    if ($period_start > $today) {
        $period_start->modify('-14 days');
    }
    $period_end = (clone $period_start)->modify('+13 days');
    $default_start = $period_start->format('Y-m-d');
    $default_end = $period_end->format('Y-m-d');
} elseif ($tab === 'monthly') {
    // Current month
    $default_start = $today->format('Y-m-01');
    $default_end = $today->format('Y-m-t');
} else {
    // History - last 3 months
    $default_start = (clone $today)->modify('-3 months')->format('Y-m-01');
    $default_end = $today->format('Y-m-d');
}

$start_date = $_GET['start'] ?? $default_start;
$end_date = $_GET['end'] ?? $default_end;
$filter_coach_id = $_GET['coach_id'] ?? '';
$filter_location_id = $_GET['location_id'] ?? '';

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'mark_paid') {
        $user_id = $_POST['user_id'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $amount = $_POST['amount'];
        $payment_method = $_POST['payment_method'] ?? 'manual';
        $notes = $_POST['notes'] ?? '';
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("INSERT INTO coach_payments (user_id, period_start, period_end, amount, payment_date, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $period_start, $period_end, $amount, $payment_date, $payment_method, $notes, getUserId()]);

        setFlash("Payment recorded successfully!", 'success');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_payment') {
        $payment_id = $_POST['payment_id'];
        $pdo->prepare("DELETE FROM coach_payments WHERE id = ?")->execute([$payment_id]);
        setFlash("Payment deleted.", 'error');
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

$msg = getFlash();

// Get coaches filtered by payment frequency (for non-history tabs)
$frequency_filter = '';
if ($tab !== 'history') {
    $frequency_filter = $tab;
}

// Fetch all users
$coaches_sql = "SELECT * FROM users WHERE 1=1";
if ($frequency_filter) {
    $coaches_sql .= " AND payment_frequency = :freq";
}
$coaches_sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($coaches_sql);
if ($frequency_filter) {
    $stmt->execute(['freq' => $frequency_filter]);
} else {
    $stmt->execute();
}
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// For history tab, get all users for filter dropdown
$all_coaches = $pdo->query("SELECT id, name FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get all locations for filter dropdown
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate earnings for each coach (for payment tabs)
$coach_data = [];
if ($tab !== 'history') {
    foreach ($coaches as $c) {
        if ($filter_coach_id && $c['id'] != $filter_coach_id) continue;

        $coach_data[$c['id']] = [
            'info' => $c,
            'regular_pay' => 0,
            'private_pay' => 0,
            'total_pay' => 0,
            'class_count' => 0
        ];
    }

    // Regular Classes
    $sql_reg = "
        SELECT ea.user_id, ea.position,
               TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) / 60 as hours
        FROM event_assignments ea
        JOIN class_templates ct ON ea.template_id = ct.id
        WHERE ea.class_date BETWEEN :start AND :end
    ";
    $reg_params = ['start' => $start_date, 'end' => $end_date];
    if ($filter_location_id) {
        $sql_reg .= " AND ct.location_id = :loc";
        $reg_params['loc'] = $filter_location_id;
    }
    $stmt = $pdo->prepare($sql_reg);
    $stmt->execute($reg_params);
    $reg_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reg_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $hours = (float)$row['hours'];
        if ($hours < 1) $hours = 1.0;

        $head_rate = $coach_data[$uid]['info']['rate_head_coach'] ?? 0;
        $helper_rate = $coach_data[$uid]['info']['rate_helper'] ?? 0;
        $rate = ($row['position'] === 'head') ? $head_rate : $helper_rate;
        $pay = $hours * $rate;

        $coach_data[$uid]['regular_pay'] += $pay;
        $coach_data[$uid]['total_pay'] += $pay;
        $coach_data[$uid]['class_count']++;
    }

    // Private Classes
    $sql_priv = "
        SELECT pc.user_id, pc.payout
        FROM private_classes pc
        WHERE pc.class_date BETWEEN :start AND :end
    ";
    $priv_params = ['start' => $start_date, 'end' => $end_date];
    if ($filter_location_id) {
        $sql_priv .= " AND pc.location_id = :loc";
        $priv_params['loc'] = $filter_location_id;
    }
    $stmt = $pdo->prepare($sql_priv);
    $stmt->execute($priv_params);
    $priv_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($priv_rows as $row) {
        $uid = $row['user_id'];
        if (!isset($coach_data[$uid])) continue;

        $coach_data[$uid]['private_pay'] += $row['payout'];
        $coach_data[$uid]['total_pay'] += $row['payout'];
        $coach_data[$uid]['class_count']++;
    }

    // Check which coaches have been paid for this period
    $paid_check = $pdo->prepare("
        SELECT user_id, id, amount, payment_date, payment_method
        FROM coach_payments
        WHERE period_start = ? AND period_end = ?
    ");
    $paid_check->execute([$start_date, $end_date]);
    $paid_records = [];
    foreach ($paid_check->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paid_records[$p['user_id']] = $p;
    }
}

// For history tab, get payment records
$payment_history = [];
if ($tab === 'history') {
    $history_sql = "
        SELECT cp.*, u.name as coach_name
        FROM coach_payments cp
        JOIN users u ON cp.user_id = u.id
        WHERE cp.payment_date BETWEEN :start AND :end
    ";
    $params = ['start' => $start_date, 'end' => $end_date];

    if ($filter_coach_id) {
        $history_sql .= " AND cp.user_id = :coach";
        $params['coach'] = $filter_coach_id;
    }

    $history_sql .= " ORDER BY cp.payment_date DESC, cp.created_at DESC";
    $stmt = $pdo->prepare($history_sql);
    $stmt->execute($params);
    $payment_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$total_owed = 0;
$total_paid = 0;
foreach ($coach_data as $cd) {
    $total_owed += $cd['total_pay'];
}
foreach ($paid_records ?? [] as $pr) {
    $total_paid += $pr['amount'];
}

// Page setup
$pageTitle = 'Coach Payments | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 20px; }

    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
    }

    .tab-btn {
        padding: 12px 24px;
        border: none;
        background: white;
        cursor: pointer;
        font-weight: 600;
        color: #666;
        transition: all 0.2s;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .tab-btn:hover {
        background: #f0f7ff;
        color: var(--primary);
    }

    .tab-btn.active {
        color: white;
        background: var(--primary);
        box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);
    }

    .controls {
        background: white;
        padding: 15px;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        display: flex;
        gap: 15px;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        font-size: 0.8em;
        font-weight: bold;
        margin-bottom: 4px;
        color: #555;
        text-transform: uppercase;
    }

    .form-group input[type="text"],
    .form-group select {
        min-width: 140px;
        height: 38px;
    }

    .form-group .btn {
        height: 38px;
    }

    .summary-bar {
        background: #2c3e50;
        color: white;
        padding: 20px;
        border-radius: var(--radius);
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .summary-bar h3 {
        margin: 0 0 5px 0;
        font-size: 0.9em;
        opacity: 0.8;
        font-weight: normal;
        color: #fff;
    }

    .summary-bar .big-number {
        font-size: 1.6em;
        font-weight: bold;
    }

    .payment-table {
        width: 100%;
        background: white;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .payment-table th {
        background: #f8f9fa;
        padding: 12px 16px;
        text-align: center;
        font-size: 0.85em;
        text-transform: uppercase;
        color: #666;
        border-bottom: 2px solid #e9ecef;
    }

    .payment-table th:first-child {
        text-align: left;
    }

    .payment-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #eee;
        text-align: center;
    }

    .payment-table td:first-child {
        text-align: left;
    }

    /* Column widths */
    .payment-table th:nth-child(1) { width: 20%; }
    .payment-table th:nth-child(2) { width: 8%; }
    .payment-table th:nth-child(3) { width: 14%; }
    .payment-table th:nth-child(4) { width: 14%; }
    .payment-table th:nth-child(5) { width: 14%; }
    .payment-table th:nth-child(6) { width: 15%; }
    .payment-table th:nth-child(7) { width: 15%; }

    .payment-table tbody tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    .payment-table tbody tr:hover {
        background-color: #e9ecef;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.85em;
        font-weight: 600;
    }

    .status-paid {
        background: #d4edda;
        color: #155724;
    }

    .status-unpaid {
        background: #fff3cd;
        color: #856404;
    }

    .text-right { text-align: right; }
    .text-success { color: var(--success); }
    .font-bold { font-weight: bold; }

    .btn-pay {
        background: var(--success);
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: 600;
    }

    .btn-pay:hover {
        background: #218838;
    }

    .btn-view {
        background: #6c757d;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.85em;
    }

    /* Modal styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal {
        background: white;
        border-radius: var(--radius);
        width: 100%;
        max-width: 450px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    }

    .modal-header {
        padding: 15px 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5em;
        cursor: pointer;
        color: #999;
    }

    .modal-body {
        padding: 20px;
    }

    .modal-body .form-group {
        margin-bottom: 15px;
    }

    .modal-body input,
    .modal-body select,
    .modal-body textarea {
        width: 100%;
    }

    .modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }

    .empty-state i {
        font-size: 3em;
        color: #ddd;
        margin-bottom: 15px;
    }

    /* Flatpickr presets */
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
        border-color: #007bff;
    }
CSS;

require_once 'includes/header.php';
?>

<div class="top-bar">
    <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <h2 class="page-title"><i class="fas fa-money-check-alt"></i> Coach Payments</h2>
</div>

<?= $msg ?>

<!-- Tabs -->
<div class="tabs">
    <a href="?tab=weekly" class="tab-btn <?= $tab === 'weekly' ? 'active' : '' ?>">
        <i class="fas fa-calendar-week"></i> Weekly
    </a>
    <a href="?tab=biweekly" class="tab-btn <?= $tab === 'biweekly' ? 'active' : '' ?>">
        <i class="fas fa-calendar-alt"></i> Biweekly
    </a>
    <a href="?tab=monthly" class="tab-btn <?= $tab === 'monthly' ? 'active' : '' ?>">
        <i class="fas fa-calendar"></i> Monthly
    </a>
    <a href="?tab=history" class="tab-btn <?= $tab === 'history' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> History
    </a>
</div>

<!-- Filters -->
<form method="GET" class="controls">
    <input type="hidden" name="tab" value="<?= $tab ?>">

    <div class="form-group">
        <label>Start Date</label>
        <input type="text" name="start" id="start_date" value="<?= $start_date ?>" readonly>
    </div>
    <div class="form-group">
        <label>End Date</label>
        <input type="text" name="end" id="end_date" value="<?= $end_date ?>" readonly>
    </div>
    <div class="form-group">
        <label>Location</label>
        <select name="location_id">
            <option value="">All Locations</option>
            <?php foreach ($locations as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $filter_location_id == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Coach</label>
        <select name="coach_id">
            <option value="">All Coaches</option>
            <?php foreach ($all_coaches as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_coach_id == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top: -7px;">Apply</button>
</form>

<?php if ($tab !== 'history'): ?>
    <!-- Summary Bar -->
    <div class="summary-bar">
        <div>
            <h3>Period</h3>
            <div class="big-number"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?></div>
        </div>
        <div>
            <h3>Total Owed</h3>
            <div class="big-number">$<?= number_format($total_owed, 2) ?></div>
        </div>
        <div>
            <h3>Total Paid</h3>
            <div class="big-number" style="color: #28a745;">$<?= number_format($total_paid, 2) ?></div>
        </div>
        <div>
            <h3>Remaining</h3>
            <div class="big-number" style="color: <?= ($total_owed - $total_paid) > 0 ? '#ffc107' : '#28a745' ?>;">
                $<?= number_format($total_owed - $total_paid, 2) ?>
            </div>
        </div>
    </div>

    <!-- Payment Table -->
    <?php if (empty($coach_data)): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <h3>No coaches found</h3>
            <p>No coaches are set to <?= $tab ?> payment frequency.</p>
        </div>
    <?php else: ?>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Coach</th>
                    <th>Classes</th>
                    <th>Regular Pay</th>
                    <th>Private Pay</th>
                    <th>Total Owed</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coach_data as $uid => $data):
                    if ($data['total_pay'] == 0 && !isset($paid_records[$uid])) continue;
                    $is_paid = isset($paid_records[$uid]);
                    $payment = $paid_records[$uid] ?? null;
                ?>
                    <tr>
                        <td class="font-bold"><?= e($data['info']['name']) ?></td>
                        <td><?= $data['class_count'] ?></td>
                        <td>$<?= number_format($data['regular_pay'], 2) ?></td>
                        <td>$<?= number_format($data['private_pay'], 2) ?></td>
                        <td class="font-bold text-success">$<?= number_format($data['total_pay'], 2) ?></td>
                        <td>
                            <?php if ($is_paid): ?>
                                <span class="status-badge status-paid">
                                    <i class="fas fa-check"></i> Paid <?= date('M d', strtotime($payment['payment_date'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-unpaid">
                                    <i class="fas fa-clock"></i> Unpaid
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_paid): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this payment record?');">
                                    <input type="hidden" name="action" value="delete_payment">
                                    <input type="hidden" name="payment_id" value="<?= $payment['id'] ?>">
                                    <button type="submit" class="btn-view" title="Remove payment">
                                        <i class="fas fa-undo"></i> Undo
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn-pay" onclick="openPayModal(<?= $uid ?>, '<?= e($data['info']['name']) ?>', <?= $data['total_pay'] ?>)">
                                    <i class="fas fa-check"></i> Mark Paid
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<?php else: ?>
    <!-- History View -->
    <?php if (empty($payment_history)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h3>No payment history</h3>
            <p>No payments recorded for this period.</p>
        </div>
    <?php else: ?>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Coach</th>
                    <th>Payment Date</th>
                    <th>Period</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payment_history as $p): ?>
                    <tr>
                        <td class="font-bold"><?= e($p['coach_name']) ?></td>
                        <td><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                        <td><?= date('M d', strtotime($p['period_start'])) ?> - <?= date('M d', strtotime($p['period_end'])) ?></td>
                        <td class="font-bold text-success">$<?= number_format($p['amount'], 2) ?></td>
                        <td>
                            <span class="status-badge <?= $p['payment_method'] === 'adp' ? 'status-paid' : 'status-unpaid' ?>">
                                <?= strtoupper($p['payment_method']) ?>
                            </span>
                        </td>
                        <td><?= e($p['notes'] ?: '-') ?></td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this payment record?');">
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<!-- Payment Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-money-check-alt"></i> Record Payment</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="user_id" id="modal_user_id">
            <input type="hidden" name="period_start" value="<?= $start_date ?>">
            <input type="hidden" name="period_end" value="<?= $end_date ?>">

            <div class="modal-body">
                <div class="form-group">
                    <label>Coach</label>
                    <input type="text" id="modal_coach_name" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Period</label>
                    <input type="text" value="<?= date('M d', strtotime($start_date)) ?> - <?= date('M d', strtotime($end_date)) ?>" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" id="modal_amount" required>
                </div>
                <div class="form-group">
                    <label>Payment Date</label>
                    <input type="text" name="payment_date" id="modal_payment_date" value="<?= date('Y-m-d') ?>" required readonly>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="manual">Manual</option>
                        <option value="adp">ADP Payroll</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes (Optional)</label>
                    <textarea name="notes" rows="2" placeholder="Any additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(userId, coachName, amount) {
    document.getElementById('modal_user_id').value = userId;
    document.getElementById('modal_coach_name').value = coachName;
    document.getElementById('modal_amount').value = amount.toFixed(2);
    document.getElementById('payModal').classList.add('active');
}

function closePayModal() {
    document.getElementById('payModal').classList.remove('active');
}

// Close modal on overlay click
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

// Flatpickr initialization
document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('start_date');
    const endInput = document.getElementById('end_date');
    const paymentDateInput = document.getElementById('modal_payment_date');

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

    function createPresetButtons(fp) {
        const presetContainer = document.createElement('div');
        presetContainer.className = 'flatpickr-presets';

        const presets = [
            { label: 'This Week', value: 'this-week' },
            { label: 'Last Week', value: 'last-week' },
            { label: 'This Month', value: 'this-month' },
            { label: 'Last Month', value: 'last-month' }
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

    const startPicker = flatpickr(startInput, {
        ...fpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                endPicker.set('minDate', selectedDates[0]);
            }
        }
    });

    const endPicker = flatpickr(endInput, {
        ...fpConfig,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                startPicker.set('maxDate', selectedDates[0]);
            }
        }
    });

    // Payment date picker (simple, no presets)
    flatpickr(paymentDateInput, {
        dateFormat: 'Y-m-d',
        locale: { firstDayOfWeek: 0 }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
