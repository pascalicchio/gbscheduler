<?php
// user_form.php - ADD/EDIT USER
require_once 'includes/config.php';

// Require admin or manager access
requireAuth(['admin', 'manager']);

// HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    // Convert coach_types array to comma-separated string for SET column
    $coach_types_arr = $_POST['coach_types'] ?? ['bjj'];
    $coach_type = implode(',', $coach_types_arr);
    $color = $_POST['color_code'];
    $rate_head = $_POST['rate_head_coach'] ?? 0;
    $rate_helper = $_POST['rate_helper'] ?? 0;
    $fixed_salary = $_POST['fixed_salary'] ?? 0;
    $fixed_salary_location_id = !empty($_POST['fixed_salary_location_id']) ? $_POST['fixed_salary_location_id'] : null;

    // Process commission tiers
    $tiers = [];
    if (isset($_POST['tier_min']) && is_array($_POST['tier_min'])) {
        foreach ($_POST['tier_min'] as $idx => $min) {
            $max = $_POST['tier_max'][$idx] ?? '';
            $rate = $_POST['tier_rate'][$idx] ?? 0;

            if ($min !== '' && $rate > 0) {
                $tiers[] = [
                    'min' => (int)$min,
                    'max' => ($max !== '' && $max !== null) ? (int)$max : null,
                    'rate' => (float)$rate
                ];
            }
        }
    }
    $commission_tiers = !empty($tiers) ? json_encode($tiers) : null;

    $payment_frequency = $_POST['payment_frequency'] ?? 'weekly';
    $payment_method = $_POST['payment_method'] ?? 'adp';
    $payment_info = trim($_POST['payment_info'] ?? '');
    $location_ids = $_POST['locations'] ?? [];
    $rates = $_POST['rates'] ?? [];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id) {
        // UPDATE EXISTING USER
        $sql = "UPDATE users SET name=?, email=?, role=?, coach_type=?, color_code=?, rate_head_coach=?, rate_helper=?, fixed_salary=?, fixed_salary_location_id=?, commission_tiers=?, is_active=?, payment_frequency=?, payment_method=?, payment_info=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $role, $coach_type, $color, $rate_head, $rate_helper, $fixed_salary, $fixed_salary_location_id, $commission_tiers, $is_active, $payment_frequency, $payment_method, $payment_info ?: null, $id]);

        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$pass, $id]);
        }
    } else {
        // CREATE NEW USER
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, email, password, role, coach_type, color_code, rate_head_coach, rate_helper, fixed_salary, fixed_salary_location_id, commission_tiers, is_active, payment_frequency, payment_method, payment_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $pass, $role, $coach_type, $color, $rate_head, $rate_helper, $fixed_salary, $fixed_salary_location_id, $commission_tiers, $is_active, $payment_frequency, $payment_method, $payment_info ?: null]);
        $id = $pdo->lastInsertId();
    }

    // UPDATE LOCATIONS
    $pdo->prepare("DELETE FROM user_locations WHERE user_id=?")->execute([$id]);
    $stmt_loc = $pdo->prepare("INSERT INTO user_locations (user_id, location_id) VALUES (?, ?)");
    foreach ($location_ids as $lid) {
        $stmt_loc->execute([$id, $lid]);
    }

    // UPDATE PRIVATE RATES
    $pdo->prepare("DELETE FROM private_rates WHERE user_id=?")->execute([$id]);
    $stmt_rate = $pdo->prepare("INSERT INTO private_rates (user_id, location_id, rate, discount_percent) VALUES (?, ?, ?, ?)");
    foreach ($rates as $lid => $data) {
        if ($data['amount'] != '' || $data['percent'] != '') {
            $rate_val = $data['amount'] ?: 0;
            $disc_val = $data['percent'] ?: 0;
            $stmt_rate->execute([$id, $lid, $rate_val, $disc_val]);
        }
    }

    header("Location: users.php");
    exit();
}

// FETCH DATA FOR VIEW
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$user = [
    'id' => '', 'name' => '', 'email' => '',
    'rate_head_coach' => '0.00', 'rate_helper' => '0.00',
    'fixed_salary' => '0.00', 'fixed_salary_location_id' => null,
    'commission_tiers' => null,
    'coach_type' => 'bjj', 'role' => 'user',
    'color_code' => '#3788d8', 'is_active' => 1,
    'payment_frequency' => 'weekly',
    'payment_method' => 'adp',
    'payment_info' => ''
];

