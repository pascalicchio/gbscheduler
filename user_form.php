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
    $location_ids = $_POST['locations'] ?? [];
    $rates = $_POST['rates'] ?? [];
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id) {
        // UPDATE EXISTING USER
        $sql = "UPDATE users SET name=?, email=?, role=?, coach_type=?, color_code=?, rate_head_coach=?, rate_helper=?, fixed_salary=?, fixed_salary_location_id=?, commission_tiers=?, is_active=?, payment_frequency=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $role, $coach_type, $color, $rate_head, $rate_helper, $fixed_salary, $fixed_salary_location_id, $commission_tiers, $is_active, $payment_frequency, $id]);

        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$pass, $id]);
        }
    } else {
        // CREATE NEW USER
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, email, password, role, coach_type, color_code, rate_head_coach, rate_helper, fixed_salary, fixed_salary_location_id, commission_tiers, is_active, payment_frequency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $pass, $role, $coach_type, $color, $rate_head, $rate_helper, $fixed_salary, $fixed_salary_location_id, $commission_tiers, $is_active, $payment_frequency]);
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
    'payment_frequency' => 'weekly'
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
    body {
        padding: 40px;
        display: flex;
        justify-content: center;
    }

    .form-card {
        width: 100%;
        max-width: 700px;
    }

    .card-body { padding: 30px; }

    .loc-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        border: 1px solid #eee;
        padding: 15px;
        border-radius: var(--radius-sm);
    }

    .loc-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .loc-item input { width: auto; margin: 0; }
    .loc-item label { margin: 0; font-weight: normal; cursor: pointer; }

    .martial-arts-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 10px;
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: var(--radius-sm);
    }

    .ma-checkbox {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: normal;
        margin: 0;
    }

    .ma-checkbox:hover {
        border-color: #007bff;
        background: #f0f7ff;
    }

    .ma-checkbox input:checked + span {
        color: #007bff;
        font-weight: 600;
    }

    .ma-checkbox input { width: auto; margin: 0; }

    .ma-all {
        background: #e9ecef;
        border-style: dashed;
    }

    .rate-box {
        background: #f9f9f9;
        padding: 15px;
        border: 1px solid #eee;
        border-radius: var(--radius-sm);
        margin-bottom: 10px;
        display: none;
    }

    .rate-box.active {
        display: block;
        animation: fadeIn 0.3s;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .active-toggle {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f8f9fa;
        padding: 12px;
        border-radius: var(--radius-sm);
        border: 1px solid #ddd;
    }

    .active-toggle input { width: auto; margin: 0; }

    .tier-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 4px;
    }

    .tier-row input {
        margin: 0;
    }

    .tier-row span {
        font-weight: 500;
        color: #666;
    }
CSS;

require_once 'includes/header.php';
?>

