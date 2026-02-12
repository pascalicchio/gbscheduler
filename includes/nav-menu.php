<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<div class="nav-menu" x-data="{ open: false }" @mouseenter="if(window.innerWidth >= 768) open = true" @mouseleave="if(window.innerWidth >= 768) open = false">
    <button @click="if(window.innerWidth < 768) open = !open" class="nav-menu-btn">
        <i class="fas fa-bars"></i>
        <span>Menu</span>
    </button>
    <div x-show="open" @click.away="if(window.innerWidth < 768) open = false" @mouseenter="open = true" x-cloak class="nav-dropdown">
        <a href="dashboard.php" <?= $currentPage === 'dashboard.php' ? 'class="active"' : '' ?>><i class="fas fa-calendar-alt"></i> Dashboard</a>
        <a href="reports.php" <?= $currentPage === 'reports.php' ? 'class="active"' : '' ?>><i class="fas fa-chart-line"></i> Individual Report</a>
        <?php if (canManage()): ?>
            <a href="private_classes.php" <?= $currentPage === 'private_classes.php' ? 'class="active"' : '' ?>><i class="fas fa-money-bill-wave"></i> Private Classes</a>
            <a href="location_reports.php" <?= $currentPage === 'location_reports.php' ? 'class="active"' : '' ?>><i class="fas fa-file-invoice-dollar"></i> Payroll Reports</a>
            <a href="coach_payments.php" <?= $currentPage === 'coach_payments.php' ? 'class="active"' : '' ?>><i class="fas fa-money-check-alt"></i> Coach Payments</a>
            <a href="classes.php" <?= $currentPage === 'classes.php' ? 'class="active"' : '' ?>><i class="fas fa-graduation-cap"></i> Class Templates</a>
            <a href="users.php" <?= in_array($currentPage, ['users.php', 'user_form.php']) ? 'class="active"' : '' ?>><i class="fas fa-users"></i> Users</a>
            <a href="inventory.php" <?= $currentPage === 'inventory.php' ? 'class="active"' : '' ?>><i class="fas fa-boxes"></i> Inventory</a>
            <a href="upload_zenplanner.php" <?= $currentPage === 'upload_zenplanner.php' ? 'class="active"' : '' ?>><i class="fas fa-upload"></i> Upload Data</a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
            <a href="school_dashboard.php" <?= $currentPage === 'school_dashboard.php' ? 'class="active"' : '' ?>><i class="fas fa-chart-line"></i> School Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>
