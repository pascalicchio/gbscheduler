<?php
// user_form.php - EDIT COACH / MANAGER (Fixed Saving Logic)
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

$message = "";

// --- 1. HANDLE FORM SUBMISSION (SAVE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $coach_type = $_POST['coach_type'];
    $color = $_POST['color_code'];
    $rate_head = $_POST['rate_head_coach'] ?? 0;
    $rate_helper = $_POST['rate_helper'] ?? 0;
    $location_ids = $_POST['locations'] ?? [];
    $rates = $_POST['rates'] ?? []; // Private rates array

    // FIX: Capture the checkbox. If unchecked, $_POST['is_active'] is missing, so we default to 0.
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id) {
        // UPDATE EXISTING USER
        $sql = "UPDATE users SET name=?, email=?, role=?, coach_type=?, color_code=?, rate_head_coach=?, rate_helper=?, is_active=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $role, $coach_type, $color, $rate_head, $rate_helper, $is_active, $id]);

        if (!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$pass, $id]);
        }
    } else {
        // CREATE NEW USER
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, email, password, role, coach_type, color_code, rate_head_coach, rate_helper, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email, $pass, $role, $coach_type, $color, $rate_head, $rate_helper, $is_active]);
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
        // Only save if a rate is entered
        if ($data['amount'] != '' || $data['percent'] != '') {
            $rate_val = $data['amount'] ?: 0;
            $disc_val = $data['percent'] ?: 0;
            $stmt_rate->execute([$id, $lid, $rate_val, $disc_val]);
        }
    }

    header("Location: users.php");
    exit();
}

// --- 2. FETCH DATA FOR VIEW ---
$stmt_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = $stmt_locations->fetchAll(PDO::FETCH_ASSOC);