$assigned_locations = [];
$private_rates = [];
$commission_tiers = [];
$is_edit = false;
$title = "Add New User";

if (isset($_GET['id'])) {
    $is_edit = true;
    $title = "Edit User";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $fetched_user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fetched_user) {
        $user = $fetched_user;
    } else {
        die("User not found.");
    }

    $stmt_loc = $pdo->prepare("SELECT location_id FROM user_locations WHERE user_id = ?");
    $stmt_loc->execute([$user['id']]);
    $assigned_locations = $stmt_loc->fetchAll(PDO::FETCH_COLUMN);

    $stmt_rates = $pdo->prepare("SELECT location_id, rate, discount_percent FROM private_rates WHERE user_id = ?");
    $stmt_rates->execute([$user['id']]);
    foreach ($stmt_rates->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $private_rates[$r['location_id']] = $r;
    }

    // Parse commission tiers
    if (!empty($user['commission_tiers'])) {
        $commission_tiers = json_decode($user['commission_tiers'], true) ?: [];
    }
}

// Page setup
$pageTitle = $title . ' | GB Scheduler';
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
        max-width: 820px;
        margin: 0 auto 24px;
        display: flex;
        justify-content: space-between;
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

    .btn-back {
        padding: 10px 18px;
        background: white;
        color: #2c3e50;
        border: 2px solid #e8ecf2;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn-back:hover {
        background: rgba(0, 201, 255, 0.05);
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
    }

    .page-header-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }
