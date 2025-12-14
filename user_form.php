<?php
// user_form.php
require 'db.php';

$stmt_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = $stmt_locations->fetchAll(PDO::FETCH_ASSOC);

$user = [
    'id' => '',
    'name' => '',
    'email' => '',
    'location' => '',
    'rate_head_coach' => '0.00',
    'rate_helper' => '0.00',
    'coach_type' => 'bjj',
    'role' => 'user',
    'color_code' => '#3788d8'
];

$is_edit = false;

// If ID is passed, we are editing
if (isset($_GET['id'])) {
    $is_edit = true;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) die("User not found.");
}
?>

<!DOCTYPE html>
<html>

<head>
    <title><?= $is_edit ? 'Edit' : 'Add' ?> Coach</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            max-width: 600px;
            margin: auto;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }

        button {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            cursor: pointer;
        }

        a {
            color: #666;
            margin-left: 10px;
            text-decoration: none;
        }
    </style>
</head>

<body>

    <a href="/users.php">Go Back</a>
    <h2><?= $is_edit ? 'Edit' : 'Add New' ?> Coach</h2>

    <form action="user_save.php" method="POST">
        <input type="hidden" name="id" value="<?= $user['id'] ?>">

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>

        <div class="form-group">
            <label>Password <?= $is_edit ? '<small>(Leave blank to keep current)</small>' : '' ?></label>
            <input type="password" name="password" <?= $is_edit ? '' : 'required' ?>>
        </div>

        <div style="display: flex; gap: 10px;">
            <div class="form-group" style="flex:1;">
                <label for="location">Location:</label>
                <select name="location" id="location" class="form-control" required>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>"
                            <?php if (isset($user['location']) && $user['location'] === $loc) echo 'selected'; ?>>
                            <?php echo $loc['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:1;">
                <label>Display Color (for Calendar)</label>
                <input type="color" name="color_code" value="<?= $user['color_code'] ?>" style="height:40px;">
            </div>

        </div>

        <div style="display: flex; gap: 10px;">
            <div class="form-group" style="flex:1;">
                <label>Head Coach Rate ($)</label>
                <input type="number" step="0.01" name="rate_head_coach" value="<?= $user['rate_head_coach'] ?>">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Helper Rate ($)</label>
                <input type="number" step="0.01" name="rate_helper" value="<?= $user['rate_helper'] ?>">
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <div class="form-group" style="flex:1;">
                <label>Martial Art Specialty</label>
                <select name="coach_type">
                    <option value="bjj" <?= $user['coach_type'] == 'bjj' ? 'selected' : '' ?>>Jiu Jitsu</option>
                    <option value="mt" <?= $user['coach_type'] == 'mt' ? 'selected' : '' ?>>Muay Thai</option>
                    <option value="both" <?= $user['coach_type'] == 'both' ? 'selected' : '' ?>>Both</option>
                </select>
            </div>

            <div class="form-group" style="flex:1;">
                <label>System Role</label>
                <select name="role">
                    <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>Regular Coach</option>
                    <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </div>

        <button type="submit">Save Coach</button>
        <a href="users.php">Cancel</a>
    </form>

</body>

</html>