$user = [
    'id' => '',
    'name' => '',
    'email' => '',
    'rate_head_coach' => '0.00',
    'rate_helper' => '0.00',
    'coach_type' => 'bjj',
    'role' => 'user',
    'color_code' => '#3788d8',
    'is_active' => 1 // Default to active for new users
];
$assigned_locations = [];
$private_rates = [];

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

    // Fetch Assigned Locations
    $stmt_loc = $pdo->prepare("SELECT location_id FROM user_locations WHERE user_id = ?");
    $stmt_loc->execute([$user['id']]);
    $assigned_locations = $stmt_loc->fetchAll(PDO::FETCH_COLUMN);

    // Fetch Private Rates
    $stmt_rates = $pdo->prepare("SELECT location_id, rate, discount_percent FROM private_rates WHERE user_id = ?");
    $stmt_rates->execute([$user['id']]);
    $rows = $stmt_rates->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $private_rates[$r['location_id']] = $r;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title><?= $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #007bff;
            --bg: #f4f6f9;
            --card: #ffffff;
        }

        body {
            font-family: sans-serif;
            background: var(--bg);
            padding: 40px;
            display: flex;
            justify-content: center;
        }

        .form-card {
            background: var(--card);
            width: 100%;
            max-width: 700px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .card-header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .card-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .row {
            display: flex;
            gap: 20px;
        }

        .col {
            flex: 1;
        }

        button {
            padding: 12px 25px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel {
            padding: 12px 20px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #555;
            border-radius: 4px;
        }

        /* Checkbox Grid */
        .loc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            border: 1px solid #eee;
            padding: 15px;
            border-radius: 4px;
        }

        .loc-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Private Rate Box */
        .rate-box {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 4px;
            margin-bottom: 10px;
            display: none;
        }

        .rate-box.active {
            display: block;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="form-card">
        <div class="card-header"><i class="fas fa-user-cog"></i> <?= $title ?></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="id" value="<?= $user['id'] ?>">

                <div class="row">
                    <div class="col">
                        <label>Full Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                    <div class="col">
                        <label>Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                </div>

                <div class="form-group" style="margin-top:15px;">
                    <label>System Role</label>
                    <select name="role">
                        <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>Coach (Standard)</option>
                        <option value="manager" <?= $user['role'] == 'manager' ? 'selected' : '' ?>>Manager (No Classes)</option>
                        <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                    <small style="color:#777">Managers can input private classes but do not appear on the schedule.</small>
                </div>

                <div class="form-group">
                    <label>Assigned Locations</label>
                    <div class="loc-grid">
                        <?php foreach ($locations as $l):
                            $checked = in_array($l['id'], $assigned_locations) ? 'checked' : '';
                        ?>
                            <div class="loc-item">
                                <input type="checkbox" name="locations[]" value="<?= $l['id'] ?>" id="loc_<?= $l['id'] ?>" <?= $checked ?> onchange="toggleRateBox(<?= $l['id'] ?>)">
                                <label for="loc_<?= $l['id'] ?>" style="margin:0; font-weight:normal; cursor:pointer;"><?= $l['name'] ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <h4 style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;">Private Class Rates & Fees</h4>
                <?php foreach ($locations as $l):
                    $r = $private_rates[$l['id']] ?? ['rate' => '0.00', 'discount_percent' => '0.00'];
                    $isActive = in_array($l['id'], $assigned_locations) ? 'active' : '';
                ?>
                    <div class="rate-box <?= $isActive ?>" id="rate_box_<?= $l['id'] ?>">
                        <strong><?= $l['name'] ?> Settings</strong>
                        <div class="row" style="margin-top:5px;">
                            <div class="col">
                                <label style="font-size:0.8em">Private Pay Rate ($)</label>
                                <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][amount]" value="<?= $r['rate'] ?>">
                            </div>
                            <div class="col">
                                <label style="font-size:0.8em">Fee / Discount (%)</label>
                                <input type="number" step="0.01" name="rates[<?= $l['id'] ?>][percent]" value="<?= $r['discount_percent'] ?>" placeholder="e.g. 4.00">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="row" style="margin-top:20px;">
                    <div class="col">
                        <label>Standard Class Head Rate ($)</label>
                        <input type="number" step="0.01" name="rate_head_coach" value="<?= $user['rate_head_coach'] ?>">
                    </div>
                    <div class="col">
                        <label>Standard Class Helper Rate ($)</label>
                        <input type="number" step="0.01" name="rate_helper" value="<?= $user['rate_helper'] ?>">
                    </div>
                </div>

                <div class="row" style="margin-top:20px;">
                    <div class="col">
                        <label>Martial Art</label>
                        <select name="coach_type">
                            <option value="bjj" <?= $user['coach_type'] == 'bjj' ? 'selected' : '' ?>>Jiu Jitsu</option>
                            <option value="mt" <?= $user['coach_type'] == 'mt' ? 'selected' : '' ?>>Muay Thai</option>
                            <option value="both" <?= $user['coach_type'] == 'both' ? 'selected' : '' ?>>Both</option>
                        </select>
                    </div>

                    <div class="col">
                        <label>Color</label>
                        <input type="color" name="color_code" value="<?= $user['color_code'] ?>" style="height:42px; padding:2px;">
                    </div>
                </div>

                <div class="form-group" style="margin-top:20px">
                    <label>Password <?= $is_edit ? '<small>(Leave blank to keep)</small>' : '' ?></label>
                    <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
                </div>

                <label style="display:flex; align-items:center; gap:10px; margin-top:15px; background:#f8f9fa; padding:10px; border-radius:4px; border:1px solid #ddd;">
                    <input type="checkbox" name="is_active" value="1" <?= (!isset($user) || $user['is_active']) ? 'checked' : '' ?> style="width:auto; margin:0;">
                    <span style="font-weight:bold; color:#2c3e50;">Active Coach?</span>
                    <small style="color:#666; font-weight:normal;">(Uncheck to hide from schedule but keep history)</small>
                </label>

                <div style="margin-top:30px; display:flex; justify-content:space-between;">
                    <a href="users.php" class="btn-cancel">Cancel</a>
                    <button type="submit">Save User</button>
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
    </script>
</body>

</html>