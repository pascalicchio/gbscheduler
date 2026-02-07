<?php
// users.php - MANAGE USERS
require_once 'includes/config.php';

// Require admin or manager access
requireAuth(['admin', 'manager']);

// DELETE ACTION (admin only)
if (isset($_GET['delete']) && isAdmin()) {
    $uid = $_GET['delete'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM event_assignments WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM private_classes WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM user_locations WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM private_rates WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        setFlash("Could not delete user: " . $e->getMessage(), 'error');
    }
    header("Location: users.php");
    exit();
}

// Fetch All Locations
$locations = $pdo->query("SELECT * FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Management & Employees
$management = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'manager', 'employee') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Page setup
$pageTitle = 'Manage Users | GB Scheduler';
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
        --bg-card: white;
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
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        gap: 16px;
        flex-wrap: wrap;
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

    .page-header-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }
[x-cloak] { display: none !important; }

    /* Add User Button */
    .btn-add {
        padding: 10px 18px;
        background-image: var(--gradient-primary);
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.25);
    }

    .btn-add:hover {
        background-image: var(--gradient-primary-hover);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 201, 255, 0.35);
    }

    /* Section Titles */
    .section-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-dark);
        margin: 30px 0 15px 0;
        padding-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title i {
        background-image: var(--gradient-primary);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    /* Cards */
    .card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--shadow-md);
        overflow-x: auto;
        border: 1px solid var(--border-light);
        margin-bottom: 20px;
    }

    /* Tables */
    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 700px;
    }

    th {
        background: linear-gradient(to bottom, #fafbfc, #f5f7fa);
        padding: 12px 14px;
        text-align: left;
        font-weight: 700;
        color: var(--text-dark);
        font-size: 0.8em;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 2px solid var(--border-light);
        white-space: nowrap;
    }

    td {
        padding: 12px 14px;
        border-bottom: 1px solid #f0f1f3;
        color: #555;
        font-size: 0.88em;
        vertical-align: middle;
    }

    tr:hover td {
        background: rgba(0, 201, 255, 0.03);
    }

    /* Column styles */
    .col-name {
        font-weight: 600;
        color: var(--text-dark);
        white-space: nowrap;
    }

    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .role-admin { background: #343a40; color: white; }
    .role-manager { background: #17a2b8; color: white; }
    .role-employee { background: #6f42c1; color: white; }
    .type-bjj { background: #e3f2fd; color: #0d47a1; }
    .type-mt { background: #ffebee; color: #b71c1c; }
    .type-both { background: #f3e5f5; color: #4a148c; }

    .pay-method {
        font-size: 0.75em;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 700;
        background: #e8f5e9;
        color: #2e7d32;
    }

    .pay-info {
        font-size: 0.75em;
        color: #888;
        margin-top: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 150px;
    }

    .color-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 8px;
        vertical-align: middle;
    }

    .col-act {
        text-align: right;
        white-space: nowrap;
    }

    .col-act a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 6px;
        color: #6c757d;
        background: #f8f9fa;
        border: 1px solid #e8ecf2;
        text-decoration: none;
        transition: all 0.2s ease;
        font-size: 0.85rem;
        margin-left: 4px;
    }

    .col-act a:hover {
        background: rgba(0, 201, 255, 0.1);
        border-color: rgb(0, 201, 255);
        color: rgb(0, 201, 255);
    }

    .col-act a.delete-btn:hover {
        background: rgba(220, 53, 69, 0.1);
        border-color: #dc3545;
        color: #dc3545;
    }

    /* Inactive rows */
    .inactive-row {
        opacity: 0.6;
        background: #fafafa;
    }

    .inactive-badge {
        background: #6c757d;
        color: white;
        font-size: 0.7em;
        padding: 3px 6px;
        border-radius: 4px;
        margin-left: 8px;
        font-weight: 700;
    }

    /* Delete Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        backdrop-filter: blur(4px);
    }

    .modal-box {
        background: white;
        border-radius: 16px;
        padding: 28px;
        max-width: 420px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(0, 201, 255, 0.2);
    }

    .modal-box h3 {
        margin: 0 0 16px 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-box h3 i {
        color: #dc3545;
    }

    .modal-box p {
        margin: 0 0 24px 0;
        color: #6c757d;
        line-height: 1.5;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .modal-btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .modal-btn-cancel {
        background: #f8f9fa;
        color: #6c757d;
        border: 2px solid #e8ecf2;
    }

    .modal-btn-cancel:hover {
        background: white;
        border-color: #6c757d;
        color: #495057;
    }

    .modal-btn-delete {
        background: #dc3545;
        color: white;
    }

    .modal-btn-delete:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: row;
            align-items: center;
            flex-wrap: nowrap;
        }

        .page-header h2 {
            font-size: 1.1rem;
            white-space: nowrap;
        }

        .page-header-right {
            flex-shrink: 0;
            gap: 8px;
        }

        .btn-add {
            padding: 8px 12px;
            font-size: 0.75rem;
        }

        .btn-add span {
            display: none;
        }

        /* Collapsible sections */
        .section-title {
            cursor: pointer;
            user-select: none;
            margin: 20px 0 10px 0;
        }

        .section-title .toggle-icon {
            margin-left: auto;
            transition: transform 0.2s ease;
            font-size: 0.8rem;
            color: #a0aec0;
        }

        .section-title .toggle-icon.collapsed {
            transform: rotate(-90deg);
        }

        /* Card layout for tables */
        .card {
            overflow: visible;
            border: none;
            box-shadow: none;
            background: transparent;
        }

        table {
            min-width: 0;
        }

        thead {
            display: none;
        }

        tbody {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        tr {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 12px;
            background: white;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 14px;
            box-shadow: var(--shadow-sm);
        }

        td {
            border: none;
            padding: 2px 0;
            font-size: 0.8rem;
        }

        td::before {
            content: attr(data-label);
            display: block;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #a0aec0;
            margin-bottom: 2px;
        }

        /* Name spans full width */
        td.col-name {
            grid-column: 1 / -1;
            border-bottom: 1px solid #f0f1f3;
            padding-bottom: 8px;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }

        /* Actions span full width */
        td.col-act {
            grid-column: 1 / -1;
            text-align: left;
            border-top: 1px solid #f0f1f3;
            padding-top: 8px;
            margin-top: 4px;
            display: flex;
            gap: 8px;
        }

        td.col-act a {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            border-radius: 8px;
        }

        /* Hide email and payment info on mobile */
        .col-hide-mobile {
            display: none;
        }
    }
CSS;

require_once 'includes/header.php';
?>

<div x-data="{ showDeleteModal: false, deleteUserId: null, deleteUserName: '' }">

<div class="page-header">
    <h2><i class="fas fa-users"></i> Manage Users</h2>
    <div class="page-header-right">
        <?php if (isAdmin()): ?>
            <a href="user_form.php" class="btn-add"><i class="fas fa-plus"></i> <span>Add New User</span></a>
        <?php endif; ?>
        <?php include 'includes/nav-menu.php'; ?>
    </div>
</div>

<div class="section-title" onclick="toggleSection(this)"><i class="fas fa-user-shield"></i> Management & Admin <i class="fas fa-chevron-down toggle-icon"></i></div>
<div class="card">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Email</th>
                <th>Rates (H/A)</th>
                <th>Frequency</th>
                <th>Payment</th>
                <th class="col-act">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($management as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'inactive-row' ?>">
                    <td class="col-name" data-label="Name">
                        <?= e($u['name']) ?>
                        <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                    </td>
                    <td data-label="Role"><span class="badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td data-label="Email" class="col-hide-mobile"><?= e($u['email']) ?></td>
                    <td data-label="Rates">$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></td>
                    <td data-label="Frequency"><?= ucfirst($u['payment_frequency'] ?? 'weekly') ?></td>
                    <td data-label="Payment" class="col-hide-mobile">
                        <span class="pay-method"><?= strtoupper($u['payment_method'] ?? 'ADP') ?></span>
                        <?php if (!empty($u['payment_info'])): ?><div class="pay-info" title="<?= e($u['payment_info']) ?>"><?= e($u['payment_info']) ?></div><?php endif; ?>
                    </td>
                    <td class="col-act">
                        <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed" title="View Report"><i class="fas fa-file-invoice-dollar"></i></a>
                        <?php if (isAdmin()): ?>
                            <a href="user_form.php?id=<?= $u['id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="#" @click.prevent="showDeleteModal = true; deleteUserId = <?= $u['id'] ?>; deleteUserName = '<?= e($u['name']) ?>'" class="delete-btn" title="Delete"><i class="fas fa-trash"></i></a>
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
    <div class="section-title" onclick="toggleSection(this)"><i class="fas fa-map-marker-alt"></i> <?= e($loc['name']) ?> <i class="fas fa-chevron-down toggle-icon"></i></div>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Rates (H/A)</th>
                    <th>Frequency</th>
                    <th>Payment</th>
                    <th class="col-act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loc_users as $u): ?>
                    <tr class="<?= $u['is_active'] ? '' : 'inactive-row' ?>">
                        <td class="col-name" data-label="Name">
                            <span class="color-dot" style="background:<?= e($u['color_code']) ?>"></span>
                            <?= e($u['name']) ?>
                            <?php if (!$u['is_active']): ?><span class="inactive-badge">INACTIVE</span><?php endif; ?>
                        </td>
                        <td data-label="Type">
                            <?php foreach (explode(',', $u['coach_type']) as $type): $type = trim($type); ?>
                                <span class="badge type-<?= $type ?>"><?= strtoupper($type) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Email" class="col-hide-mobile"><?= e($u['email']) ?></td>
                        <td data-label="Rates">$<?= number_format($u['rate_head_coach'], 0) ?> / $<?= number_format($u['rate_helper'], 0) ?></td>
                        <td data-label="Frequency"><?= ucfirst($u['payment_frequency'] ?? 'weekly') ?></td>
                        <td data-label="Payment" class="col-hide-mobile">
                            <span class="pay-method"><?= strtoupper($u['payment_method'] ?? 'ADP') ?></span>
                            <?php if (!empty($u['payment_info'])): ?><div class="pay-info" title="<?= e($u['payment_info']) ?>"><?= e($u['payment_info']) ?></div><?php endif; ?>
                        </td>
                        <td class="col-act">
                            <a href="location_reports.php?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-t') ?>&coach_id=<?= $u['id'] ?>&view=detailed" title="View Report"><i class="fas fa-file-invoice-dollar"></i></a>
                            <?php if (isAdmin()): ?>
                                <a href="user_form.php?id=<?= $u['id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="#" @click.prevent="showDeleteModal = true; deleteUserId = <?= $u['id'] ?>; deleteUserName = '<?= e($u['name']) ?>'" class="delete-btn" title="Delete"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<!-- Delete Confirmation Modal -->
<div x-show="showDeleteModal" 
     x-cloak 
     class="modal-overlay" 
     @click.self="showDeleteModal = false">
    <div class="modal-box" @click.stop>
        <h3><i class="fas fa-exclamation-triangle"></i> Delete User</h3>
        <p>Are you sure you want to delete <strong x-text="deleteUserName"></strong>? This action cannot be undone.</p>
        <div class="modal-actions">
            <button @click="showDeleteModal = false" class="modal-btn modal-btn-cancel">Cancel</button>
            <button @click="window.location.href = 'users.php?delete=' + deleteUserId" class="modal-btn modal-btn-delete">Delete User</button>
        </div>
    </div>
</div>

</div>

<script>
function toggleSection(el) {
    if (window.innerWidth > 768) return;
    const card = el.nextElementSibling;
    const icon = el.querySelector('.toggle-icon');
    if (card.style.display === 'none') {
        card.style.display = '';
        icon.classList.remove('collapsed');
    } else {
        card.style.display = 'none';
        icon.classList.add('collapsed');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
