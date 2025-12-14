<?php

session_start();
require 'db.php'; // Contains the db connection
// NOTE: Make sure the require_admin() function is available, 
// or put the logic directly here:
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dashboard.php"); // or index.php
    exit();
}

$stmt_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC");
$locations = $stmt_locations->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>

<head>
    <title>Manage Coaches</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
        }

        .btn {
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 4px;
            color: white;
        }

        .btn-add {
            background-color: #28a745;
            margin-bottom: 20px;
            display: inline-block;
        }

        .btn-edit {
            background-color: #007bff;
        }

        .btn-delete {
            background-color: #dc3545;
        }

        .color-box {
            width: 20px;
            height: 20px;
            display: inline-block;
            border: 1px solid #000;
            vertical-align: middle;
        }
    </style>
</head>

<body>

    <a href="/dashboard">Go Back Home</a>
    <h2>Coaches & Staff</h2>

    <a href="user_form.php" class="btn btn-add">+ Add New Coach</a>

    <?php foreach ($locations as $loc): ?>

        <h3>Location: <?= htmlspecialchars($loc['name']) ?></h3>

        <?php
            // 1. Fetch all users
            $stmt = $pdo->query("SELECT * FROM users WHERE location = " . $loc['id'] . " ORDER BY name ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <table style="margin-bottom:60px">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Color</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Specialty</th>
                    <th>Head Rate</th>
                    <th>Helper Rate</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id'] ?></td>
                        <td>
                            <span class="color-box" style="background-color: <?= $user['color_code'] ?>;"></span>
                        </td>
                        <td><?= htmlspecialchars($user['name']) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= ucfirst($user['role']) ?></td>
                        <td>
                            <?php
                            if ($user['coach_type'] == 'bjj') echo 'Jiu Jitsu';
                            elseif ($user['coach_type'] == 'mt') echo 'Muay Thai';
                            else echo 'Both';
                            ?>
                        </td>
                        <td>$<?= number_format($user['rate_head_coach'], 2) ?></td>
                        <td>$<?= number_format($user['rate_helper'], 2) ?></td>
                        <td>
                            <a href="user_form.php?id=<?= $user['id'] ?>" class="btn btn-edit">Edit</a>
                            <a href="user_delete.php?id=<?= $user['id'] ?>" class="btn btn-delete" onclick="return confirm('Are you sure?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endforeach; ?>

</body>

</html>