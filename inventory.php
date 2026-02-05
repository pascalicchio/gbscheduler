<?php
// inventory.php - INVENTORY MANAGEMENT
require_once 'includes/config.php';

// Require admin or manager access
requireAuth(['admin', 'manager']);

$msg = getFlash();
$is_admin = isAdmin();

// Fetch locations
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories
$categories = $pdo->query("SELECT id, name FROM product_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending order counts by location
$pending_counts = [];
$stmt = $pdo->query("SELECT location_id, COUNT(*) as cnt FROM order_requests WHERE status = 'pending' AND is_active = 1 GROUP BY location_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pending_counts[$row['location_id']] = (int)$row['cnt'];
}
$total_pending = array_sum($pending_counts);

// Current filters
$filter_location = $_GET['location_id'] ?? ($locations[0]['id'] ?? '');
$filter_category = $_GET['category_id'] ?? '';
$filter_date = $_GET['count_date'] ?? date('Y-m-d');
$active_tab = $_GET['tab'] ?? 'counts';

// Page setup
$pageTitle = 'Inventory Management | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 20px; }

    .tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .tab-btn {
        position: relative;
        padding: 12px 20px;
        border: 2px solid #e9ecef;
        background: white;
        cursor: pointer;
        font-weight: 600;
        color: #666;
        transition: all 0.2s;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .tab-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .tab-btn.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .tab-btn .pending-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #dc3545;
        color: white;
        font-size: 0.75em;
        font-weight: bold;
        min-width: 20px;
        height: 20px;
        line-height: 20px;
        text-align: center;
        border-radius: 10px;
        padding: 0 6px;
        box-shadow: 0 2px 4px rgba(220,53,69,0.4);
    }

    .tab-btn .pending-badge:empty {
        display: none;
    }

    .tab-content {
        display: none;
        background: white;
        padding: 20px;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .tab-content.active {
        display: block;
    }

    .filter-bar {
        display: flex;
        gap: 10px;
        align-items: flex-end;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: var(--radius);
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .filter-group label {
        font-size: 0.75em;
        font-weight: 600;
        text-transform: uppercase;
        color: #666;
    }

    .filter-group select,
    .filter-group input {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        min-width: 150px;
    }

    /* Inventory Table */
    .inventory-table {
        width: 100%;
        border-collapse: collapse;
    }

    .inventory-table th {
        background: #2c3e50;
        color: white;
        padding: 12px;
        text-align: left;
        font-size: 0.85em;
        text-transform: uppercase;
    }

    .inventory-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #eee;
        vertical-align: middle;
    }

    .inventory-table tbody tr:hover {
        background: #f8f9fa;
    }

    .category-header {
        background: #e9ecef !important;
        font-weight: bold;
        color: #2c3e50;
    }

    .category-header td {
        padding: 8px 12px;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Stock Level Colors */
    .stock-critical {
        background-color: #f8d7da !important;
    }

    .stock-low {
        background-color: #fff3cd !important;
    }

    .stock-input {
        width: 70px;
        padding: 6px 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        text-align: center;
        font-size: 14px;
    }

    .stock-input:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.15);
    }

    .trend-indicator {
        font-size: 0.85em;
        padding: 2px 6px;
        border-radius: 4px;
        font-weight: 600;
    }

    .trend-down {
        color: #dc3545;
        background: #f8d7da;
    }

    .trend-up {
        color: #28a745;
        background: #d4edda;
    }

    .trend-same {
        color: #6c757d;
        background: #e9ecef;
    }

    /* Two Column Layout */
    .two-col-layout {
        display: flex;
        gap: 20px;
    }

    .form-panel {
        flex: 0 0 350px;
        background: #f8f9fa;
        padding: 20px;
        border-radius: var(--radius);
    }

    .form-panel h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #2c3e50;
        padding-bottom: 10px;
    }

    .data-panel {
        flex: 1;
        overflow-x: auto;
    }

    /* Order Request Status Badges */
    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-pending { background: #fff3cd; color: #856404; }
    .status-ordered { background: #cce5ff; color: #004085; }
    .status-received { background: #d4edda; color: #155724; }
    .status-completed { background: #28a745; color: white; }
    .status-cancelled { background: #f8d7da; color: #721c24; }

    /* Save Button */
    .save-bar {
        position: sticky;
        bottom: 0;
        background: white;
        padding: 15px;
        border-top: 2px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 20px -20px -20px -20px;
    }

    .save-bar .btn-save {
        padding: 12px 30px;
        font-size: 1em;
    }

    .unsaved-indicator {
        color: #dc3545;
        font-weight: 600;
        display: none;
    }

    .unsaved-indicator.show {
        display: inline;
    }

    /* Product Management */
    .product-form {
        background: white;
        padding: 20px;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 20px;
    }

    .product-form .form-row {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
    }

    .product-form .form-group {
        flex: 1;
    }

    /* Best Sellers */
    .best-sellers-table {
        width: 100%;
    }

    .best-sellers-table th {
        background: #2c3e50;
        color: white;
        padding: 10px;
    }

    .rank-badge {
        display: inline-block;
        width: 28px;
        height: 28px;
        line-height: 28px;
        text-align: center;
        border-radius: 50%;
        font-weight: bold;
        font-size: 0.85em;
    }

    .rank-1 { background: #ffd700; color: #333; }
    .rank-2 { background: #c0c0c0; color: #333; }
    .rank-3 { background: #cd7f32; color: white; }
    .rank-default { background: #e9ecef; color: #666; }

    @media (max-width: 768px) {
        .tabs {
            gap: 8px;
        }

        .tab-btn {
            flex: 1 1 calc(50% - 8px);
            text-align: center;
            padding: 10px 12px;
            font-size: 0.85em;
        }

        .tab-btn i {
            display: block;
            margin-bottom: 4px;
            font-size: 1.2em;
        }

        .two-col-layout {
            flex-direction: column;
        }

        .form-panel {
            flex: none;
            order: -1;
        }

        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
        }
    }
CSS;

require_once 'includes/header.php';
?>

<div class="top-bar">
    <a href="dashboard.php" class="nav-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <h2 class="page-title"><i class="fas fa-boxes"></i> Inventory Management</h2>
</div>

<?= $msg ?>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn <?= $active_tab === 'counts' ? 'active' : '' ?>" data-tab="counts">
        <i class="fas fa-clipboard-list"></i> Weekly Counts
    </button>
    <button class="tab-btn <?= $active_tab === 'trends' ? 'active' : '' ?>" data-tab="trends">
        <i class="fas fa-chart-line"></i> Sales Trends
    </button>
    <button class="tab-btn <?= $active_tab === 'orders' ? 'active' : '' ?>" data-tab="orders">
        <i class="fas fa-shopping-cart"></i> Order Requests
        <span class="pending-badge" id="pending-badge"><?= $total_pending > 0 ? $total_pending : '' ?></span>
    </button>
    <?php if ($is_admin): ?>
    <button class="tab-btn <?= $active_tab === 'products' ? 'active' : '' ?>" data-tab="products">
        <i class="fas fa-cog"></i> Manage Products
    </button>
    <?php endif; ?>
</div>

<!-- Tab 1: Weekly Counts -->
<div class="tab-content <?= $active_tab === 'counts' ? 'active' : '' ?>" id="tab-counts">
    <div class="filter-bar">
        <div class="filter-group">
            <label>Location</label>
            <select id="filter-location">
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= $filter_location == $loc['id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Category</label>
            <select id="filter-category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filter_category == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Count Date</label>
            <input type="text" id="filter-date" value="<?= $filter_date ?>" readonly>
        </div>
        <button class="btn btn-primary" onclick="loadInventory()">
            <i class="fas fa-sync"></i> Load
        </button>
    </div>

    <div id="inventory-container">
        <table class="inventory-table">
            <thead>
                <tr>
                    <th style="width:40%">Product</th>
                    <th style="width:15%">Size / Color</th>
                    <th style="width:15%">Last Week</th>
                    <th style="width:15%">Current Count</th>
                    <th style="width:15%">Change</th>
                </tr>
            </thead>
            <tbody id="inventory-body">
                <tr><td colspan="5" style="text-align:center; padding:40px; color:#999;">Select a location and date, then click Load</td></tr>
            </tbody>
        </table>
    </div>

    <div class="save-bar">
        <span class="unsaved-indicator" id="unsaved-indicator">
            <i class="fas fa-exclamation-circle"></i> Unsaved changes
        </span>
        <button class="btn btn-success btn-save" onclick="saveInventory()">
            <i class="fas fa-save"></i> Save All Counts
        </button>
    </div>
</div>

<!-- Tab 2: Sales Trends -->
<div class="tab-content <?= $active_tab === 'trends' ? 'active' : '' ?>" id="tab-trends">
    <div class="filter-bar">
        <div class="filter-group">
            <label>Location</label>
            <select id="trends-location">
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= $filter_location == $loc['id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>From Date</label>
            <input type="text" id="trends-from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" readonly>
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="text" id="trends-to" value="<?= date('Y-m-d') ?>" readonly>
        </div>
        <button class="btn btn-primary" onclick="loadTrends()">
            <i class="fas fa-chart-line"></i> Analyze
        </button>
    </div>

    <h3><i class="fas fa-fire"></i> Best Sellers (Estimated from Inventory Depletion)</h3>
    <table class="inventory-table best-sellers-table">
        <thead>
            <tr>
                <th style="width:10%">Rank</th>
                <th style="width:50%">Product</th>
                <th style="width:20%">Size / Color</th>
                <th style="width:20%">Est. Sales</th>
            </tr>
        </thead>
        <tbody id="trends-body">
            <tr><td colspan="4" style="text-align:center; padding:40px; color:#999;">Click Analyze to see trends</td></tr>
        </tbody>
    </table>
</div>

<!-- Tab 3: Order Requests -->
<div class="tab-content <?= $active_tab === 'orders' ? 'active' : '' ?>" id="tab-orders">
    <div class="two-col-layout">
        <div class="form-panel">
            <h3><i class="fas fa-plus-circle"></i> New Order Request</h3>
            <form id="order-form">
                <label>Member Name</label>
                <input type="text" name="member_name" required placeholder="Enter member name">

                <label>Location</label>
                <select name="location_id" required>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Product (Optional)</label>
                <select name="product_id" id="order-product">
                    <option value="">-- Custom Item --</option>
                    <?php
                    $products = $pdo->query("SELECT p.id, p.name, p.size, p.color, c.name as category_name
                                             FROM products p
                                             JOIN product_categories c ON p.category_id = c.id
                                             WHERE p.is_active = 1
                                             ORDER BY c.sort_order, p.name, p.size")->fetchAll(PDO::FETCH_ASSOC);
                    $current_cat = '';
                    foreach ($products as $p):
                        if ($p['category_name'] !== $current_cat):
                            if ($current_cat !== '') echo '</optgroup>';
                            $current_cat = $p['category_name'];
                            echo '<optgroup label="' . e($current_cat) . '">';
                        endif;
                    ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> - <?= e($p['size']) ?> <?= $p['color'] ? '(' . e($p['color']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                    <?php if ($current_cat !== '') echo '</optgroup>'; ?>
                </select>

                <label>Or Description (for custom)</label>
                <input type="text" name="product_description" placeholder="e.g. Special order item">

                <label>Size</label>
                <input type="text" name="size_requested" placeholder="e.g. A2, Medium">

                <label>Color</label>
                <input type="text" name="color_requested" placeholder="e.g. Navy, White">

                <label>Quantity</label>
                <input type="number" name="quantity" value="1" min="1" required>

                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Additional notes..."></textarea>

                <button type="submit" class="btn btn-primary btn-block mt-1">
                    <i class="fas fa-plus"></i> Add Request
                </button>
            </form>
        </div>

        <div class="data-panel">
            <h3><i class="fas fa-list"></i> Pending Requests</h3>
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Product</th>
                        <th>Size/Color</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="orders-body">
                    <tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tab 4: Manage Products (Admin Only) -->
<?php if ($is_admin): ?>
<div class="tab-content <?= $active_tab === 'products' ? 'active' : '' ?>" id="tab-products">
    <div class="product-form">
        <h3><i class="fas fa-plus"></i> Add New Product</h3>
        <form id="product-form">
            <div class="form-row">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="name" required placeholder="e.g. Kids Gi White">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Size</label>
                    <input type="text" name="size" placeholder="e.g. A2, Y3, Medium">
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="text" name="color" placeholder="e.g. White, Navy">
                </div>
                <div class="form-group">
                    <label>Variant Type</label>
                    <select name="variant_type">
                        <option value="standard">Standard</option>
                        <option value="regular">Regular</option>
                        <option value="mesh">Mesh</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>SKU (Optional)</label>
                    <input type="text" name="sku" placeholder="Product SKU">
                </div>
                <div class="form-group">
                    <label>Low Stock Threshold</label>
                    <input type="number" name="low_stock_threshold" value="8" min="1">
                </div>
                <div class="form-group" style="flex:0 0 auto; display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Product
                    </button>
                </div>
            </div>
        </form>
    </div>

    <h3><i class="fas fa-list"></i> Product List</h3>
    <div class="filter-bar">
        <div class="filter-group">
            <label>Category</label>
            <select id="products-filter-category" onchange="loadProducts()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <table class="inventory-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Size</th>
                <th>Color</th>
                <th>Type</th>
                <th>Low Stock At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="products-body">
            <tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">Loading...</td></tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.dataset.tab;

            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            this.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);

            // Load data for the tab
            if (tabId === 'orders') loadOrders();
            if (tabId === 'products') loadProducts();
        });
    });

    // Initialize date pickers
    flatpickr('#filter-date', { dateFormat: 'Y-m-d' });
    flatpickr('#trends-from', { dateFormat: 'Y-m-d' });
    flatpickr('#trends-to', { dateFormat: 'Y-m-d' });

    // Load initial data
    const activeTab = '<?= $active_tab ?>';
    if (activeTab === 'orders') loadOrders();
    if (activeTab === 'products') loadProducts();

    // Order form submission
    document.getElementById('order-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'create');

        fetch('api/order_request_update.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.reset();
                loadOrders();
                showNotification('Order request added!', 'success');
            } else {
                showNotification(data.message || 'Error adding request', 'error');
            }
        });
    });

    // Product form submission
    const productForm = document.getElementById('product-form');
    if (productForm) {
        productForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'create');

            fetch('api/inventory_save.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.reset();
                    loadProducts();
                    showNotification('Product added!', 'success');
                } else {
                    showNotification(data.message || 'Error adding product', 'error');
                }
            });
        });
    }
});