[x-cloak] { display: none !important; }

    /* Form Container */
    .form-container {
        max-width: 820px;
        margin: 0 auto;
    }

    /* Section Cards */
    .section-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border-light);
        margin-bottom: 20px;
        position: relative;
    }

    .section-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background-image: var(--gradient-primary);
        border-radius: 16px 16px 0 0;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title i {
        background-image: var(--gradient-primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        font-size: 1.1rem;
    }

    /* Form Rows */
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 0;
    }

    .form-group {
        margin-bottom: 0;
    }

    /* Labels & Inputs (approved style) */
    .section-card label {
        display: block;
        font-weight: 700;
        font-size: 0.75rem;
        margin-bottom: 8px;
        text-transform: uppercase;
        color: #2c3e50;
        letter-spacing: 0.05em;
    }

    .section-card input,
    .section-card select,
    .section-card textarea {
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

    .section-card input:focus,
    .section-card select:focus,
    .section-card textarea:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .form-text {
        font-size: 0.78rem;
        color: var(--text-secondary);
        margin: -16px 0 20px 0;
    }

    /* Location Grid */
    .loc-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        padding: 16px;
        background: #f8fafb;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .loc-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .loc-item input {
        width: auto;
        margin: 0 0 0 0;
        padding: 0;
    }

    .loc-item label {
        margin: 0;
        font-weight: 500;
        font-size: 0.9rem;
        text-transform: none;
        cursor: pointer;
    }

    /* Martial Arts Checkboxes */
    .martial-arts-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
    }

    .ma-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: white;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.25s ease;
        font-weight: 500;
        font-size: 0.9rem;
        text-transform: none;
        margin: 0;
    }

    .ma-checkbox:hover {
        border-color: rgba(0, 201, 255, 0.3);
        background: rgba(0, 201, 255, 0.05);
    }

    .ma-checkbox input:checked + span {
        color: rgb(0, 201, 255);
        font-weight: 600;
    }

    .ma-checkbox input {
        width: auto;
        margin: 0;
        padding: 0;
    }

    .ma-all {
        background: #f8fafb;
        border-style: dashed;
    }

    /* Rate Boxes */
    .rate-box {
        background: #f8fafb;
        padding: 16px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 12px;
        display: none;
    }

    .rate-box.active {
        display: block;
        animation: fadeIn 0.3s;
    }

    .rate-box strong {
        display: block;
        margin-bottom: 12px;
        font-size: 0.85rem;
        color: var(--text-dark);
    }

    .rate-box .form-row {
        margin-bottom: 0;
    }

    .rate-box input {
        margin-bottom: 0;
    }

    .rate-box label {
        font-size: 0.7rem;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-4px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Active Toggle */
    .active-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        background: #f8fafb;
        padding: 16px;
        border-radius: 10px;
        border: 2px solid #e2e8f0;
        margin-bottom: 20px;
    }

    .active-toggle input {
        width: auto;
        margin: 0;
        padding: 0;
    }

    .active-toggle span {
        font-weight: 600;
        font-size: 0.95rem;
    }

    .active-toggle small {
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    /* Commission Tiers */
    .tier-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        padding: 12px;
        background: #f8fafb;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
    }

    .tier-row input {
        margin: 0;
        padding: 10px 12px;
        text-align: center;
    }

    .tier-row .tier-from { width: 80px; }
    .tier-row .tier-to { width: 120px; }
    .tier-row .tier-rate { width: 100px; }

    .tier-row span {
        font-weight: 600;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .tier-remove {
        background: none;
        border: none;
        color: #dc3545;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: all 0.2s;
        font-size: 0.9rem;
    }

    .tier-remove:hover {
        background: rgba(220, 53, 69, 0.1);
    }

    .btn-add-tier {
        padding: 8px 16px;
        background: white;
        border: 2px dashed #e2e8f0;
        border-radius: 10px;
        color: var(--text-secondary);
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 20px;
    }

    .btn-add-tier:hover {
        border-color: rgb(0, 201, 255);
        color: rgb(0, 201, 255);
        background: rgba(0, 201, 255, 0.05);
    }

    /* Color Input */
    input[type="color"] {
        height: 46px;
        padding: 4px;
        cursor: pointer;
    }

    /* Bottom Actions */
    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        max-width: 820px;
        margin: 0 auto;
    }

    .btn-cancel {
        padding: 12px 24px;
        background: white;
        color: var(--text-secondary);
        border: 2px solid var(--border-light);
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.25s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-cancel:hover {
        background: #f8f9fa;
        border-color: #6c757d;
        color: #495057;
    }

    .btn-save {
        padding: 12px 32px;
        background-image: var(--gradient-primary);
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.25);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-save:hover {
        background-image: var(--gradient-primary-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 201, 255, 0.35);
    }

    @media (max-width: 600px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .tier-row {
            flex-wrap: wrap;
        }

        .form-actions {
            flex-direction: column;
        }

        .btn-cancel, .btn-save {
            width: 100%;
            justify-content: center;
        }
    }
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-user-cog"></i> <?= $title ?></h2>
    <div class="page-header-right">
        <a href="users.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Users</a>
        <?php include 'includes/nav-menu.php'; ?>
    </div>
</div>

<form method="POST" class="form-container">
    <input type="hidden" name="id" value="<?= $user['id'] ?>">

    <!-- Section 1: Basic Info -->
    <div class="section-card">
        <div class="section-title"><i class="fas fa-id-card"></i> Basic Information</div>
        <div class="form-row">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" value="<?= e($user['name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= e($user['email']) ?>" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>System Role</label>
                <select name="role">
                    <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>Coach (Standard)</option>
                    <option value="manager" <?= $user['role'] == 'manager' ? 'selected' : '' ?>>Manager (No Classes)</option>
                    <option value="employee" <?= $user['role'] == 'employee' ? 'selected' : '' ?>>Employee (Salary Only)</option>
                    <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Administrator</option>
                </select>
                <p class="form-text">Employees only receive salary/commission — no classes or schedule access.</p>
            </div>
            <div class="form-group">
                <label>Password <?= $is_edit ? '<small class="text-gray-500 normal-case tracking-normal font-normal">(Leave blank to keep)</small>' : '' ?></label>
                <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Martial Arts</label>
                <?php $coach_types = explode(',', $user['coach_type'] ?? ''); ?>
                <div class="martial-arts-grid">
                    <label class="ma-checkbox">
                        <input type="checkbox" name="coach_types[]" value="bjj" <?= in_array('bjj', $coach_types) ? 'checked' : '' ?>>
                        <span>Jiu Jitsu</span>
                    </label>
                    <label class="ma-checkbox">
                        <input type="checkbox" name="coach_types[]" value="mt" <?= in_array('mt', $coach_types) ? 'checked' : '' ?>>
                        <span>Muay Thai</span>
                    </label>
                    <label class="ma-checkbox">
                        <input type="checkbox" name="coach_types[]" value="mma" <?= in_array('mma', $coach_types) ? 'checked' : '' ?>>
                        <span>MMA</span>
                    </label>
                    <label class="ma-checkbox ma-all">
                        <input type="checkbox" id="select-all-ma" onchange="toggleAllMartialArts(this)">
                        <span>All</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Color</label>
                <input type="color" name="color_code" value="<?= $user['color_code'] ?>">
            </div>
        </div>
        <label class="active-toggle">
            <input type="checkbox" name="is_active" value="1" <?= $user['is_active'] ? 'checked' : '' ?>>
            <span>Active Coach?</span>
            <small>(Uncheck to hide from schedule but keep history)</small>
        </label>
    </div>

    <!-- Section 2: Payment -->
    <div class="section-card">
        <div class="section-title"><i class="fas fa-credit-card"></i> Payment Settings</div>
        <div class="form-row">
            <div class="form-group">
                <label>Payment Method</label>
                <select name="payment_method">
                    <?php
                    $methods = ['adp' => 'ADP', 'zelle' => 'Zelle', 'wire' => 'Wire', 'cash' => 'Cash', 'check' => 'Check', 'paypal' => 'PayPal'];
                    foreach ($methods as $val => $label):
                    ?>
                        <option value="<?= $val ?>" <?= ($user['payment_method'] ?? 'adp') == $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Payment Frequency</label>
                <select name="payment_frequency">
                    <option value="weekly" <?= ($user['payment_frequency'] ?? 'weekly') == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="biweekly" <?= ($user['payment_frequency'] ?? '') == 'biweekly' ? 'selected' : '' ?>>Biweekly</option>
                    <option value="monthly" <?= ($user['payment_frequency'] ?? '') == 'monthly' ? 'selected' : '' ?>>Monthly (ADP)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Payment Details</label>
            <input type="text" name="payment_info" value="<?= e($user['payment_info'] ?? '') ?>" placeholder="e.g. Zelle email, PayPal ID, bank account">
            <p class="form-text">Account info for the selected payment method.</p>
        </div>
    </div>

    <!-- Section 3: Rates -->
    <div class="section-card">
        <div class="section-title"><i class="fas fa-dollar-sign"></i> Rates & Compensation</div>
        <div class="form-row">
            <div class="form-group">
                <label>Head Coach Rate ($/class)</label>
                <input type="number" step="0.01" name="rate_head_coach" value="<?= $user['rate_head_coach'] ?>">
            </div>
            <div class="form-group">
                <label>Helper Rate ($/class)</label>
                <input type="number" step="0.01" name="rate_helper" value="<?= $user['rate_helper'] ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Fixed Monthly Salary ($)</label>
                <input type="number" step="0.01" name="fixed_salary" value="<?= $user['fixed_salary'] ?? '0.00' ?>">
                <p class="form-text">For managers/employees with fixed monthly salary.</p>
            </div>
            <div class="form-group">
                <label>Paid By Location</label>
                <select name="fixed_salary_location_id">
                    <option value="">None</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= ($user['fixed_salary_location_id'] ?? '') == $l['id'] ? 'selected' : '' ?>>
                            <?= e($l['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="form-text">Which location pays this salary.</p>
            </div>
        </div>

        <label>Commission Tiers</label>
        <p class="form-text mt-0">Progressive calculation: each tier is calculated separately and summed.</p>
        <div id="commission-tiers">
            <?php if (empty($commission_tiers)): ?>
                <div class="tier-row" data-tier="0">
                    <input type="number" name="tier_min[]" placeholder="From" min="0" class="tier-from">
                    <span>—</span>
                    <input type="number" name="tier_max[]" placeholder="To (∞)" min="0" class="tier-to">
                    <span>: $</span>
                    <input type="number" step="0.01" name="tier_rate[]" placeholder="Rate" min="0" class="tier-rate">
                    <button type="button" class="tier-remove" onclick="removeTier(this)" title="Remove tier">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($commission_tiers as $idx => $tier): ?>
                    <div class="tier-row" data-tier="<?= $idx ?>">
                        <input type="number" name="tier_min[]" value="<?= $tier['min'] ?>" placeholder="From" min="0" class="tier-from">
                        <span>—</span>
                        <input type="number" name="tier_max[]" value="<?= $tier['max'] ?? '' ?>" placeholder="To (∞)" min="0" class="tier-to">
                        <span>: $</span>
                        <input type="number" step="0.01" name="tier_rate[]" value="<?= $tier['rate'] ?>" placeholder="Rate" min="0" class="tier-rate">
                        <button type="button" class="tier-remove" onclick="removeTier(this)" title="Remove tier">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="btn-add-tier" onclick="addTier()">
            <i class="fas fa-plus"></i> Add Tier
        </button>
    </div>

    <!-- Section 4: Locations & Private Rates -->
    <div class="section-card">
        <div class="section-title"><i class="fas fa-map-marker-alt"></i> Locations & Private Rates</div>
        <label>Assigned Locations</label>
        <div class="loc-grid">
            <?php foreach ($locations as $l):
                $checked = in_array($l['id'], $assigned_locations) ? 'checked' : '';
            ?>
                <div class="loc-item">
                    <input type="checkbox" name="locations[]" value="<?= $l['id'] ?>" id="loc_<?= $l['id'] ?>" <?= $checked ?> onchange="toggleRateBox(<?= $l['id'] ?>)">
                    <label for="loc_<?= $l['id'] ?>"><?= e($l['name']) ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($locations as $l):
            $r = $private_rates[$l['id']] ?? ['rate' => '0.00', 'discount_percent' => '0.00'];
            $isActive = in_array($l['id'], $assigned_locations) ? 'active' : '';
        ?>
            <div class="rate-box <?= $isActive ?>" id="rate_box_<?= $l['id'] ?>">
                <strong><i class="fas fa-map-pin"></i> <?= e($l['name']) ?></strong>
                <div class="form-row">
                    <div>
                        <label>Private Pay Rate ($)</label>
                        <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][amount]" value="<?= $r['rate'] ?>">
                    </div>
                    <div>
                        <label>Fee / Discount (%)</label>
                        <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][percent]" value="<?= $r['discount_percent'] ?>" placeholder="e.g. 4.00">
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="users.php" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
        <button type="submit" class="btn-save"><i class="fas fa-check"></i> Save User</button>
    </div>
</form>

<script>
function toggleRateBox(id) {
    const box = document.getElementById('rate_box_' + id);
    const check = document.getElementById('loc_' + id);
    if (check.checked) box.classList.add('active');
    else box.classList.remove('active');
}

function toggleAllMartialArts(selectAllCheckbox) {
    const checkboxes = document.querySelectorAll('input[name="coach_types[]"]');
    checkboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
}

// Update "Select All" state on page load and when individual checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[name="coach_types[]"]');
    const selectAll = document.getElementById('select-all-ma');

    function updateSelectAll() {
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        selectAll.checked = allChecked;
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateSelectAll));
    updateSelectAll();
});

// Commission tier management
let tierCounter = <?= count($commission_tiers) ?>;

function addTier() {
    const container = document.getElementById('commission-tiers');
    const tierRow = document.createElement('div');
    tierRow.className = 'tier-row';
    tierRow.dataset.tier = tierCounter++;
    tierRow.innerHTML = `
        <input type="number" name="tier_min[]" placeholder="From" min="0" class="tier-from">
        <span>—</span>
        <input type="number" name="tier_max[]" placeholder="To (∞)" min="0" class="tier-to">
        <span>: $</span>
        <input type="number" step="0.01" name="tier_rate[]" placeholder="Rate" min="0" class="tier-rate">
        <button type="button" class="tier-remove" onclick="removeTier(this)" title="Remove tier">
            <i class="fas fa-trash"></i>
        </button>
    `;
    container.appendChild(tierRow);
}

function removeTier(button) {
    const container = document.getElementById('commission-tiers');
    if (container.children.length > 1) {
        button.closest('.tier-row').remove();
    } else {
        alert('You must have at least one tier.');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
