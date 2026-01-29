<?php
// users.php - MANAGE USERS
require_once 'includes/config.php';

// Require admin or manager access
requireAuth(['admin', 'manager']);

// DELETE ACTION (admin only)
if (isset($_GET['delete']) && isAdmin()) {
    $pdo->prepare("DELETE FROM user_locations WHERE user_id = ?")->execute([$_GET['delete']]);
    $pdo->prepare("DELETE FROM private_rates WHERE user_id = ?")->execute([$_GET['delete']]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$_GET['delete']]);
    header("Location: users.php");
    exit();
}

// Fetch All Locations
$locations = $pdo->query("SELECT * FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Management
$management = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'manager') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Page setup
$pageTitle = 'Manage Users | GB Scheduler';
$extraCss = <<<CSS
    body { padding: 40px; }

    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }

    .section-title {
        font-size: 1.2em;
        color: var(--text-dark);
        margin: 30px 0 15px 0;
        border-bottom: 2px solid #ddd;
        padding-bottom: 5px;
    }

    .col-name { width: 25%; }
    .col-meta { width: 15%; }
    .col-email { width: 25%; }
    .col-rate { width: 15%; }
    .col-act { width: 20%; text-align: right; }

    .color-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 8px;
    }

    .role-admin { background: #343a40; color: white; }
    .role-manager { background: #17a2b8; color: white; }
    .type-bjj { background: #e3f2fd; color: #0d47a1; }
    .type-mt { background: #ffebee; color: #b71c1c; }
    .type-both { background: #f3e5f5; color: #4a148c; }

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

    .inactive-row { opacity: 0.5; background: #f9f9f9; }
    .inactive-badge {
        background: #6c757d;
        color: white;
        font-size: 0.7em;
        padding: 2px 5px;
        border-radius: 4px;
        margin-left: 5px;
    }
CSS;

require_once 'includes/header.php';
?>

<div class="header">
    <h2 style="margin:0;"><i class="fas fa-users"></i> Manage Users</h2>
    <div>
        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Dashboard</a>
        <?php if (isAdmin()): ?>
            <a href="user_form.php" class="btn btn-success"><i class="fas fa-plus"></i> Add New User</a>
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
                    <td class="font-bold" style="color:var(--text-dark);">
                        <?= e($u['name']) ?>
                        <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                    </td>
                    <td><span class="badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td><?= e($u['email']) ?></td>
                    <td>$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></td>
                    <td class="actions col-act">
                        <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed"><i class="fas fa-file-invoice-dollar"></i></a>
                        <?php if (isAdmin()): ?>
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
    <div class="section-title"><i class="fas fa-map-marker-alt"></i> <?= e($loc['name']) ?></div>
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
                        <td class="font-bold" style="color:var(--text-dark);">
                            <span class="color-dot" style="background:<?= $u['color_code'] ?>"></span>
                            <?= e($u['name']) ?>
                            <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                        </td>
                        <td><span class="badge type-<?= $u['coach_type'] ?>"><?= strtoupper($u['coach_type']) ?></span></td>
                        <td><?= e($u['email']) ?></td>
                        <td>$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></td>
                        <td class="actions col-act">
                            <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed"><i class="fas fa-file-invoice-dollar"></i></a>
                            <?php if (isAdmin()): ?>
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

<?php require_once 'includes/footer.php'; ?>