let hasUnsavedChanges = false;

function loadInventory() {
    const locationId = document.getElementById('filter-location').value;
    const categoryId = document.getElementById('filter-category').value;
    const countDate = document.getElementById('filter-date').value;

    fetch(`api/inventory_load.php?action=counts&location_id=${locationId}&category_id=${categoryId}&count_date=${countDate}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderInventoryTable(data.products, data.counts, data.prev_counts);
            } else {
                showNotification(data.message || 'Error loading inventory', 'error');
            }
        });
}

function renderInventoryTable(products, counts, prevCounts) {
    const tbody = document.getElementById('inventory-body');
    let html = '';
    let currentCategory = '';

    products.forEach(product => {
        // Category header
        if (product.category_name !== currentCategory) {
            currentCategory = product.category_name;
            html += `<tr class="category-header"><td colspan="5"><i class="fas fa-folder"></i> ${escapeHtml(currentCategory)}</td></tr>`;
        }

        const count = counts[product.id] || 0;
        const prevCount = prevCounts[product.id] || 0;
        const threshold = product.low_stock_threshold || 8;

        let stockClass = '';
        if (count === 0) stockClass = 'stock-critical';
        else if (count < threshold) stockClass = 'stock-low';

        let trendHtml = '';
        const diff = prevCount - count;
        if (prevCount > 0 || count > 0) {
            if (diff > 0) {
                trendHtml = `<span class="trend-indicator trend-down"><i class="fas fa-arrow-down"></i> ${diff} sold</span>`;
            } else if (diff < 0) {
                trendHtml = `<span class="trend-indicator trend-up"><i class="fas fa-arrow-up"></i> ${Math.abs(diff)} added</span>`;
            } else {
                trendHtml = `<span class="trend-indicator trend-same">—</span>`;
            }
        }

        html += `
            <tr class="${stockClass}" data-product-id="${product.id}">
                <td>${escapeHtml(product.name)}</td>
                <td>${escapeHtml(product.size || '')} ${product.color ? '/ ' + escapeHtml(product.color) : ''}</td>
                <td style="text-align:center; color:#999;">${prevCount}</td>
                <td>
                    <input type="number" class="stock-input" value="${count}" min="0"
                           data-product-id="${product.id}" data-original="${count}"
                           onchange="markUnsaved()">
                </td>
                <td>${trendHtml}</td>
            </tr>
        `;
    });

    if (products.length === 0) {
        html = '<tr><td colspan="5" style="text-align:center; padding:40px; color:#999;">No products found</td></tr>';
    }

    tbody.innerHTML = html;
    hasUnsavedChanges = false;
    document.getElementById('unsaved-indicator').classList.remove('show');
}

function markUnsaved() {
    hasUnsavedChanges = true;
    document.getElementById('unsaved-indicator').classList.add('show');
}

function saveInventory() {
    const locationId = document.getElementById('filter-location').value;
    const countDate = document.getElementById('filter-date').value;
    const inputs = document.querySelectorAll('.stock-input');

    const counts = {};
    inputs.forEach(input => {
        counts[input.dataset.productId] = parseInt(input.value) || 0;
    });

    fetch('api/inventory_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_counts',
            location_id: locationId,
            count_date: countDate,
            counts: counts
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('Inventory saved!', 'success');
            hasUnsavedChanges = false;
            document.getElementById('unsaved-indicator').classList.remove('show');

            // Update original values
            inputs.forEach(input => {
                input.dataset.original = input.value;
            });
        } else {
            showNotification(data.message || 'Error saving inventory', 'error');
        }
    });
}

function loadTrends() {
    const locationId = document.getElementById('trends-location').value;
    const fromDate = document.getElementById('trends-from').value;
    const toDate = document.getElementById('trends-to').value;

    fetch(`api/inventory_load.php?action=trends&location_id=${locationId}&from_date=${fromDate}&to_date=${toDate}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderTrendsTable(data.trends);
            } else {
                showNotification(data.message || 'Error loading trends', 'error');
            }
        });
}

