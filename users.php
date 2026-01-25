<?php
// users.php - MANAGE USERS (With Deactivation)
session_start();
require 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header("Location: dashboard.php");
    exit();
}

$is_admin = ($_SESSION['user_role'] === 'admin');

// DELETE ACTION
if (isset($_GET['delete']) && $is_admin) {
    $pdo->prepare("DELETE FROM user_locations WHERE user_id = ?")->execute([$_GET['delete']]);
    $pdo->prepare("DELETE FROM private_rates WHERE user_id = ?")->execute([$_GET['delete']]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_GET['delete']]);
    header("Location: users.php");
    exit();
}

// 1. Fetch All Locations
$locations = $pdo->query("SELECT * FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Management
$management = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'manager') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Manage Users</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #007bff;
            --bg: #f4f6f9;
            --text: #333;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg);
            padding: 40px;
            color: var(--text);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn {
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            color: white;
            background: #28a745;
        }

        .btn-back {
            background: #6c757d;
        }

        .section-title {
            font-size: 1.2em;
            color: #2c3e50;
            margin: 30px 0 15px 0;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        th {
            background: #f8f9fa;
            text-transform: uppercase;
            font-size: 0.85em;
            color: #666;
            font-weight: 700;
        }

        .col-name {
            width: 25%;
        }

        .col-meta {
            width: 15%;
        }

        .col-email {
            width: 25%;
        }

        .col-rate {
            width: 15%;
        }

        .col-act {
            width: 20%;
            text-align: right;
        }

        .color-dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .role-admin {
            background: #343a40;
            color: white;
        }

        .role-manager {
            background: #17a2b8;
            color: white;
        }

        .type-bjj {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .type-mt {
            background: #ffebee;
            color: #b71c1c;
        }

        .type-both {
            background: #f3e5f5;
            color: #4a148c;
        }

        .actions a {
            display: inline-block;
            width: 32px;
            height: 32px;
            line-height: 32px;
            text-align: center;
            border-radius: 4px;
            margin-left: 5px;
            color: #555;
            background: #f8f9fa;
            border: 1px solid #ddd;
        }

        .inactive-row {
            opacity: 0.5;
            background: #f9f9f9;
        }

        .inactive-badge {
            background: #6c757d;
            color: white;
            font-size: 0.7em;
            padding: 2px 5px;
            border-radius: 4px;
            margin-left: 5px;
        }
    </style>
</head>

<body>

    <div class="header">
        <h2 style="margin:0;"><i class="fas fa-users"></i> Manage Users</h2>
        <div>
            <a href="dashboard.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <?php if ($is_admin): ?>
                <a href="user_form.php" class="btn"><i class="fas fa-plus"></i> Add New User</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="section-title"><i class="fas fa-user-shield"></i> Management & Admin</div>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th class="col-name">Name</th>
                    <th class="col-meta">Role</th>
                    <th class="col-email">Email</th>
                    <th class="col-rate">Rates (H/A)</th>
                    <th class="col-act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($management as $u): ?>
                    <tr class="<?= $u['is_active'] ? '' : 'inactive-row' ?>">
                        <td style="font-weight:bold; color:#2c3e50;">
                            <?= htmlspecialchars($u['name']) ?>
                            <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                        </td>
                        <td><span class="badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="rates-text">$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></span></td>
                        <td class="actions col-act">
                            <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed"><i class="fas fa-file-invoice-dollar"></i></a>
                            <?php if ($is_admin): ?>
                                <a href="user_form.php?id=<?= $u['id'] ?>"><i class="fas fa-edit"></i></a>
                                <a href="users.php?delete=<?= $u['id'] ?>" onclick="return confirm('Delete?');"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($locations as $loc):
        $sql = "SELECT u.* FROM users u JOIN user_locations ul ON u.id = ul.user_id WHERE ul.location_id = ? AND u.role = 'user' ORDER BY u.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$loc['id']]);
        $loc_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($loc_users)) continue;
    ?>
        <div class="section-title"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($loc['name']) ?></div>
        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th class="col-name">Name</th>
                        <th class="col-meta">Type</th>
                        <th class="col-email">Email</th>
                        <th class="col-rate">Rates (H/A)</th>
                        <th class="col-act">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loc_users as $u): ?>
                        <tr class="<?= $u['is_active'] ? '' : 'inactive-row' ?>">
                            <td style="font-weight:bold; color:#2c3e50;">
                                <span class="color-dot" style="background:<?= $u['color_code'] ?>"></span>
                                <?= htmlspecialchars($u['name']) ?>
                                <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                            </td>
                            <td><span class="badge type-<?= $u['coach_type'] ?>"><?= strtoupper($u['coach_type']) ?></span></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="rates-text">$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></span></td>
                            <td class="actions col-act">
                                <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed"><i class="fas fa-file-invoice-dollar"></i></a>
                                <?php if ($is_admin): ?>
                                    <a href="user_form.php?id=<?= $u['id'] ?>"><i class="fas fa-edit"></i></a>
                                    <a href="users.php?delete=<?= $u['id'] ?>" onclick="return confirm('Delete?');"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

</body>

</html>