<div class="form-card card">
    <div class="card-header"><i class="fas fa-user-cog"></i> <?= $title ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?= $user['id'] ?>">

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
                    <p class="form-text">Employees only receive salary/commission - no classes or schedule access.</p>
                </div>
                <div class="form-group">
                    <label>Payment Frequency</label>
                    <select name="payment_frequency">
                        <option value="weekly" <?= ($user['payment_frequency'] ?? 'weekly') == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        <option value="biweekly" <?= ($user['payment_frequency'] ?? '') == 'biweekly' ? 'selected' : '' ?>>Biweekly</option>
                        <option value="monthly" <?= ($user['payment_frequency'] ?? '') == 'monthly' ? 'selected' : '' ?>>Monthly (ADP)</option>
                    </select>
                    <p class="form-text">How often this coach is paid.</p>
                </div>
            </div>

            <div class="form-group">
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
            </div>

            <h4 class="mb-1" style="border-bottom:1px solid #eee; padding-bottom:8px;">Private Class Rates & Fees</h4>
            <?php foreach ($locations as $l):
                $r = $private_rates[$l['id']] ?? ['rate' => '0.00', 'discount_percent' => '0.00'];
                $isActive = in_array($l['id'], $assigned_locations) ? 'active' : '';
            ?>
                <div class="rate-box <?= $isActive ?>" id="rate_box_<?= $l['id'] ?>">
                    <strong><?= e($l['name']) ?> Settings</strong>
                    <div class="form-row mt-1">
                        <div>
                            <label style="font-size:0.85em">Private Pay Rate ($)</label>
                            <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][amount]" value="<?= $r['rate'] ?>">
                        </div>
                        <div>
                            <label style="font-size:0.85em">Fee / Discount (%)</label>
                            <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][percent]" value="<?= $r['discount_percent'] ?>" placeholder="e.g. 4.00">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="form-row mt-2">
                <div class="form-group">
                    <label>Standard Class Head Rate ($)</label>
                    <input type="number" step="0.01" name="rate_head_coach" value="<?= $user['rate_head_coach'] ?>">
                </div>
                <div class="form-group">
                    <label>Standard Class Helper Rate ($)</label>
                    <input type="number" step="0.01" name="rate_helper" value="<?= $user['rate_helper'] ?>">
                </div>
            </div>

            <h4 class="mb-1 mt-2" style="border-bottom:1px solid #eee; padding-bottom:8px;">Fixed Salary & Commission</h4>

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
                    <p class="form-text">Which location pays this salary (prevents duplicate in reports).</p>
                </div>
            </div>

            <div class="form-group">
                <label>Commission Tiers</label>
                <p class="form-text mb-1">Progressive calculation: each tier is calculated separately and summed.</p>

                <div id="commission-tiers">
                    <?php if (empty($commission_tiers)): ?>
                        <div class="tier-row" data-tier="0">
                            <input type="number" name="tier_min[]" placeholder="From" min="0" style="width: 80px;">
                            <span> - </span>
                            <input type="number" name="tier_max[]" placeholder="To (empty for 51+)" min="0" style="width: 120px;">
                            <span> : $</span>
                            <input type="number" step="0.01" name="tier_rate[]" placeholder="Rate" min="0" style="width: 100px;">
                            <button type="button" class="btn-icon" onclick="removeTier(this)" title="Remove tier">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($commission_tiers as $idx => $tier): ?>
                            <div class="tier-row" data-tier="<?= $idx ?>">
                                <input type="number" name="tier_min[]" value="<?= $tier['min'] ?>" placeholder="From" min="0" style="width: 80px;">
                                <span> - </span>
                                <input type="number" name="tier_max[]" value="<?= $tier['max'] ?? '' ?>" placeholder="To (empty for 51+)" min="0" style="width: 120px;">
                                <span> : $</span>
                                <input type="number" step="0.01" name="tier_rate[]" value="<?= $tier['rate'] ?>" placeholder="Rate" min="0" style="width: 100px;">
                                <button type="button" class="btn-icon" onclick="removeTier(this)" title="Remove tier">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="btn btn-outline mt-1" onclick="addTier()">
                    <i class="fas fa-plus"></i> Add Tier
                </button>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Martial Arts</label>
                    <?php
                    $coach_types = explode(',', $user['coach_type'] ?? '');
                    ?>
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
                            <span>Select All</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="color" name="color_code" value="<?= $user['color_code'] ?>" style="height:42px; padding:2px;">
                </div>
            </div>

            <div class="form-group">
                <label>Password <?= $is_edit ? '<small class="text-muted">(Leave blank to keep)</small>' : '' ?></label>
                <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
            </div>

            <label class="active-toggle mt-2">
                <input type="checkbox" name="is_active" value="1" <?= $user['is_active'] ? 'checked' : '' ?>>
                <span class="font-bold">Active Coach?</span>
                <small class="text-muted">(Uncheck to hide from schedule but keep history)</small>
            </label>

            <div class="d-flex justify-between mt-3">
                <a href="users.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-success">Save User</button>
            </div>
        </form>
    </div>
</div>

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
        <input type="number" name="tier_min[]" placeholder="From" min="0" style="width: 80px;">
        <span> - </span>
        <input type="number" name="tier_max[]" placeholder="To (empty for 51+)" min="0" style="width: 120px;">
        <span> : $</span>
        <input type="number" step="0.01" name="tier_rate[]" placeholder="Rate" min="0" style="width: 100px;">
        <button type="button" class="btn-icon" onclick="removeTier(this)" title="Remove tier">
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