function renderTrendsTable(trends) {
    const tbody = document.getElementById('trends-body');
    let html = '';

    trends.forEach((item, index) => {
        const rank = index + 1;
        let rankClass = 'rank-default';
        if (rank === 1) rankClass = 'rank-1';
        else if (rank === 2) rankClass = 'rank-2';
        else if (rank === 3) rankClass = 'rank-3';

        html += `
            <tr>
                <td><span class="rank-badge ${rankClass}">${rank}</span></td>
                <td>${escapeHtml(item.name)}</td>
                <td>${escapeHtml(item.size || '')} ${item.color ? '/ ' + escapeHtml(item.color) : ''}</td>
                <td><strong>${item.estimated_sales}</strong> units</td>
            </tr>
        `;
    });

    if (trends.length === 0) {
        html = '<tr><td colspan="4" style="text-align:center; padding:40px; color:#999;">No sales data found for this period</td></tr>';
    }

    tbody.innerHTML = html;
}

function loadOrders() {
    fetch('api/inventory_load.php?action=orders')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderOrdersTable(data.orders, data.pending_count || 0);
            }
        });
}

function renderOrdersTable(orders, pendingCount) {
    const tbody = document.getElementById('orders-body');
    let html = '';

    orders.forEach(order => {
        const productName = order.product_name || order.product_description || 'Custom Item';
        const sizeColor = [order.size_requested, order.color_requested].filter(x => x).join(' / ') || '-';

        html += `
            <tr data-order-id="${order.id}">
                <td>${escapeHtml(order.member_name)}</td>
                <td>${escapeHtml(productName)}</td>
                <td>${escapeHtml(sizeColor)}</td>
                <td>${order.quantity}</td>
                <td>
                    <select class="status-select" data-order-id="${order.id}" onchange="updateOrderStatus(this)">
                        <option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="ordered" ${order.status === 'ordered' ? 'selected' : ''}>Ordered</option>
                        <option value="received" ${order.status === 'received' ? 'selected' : ''}>Received</option>
                        <option value="completed" ${order.status === 'completed' ? 'selected' : ''}>Completed</option>
                        <option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                    </select>
                </td>
                <td>
                    ${order.notes ? `<span title="${escapeHtml(order.notes)}" style="cursor:help;"><i class="fas fa-sticky-note" style="color:#ffc107;"></i></span>` : ''}
                    <button class="btn-icon danger" onclick="deleteOrder(${order.id})"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
    });

    if (orders.length === 0) {
        html = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">No order requests</td></tr>';
    }

    tbody.innerHTML = html;

    // Update pending badge
    updatePendingBadge(pendingCount);
}

function updatePendingBadge(count) {
    const badge = document.getElementById('pending-badge');
    if (badge) {
        badge.textContent = count > 0 ? count : '';
    }
}

function updateOrderStatus(select) {
    const orderId = select.dataset.orderId;
    const status = select.value;

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('order_id', orderId);
    formData.append('status', status);

    fetch('api/order_request_update.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showNotification('Status updated!', 'success');
            // Refresh to update pending badge
            loadOrders();
        } else {
            showNotification(data.message || 'Error updating status', 'error');
        }
    });
}

function deleteOrder(orderId) {
    if (!confirm('Delete this order request?')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('order_id', orderId);

    fetch('api/order_request_update.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadOrders();
            showNotification('Order deleted!', 'success');
        } else {
            showNotification(data.message || 'Error deleting order', 'error');
        }
    });
}

function loadProducts() {
    const categoryId = document.getElementById('products-filter-category')?.value || '';

    fetch(`api/inventory_load.php?action=products&category_id=${categoryId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderProductsTable(data.products);
            }
        });
}

function renderProductsTable(products) {
    const tbody = document.getElementById('products-body');
    if (!tbody) return;

    let html = '';

    products.forEach(product => {
        html += `
            <tr data-product-id="${product.id}">
                <td>${escapeHtml(product.name)}</td>
                <td>${escapeHtml(product.size || '-')}</td>
                <td>${escapeHtml(product.color || '-')}</td>
                <td>${escapeHtml(product.variant_type || 'standard')}</td>
                <td>${product.low_stock_threshold}</td>
                <td>
                    <button class="btn-icon" onclick="editProductThreshold(${product.id}, ${product.low_stock_threshold})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-icon danger" onclick="toggleProduct(${product.id}, 0)">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    if (products.length === 0) {
        html = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#999;">No products found</td></tr>';
    }

    tbody.innerHTML = html;
}

function editProductThreshold(productId, currentThreshold) {
    const newThreshold = prompt('Enter new low stock threshold:', currentThreshold);
    if (newThreshold === null) return;

    fetch('api/inventory_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_threshold',
            product_id: productId,
            threshold: parseInt(newThreshold)
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadProducts();
            showNotification('Threshold updated!', 'success');
        } else {
            showNotification(data.message || 'Error updating threshold', 'error');
        }
    });
}

function toggleProduct(productId, active) {
    if (!confirm('Deactivate this product?')) return;

    fetch('api/inventory_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'toggle_product',
            product_id: productId,
            is_active: active
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            loadProducts();
            showNotification('Product deactivated!', 'success');
        } else {
            showNotification(data.message || 'Error', 'error');
        }
    });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showNotification(message, type) {
    // Simple notification - could be enhanced
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    alertDiv.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; padding:15px 20px; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15);';
    document.body.appendChild(alertDiv);
    setTimeout(() => alertDiv.remove(), 3000);
}

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', function(e) {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
