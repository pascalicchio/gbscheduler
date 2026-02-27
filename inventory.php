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

// Fetch last inventory count date per location
$last_counts = [];
$stmt = $pdo->query("SELECT location_id, MAX(count_date) as last_date FROM inventory_counts GROUP BY location_id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $last_counts[$row['location_id']] = $row['last_date'];
}

// Current filters
$filter_location = $_GET['location_id'] ?? ($locations[0]['id'] ?? '');
$filter_category = $_GET['category_id'] ?? '';
$default_last_date = $last_counts[$filter_location] ?? date('Y-m-d');
$filter_date = $_GET['count_date'] ?? $default_last_date;
$active_tab = $_GET['tab'] ?? 'counts';

// Page setup
$pageTitle = 'Inventory Management | GB Scheduler';
$extraHead = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
HTML;

$extraCss = <<<CSS
    body { padding: 20px; }

    [x-cloak] { display: none !important; }

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        gap: 12px;
    }

    .page-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
    }

    .page-header h2 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    @media (min-width: 768px) {
        .page-header h2 {
            font-size: 1.75rem;
        }
    }
.tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .tab-btn {
        position: relative;
        padding: 12px 20px;
        border: 2px solid #e8ecf2;
        background: white;
        cursor: pointer;
        font-weight: 600;
        color: #6c757d;
        transition: all 0.25s ease;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .tab-btn:hover {
        border-color: rgba(0, 201, 255, 0.3);
        color: rgb(0, 201, 255);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .tab-btn.active {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
        border-color: transparent;
        box-shadow: 0 4px 12px rgba(26, 32, 44, 0.3);
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
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .tab-content.active {
        display: block;
    }

    .tab-content h3 {
        margin-top: 0;
        margin-bottom: 16px;
        color: #2c3e50;
        font-size: 1rem;
        font-weight: 700;
    }

    .tab-content h3 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-right: 6px;
    }

    .filter-bar {
        display: flex;
        gap: 12px;
        align-items: flex-end;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding: 18px;
        background: #f8fafb;
        border-radius: 12px;
        border: 1px solid #e8ecf2;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .filter-group label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #2c3e50;
    }

    .filter-group select,
    .filter-group input {
        padding: 10px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 500;
        min-width: 150px;
        background: white;
        transition: all 0.25s ease;
        font-family: inherit;
    }

    .filter-group select:focus,
    .filter-group input:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .last-count-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: linear-gradient(135deg, rgba(0, 201, 255, 0.08), rgba(146, 254, 157, 0.08));
        border: 1px solid rgba(0, 201, 255, 0.2);
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #2c3e50;
        white-space: nowrap;
        align-self: flex-end;
    }

    .last-count-badge i {
        color: rgb(0, 201, 255);
    }

    .last-count-badge .last-date {
        color: rgb(0, 160, 200);
        font-weight: 700;
    }

    .last-count-badge.never {
        background: rgba(220, 53, 69, 0.08);
        border-color: rgba(220, 53, 69, 0.2);
    }

    .last-count-badge.never i,
    .last-count-badge.never .last-date {
        color: #dc3545;
    }

    /* Gradient buttons */
    .btn-gradient {
        padding: 10px 20px;
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        color: white;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-gradient:hover {
        background-image: linear-gradient(135deg, rgb(0, 181, 235), rgb(126, 234, 137));
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 201, 255, 0.3);
    }

    /* Inventory Table */
    .inventory-table {
        width: 100%;
        border-collapse: collapse;
    }

    .inventory-table th {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
        padding: 12px;
        text-align: left;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .inventory-table td {
        padding: 7px 12px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
        font-size: 0.9rem;
    }

    .inventory-table tbody tr:hover {
        background: #f8fafb;
    }

    .category-header {
        background: #f8fafb !important;
    }

    .category-header td {
        padding: 10px 12px 6px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c757d;
        border-bottom: 1px solid #e8ecf2;
    }

    .category-header td i {
        color: rgb(0, 201, 255);
        margin-right: 4px;
    }

    /* Stock Level — subtle left border instead of full row bg */
    .stock-critical {
        border-left: 3px solid #dc3545;
    }

    .stock-critical td:first-child {
        color: #dc3545;
        font-weight: 600;
    }

    .stock-low {
        border-left: 3px solid #f0ad4e;
    }

    .stock-low td:first-child {
        color: #b8860b;
    }

    .stock-input {
        width: 70px;
        padding: 5px 8px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        text-align: center;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.25s ease;
        font-family: inherit;
    }

    .stock-input:focus {
        border-color: rgb(0, 201, 255);
        outline: none;
        box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.1);
    }

    .trend-indicator {
        font-size: 0.8rem;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 600;
    }

    .trend-down {
        color: #dc3545;
        background: rgba(220, 53, 69, 0.08);
    }

    .trend-up {
        color: #28a745;
        background: rgba(40, 167, 69, 0.08);
    }

    .trend-same {
        color: #adb5bd;
    }

    .text-muted-center {
        text-align: center;
        color: #adb5bd;
    }

    /* Two Column Layout */
    .two-col-layout {
        display: flex;
        gap: 20px;
    }

    .form-panel {
        flex: 0 0 350px;
        background: #f8fafb;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e8ecf2;
    }

    .form-panel h3 {
        margin-top: 0;
        margin-bottom: 20px;
        color: #2c3e50;
        font-size: 1rem;
        font-weight: 700;
    }

    .form-panel h3 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-right: 6px;
    }

    .form-panel label {
        display: block;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #2c3e50;
        margin-bottom: 6px;
    }

    .form-panel input,
    .form-panel select,
    .form-panel textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        box-sizing: border-box;
        font-size: 0.9rem;
        font-weight: 500;
        background: white;
        transition: all 0.25s ease;
        font-family: inherit;
        margin-bottom: 14px;
    }

    .form-panel input:focus,
    .form-panel select:focus,
    .form-panel textarea:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    .data-panel {
        flex: 1;
        overflow-x: auto;
    }

    .data-panel h3 {
        margin-top: 0;
        margin-bottom: 16px;
        color: #2c3e50;
        font-size: 1rem;
        font-weight: 700;
    }

    .data-panel h3 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-right: 6px;
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
        border-top: 2px solid #e8ecf2;
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
        background: #f8fafb;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e8ecf2;
        margin-bottom: 24px;
    }

    .product-form h3 {
        margin-top: 0;
        margin-bottom: 20px;
        color: #2c3e50;
        font-size: 1rem;
        font-weight: 700;
    }

    .product-form h3 i {
        background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-right: 6px;
    }

    .product-form .form-row {
        display: flex;
        gap: 16px;
        margin-bottom: 0;
    }

    .product-form .form-group {
        flex: 1;
    }

    .product-form label {
        display: block;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #2c3e50;
        margin-bottom: 6px;
    }

    .product-form input,
    .product-form select {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        box-sizing: border-box;
        font-size: 0.9rem;
        font-weight: 500;
        background: white;
        transition: all 0.25s ease;
        font-family: inherit;
        margin-bottom: 14px;
    }

    .product-form input:focus,
    .product-form select:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 4px rgba(0, 201, 255, 0.1);
    }

    /* Best Sellers */
    .best-sellers-table {
        width: 100%;
    }

    .best-sellers-table th {
        background: linear-gradient(135deg, #1a202c, #2d3748);
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

    /* Order Filter Chips */
    .order-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
    }

    .order-panel-header h3 {
        margin: 0;
        white-space: nowrap;
    }

    .order-filter-bar {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }

    .order-filter-sep {
        width: 1px;
        height: 20px;
        background: #e2e8f0;
        flex-shrink: 0;
    }

    .order-filter-chip {
        padding: 5px 12px;
        height: 32px;
        box-sizing: border-box;
        border: 2px solid #e2e8f0;
        border-radius: 20px;
        background: white;
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        color: #6c757d;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .order-filter-chip:hover {
        border-color: rgba(0,201,255,0.4);
        color: #2c3e50;
    }

    .order-filter-chip.active {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
        border-color: transparent;
    }

    .order-filter-chip .chip-count {
        background: #e9ecef;
        color: #495057;
        border-radius: 10px;
        padding: 0 5px;
        font-size: 0.72rem;
        line-height: 18px;
        min-width: 18px;
        text-align: center;
    }

    .order-filter-chip.active .chip-count {
        background: rgba(255,255,255,0.2);
        color: white;
    }

    /* Note cell in orders table */
    .note-cell {
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }

    .note-preview {
        font-size: 0.82rem;
        color: #6c757d;
        max-width: 140px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .note-count-badge {
        font-size: 0.72rem;
        font-weight: 700;
        background: rgba(0,201,255,0.12);
        color: rgb(0, 160, 200);
        border-radius: 10px;
        padding: 1px 6px;
        white-space: nowrap;
    }

    .btn-add-note {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid #e2e8f0;
        background: white;
        color: #6c757d;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        flex-shrink: 0;
        line-height: 1;
    }

    .btn-add-note:hover {
        border-color: rgb(0, 201, 255);
        color: rgb(0, 201, 255);
        background: rgba(0,201,255,0.06);
    }

    /* Note Modal */
    .note-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .note-modal-overlay.open {
        display: flex;
    }

    .note-modal {
        background: white;
        border-radius: 14px;
        width: 480px;
        max-width: 95vw;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .note-modal-header {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .note-modal-header h4 {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
    }

    .note-modal-header .modal-subtitle {
        font-size: 0.8rem;
        opacity: 0.65;
        margin-top: 2px;
    }

    .note-modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.2rem;
        cursor: pointer;
        opacity: 0.7;
        padding: 4px;
        line-height: 1;
    }

    .note-modal-close:hover { opacity: 1; }

    .note-modal-log {
        padding: 16px 20px;
        max-height: 280px;
        overflow-y: auto;
        border-bottom: 1px solid #e8ecf2;
        background: #f8fafb;
    }

    .note-log-empty {
        color: #adb5bd;
        font-style: italic;
        font-size: 0.875rem;
        text-align: center;
        margin: 20px 0;
    }

    .note-log-entry {
        margin-bottom: 10px;
        line-height: 1.4;
    }

    .note-log-entry:last-child { margin-bottom: 0; }

    .note-log-ts {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        color: rgb(0, 160, 200);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 2px;
    }

    .note-log-text {
        font-size: 0.88rem;
        color: #2c3e50;
    }

    .note-log-legacy {
        font-size: 0.88rem;
        color: #6c757d;
        font-style: italic;
    }

    .note-modal-footer {
        padding: 16px 20px;
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }

    .note-modal-footer textarea {
        flex: 1;
        padding: 10px 12px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.9rem;
        font-family: inherit;
        resize: none;
        rows: 2;
        transition: border-color 0.2s;
    }

    .note-modal-footer textarea:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 3px rgba(0,201,255,0.1);
    }

    .note-modal-footer .btn-save-note {
        padding: 10px 18px;
        font-size: 0.875rem;
        white-space: nowrap;
        align-self: flex-end;
    }

    /* View Toggle */
    .view-toggle {
        display: flex;
        gap: 0;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        align-self: flex-end;
    }

    .view-toggle-btn {
        padding: 10px 16px;
        background: white;
        border: none;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        color: #6c757d;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .view-toggle-btn:hover {
        background: #f8fafb;
        color: #2c3e50;
    }

    .view-toggle-btn.active {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
    }

    /* Buying View Cards */
    .buying-summary-banner {
        padding: 10px 16px;
        background: linear-gradient(135deg, rgba(220,53,69,0.08), rgba(240,173,78,0.08));
        border: 1px solid rgba(220,53,69,0.2);
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 0.9rem;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }

    .buying-summary-banner .stat-critical { color: #dc3545; }
    .buying-summary-banner .stat-low { color: #b8860b; }

    .buying-cards-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-start;
    }

    .buying-card {
        background: white;
        border: 1px solid #e8ecf2;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        overflow: hidden;
        flex: 0 0 auto;
        width: max-content;
        min-width: 280px;
        max-width: 100%;
    }

    /* Wide cards (many color columns) fill the full row and distribute evenly */
    .buying-card.card-wide {
        flex: 1 1 100%;
        width: 100%;
    }

    .buying-card.card-wide .buying-matrix {
        table-layout: fixed;
        width: 100%;
    }

    .buying-card.card-wide .buying-matrix th,
    .buying-card.card-wide .buying-matrix td {
        min-width: unset;
    }

    .buying-card.card-wide .buying-matrix th.size-col,
    .buying-card.card-wide .buying-matrix td.size-label {
        width: 44px;
    }

    .buying-card-header {
        background: linear-gradient(135deg, #1a202c, #2d3748);
        color: white;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 700;
        font-size: 0.95rem;
    }

    .buying-card-header .alert-badge {
        background: #dc3545;
        color: white;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 10px;
    }

    .buying-card-body {
        padding: 12px;
    }

    .buying-matrix {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .buying-matrix th {
        background: #f8fafb;
        color: #6c757d;
        padding: 6px 10px;
        text-align: center;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e8ecf2;
        min-width: 80px;
    }

    .buying-matrix th.size-col {
        text-align: left;
        color: #2c3e50;
        min-width: auto;
    }

    .buying-matrix td {
        padding: 5px 8px;
        text-align: center;
        border-bottom: 1px solid #f5f5f5;
        font-weight: 600;
        min-width: 80px;
    }

    .buying-matrix td.size-label {
        text-align: left;
        font-weight: 700;
        color: #2c3e50;
        white-space: nowrap;
    }

    .qty-cell.qty-zero {
        background: rgba(220,53,69,0.15);
        color: #dc3545;
        font-weight: 700;
    }

    .qty-cell.qty-low {
        background: rgba(240,173,78,0.15);
        color: #b8860b;
        font-weight: 700;
    }

    .qty-cell.no-product {
        color: #dee2e6;
    }

    /* Card cell inputs & check-off */
    .qty-cell {
        position: relative;
    }

    .card-qty-input {
        width: 50px !important;
        min-width: 50px !important;
        padding: 2px !important;
        border: 1.5px solid #e2e8f0;
        border-radius: 6px;
        text-align: center;
        font-size: 0.88rem;
        font-weight: 600;
        font-family: inherit;
        background: white;
        transition: border-color 0.2s, box-shadow 0.2s;
        display: block;
        margin: 0 auto;
    }

    .card-qty-input:focus {
        outline: none;
        border-color: rgb(0, 201, 255);
        box-shadow: 0 0 0 2px rgba(0, 201, 255, 0.12);
    }

    .qty-cell.qty-zero .card-qty-input {
        border-color: rgba(220,53,69,0.4);
        background: rgba(220,53,69,0.06);
    }

    .qty-cell.qty-low .card-qty-input {
        border-color: rgba(240,173,78,0.4);
        background: rgba(240,173,78,0.06);
    }

    .cell-check-btn {
        position: absolute;
        top: 2px;
        right: 3px;
        width: 15px;
        height: 15px;
        border-radius: 50%;
        border: 1.5px solid #dee2e6;
        background: white;
        color: transparent;
        font-size: 0.55rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: all 0.15s ease;
        padding: 0;
        line-height: 1;
    }

    .qty-cell:hover .cell-check-btn,
    .cell-check-btn.checked {
        opacity: 1;
    }

    .cell-check-btn.checked {
        border-color: #28a745;
        background: #28a745;
        color: white;
    }

    .qty-cell.cell-checked {
        opacity: 0.3;
    }

    .qty-cell.cell-checked .card-qty-input {
        pointer-events: none;
    }

    .clear-checks-btn {
        margin-left: auto;
        background: none;
        border: 1px solid rgba(220,53,69,0.35);
        border-radius: 8px;
        color: #dc3545;
        font-size: 0.78rem;
        font-weight: 600;
        padding: 3px 10px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .clear-checks-btn:hover {
        background: rgba(220,53,69,0.08);
    }

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

<div class="page-header">
    <h2><i class="fas fa-boxes"></i> Inventory Management</h2>
    <?php include 'includes/nav-menu.php'; ?>
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
            <select id="filter-location" onchange="onLocationChange()">
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
        <button class="btn-gradient" onclick="loadInventory()">
            <i class="fas fa-sync"></i> Load
        </button>
        <div class="view-toggle">
            <button class="view-toggle-btn" data-mode="table" onclick="setViewMode('table')">
                <i class="fas fa-table"></i> Table
            </button>
            <button class="view-toggle-btn active" data-mode="cards" onclick="setViewMode('cards')">
                <i class="fas fa-th-large"></i> Card View
            </button>
        </div>
        <span class="last-count-badge" id="last-count-badge">
            <i class="fas fa-clock"></i> Last count: <span class="last-date" id="last-count-date">—</span>
        </span>
    </div>

    <div id="inventory-container">
        <div id="inventory-table-wrapper">
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Size / Color</th>
                        <th>Last Week</th>
                        <th>Current Count</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody id="inventory-body">
                    <tr><td colspan="5" class="text-center text-gray-400 py-10">Select a location and date, then click Load</td></tr>
                </tbody>
            </table>
        </div>
        <div id="inventory-cards-wrapper" style="display:none;"></div>
    </div>

    <div class="save-bar">
        <span class="unsaved-indicator" id="unsaved-indicator">
            <i class="fas fa-exclamation-circle"></i> Unsaved changes
        </span>
        <button class="btn-gradient" onclick="saveInventory()">
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
        <button class="btn-gradient" onclick="loadTrends()">
            <i class="fas fa-chart-line"></i> Analyze
        </button>
    </div>

    <h3><i class="fas fa-fire"></i> Best Sellers (Estimated from Inventory Depletion)</h3>
    <table class="inventory-table best-sellers-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Product</th>
                <th>Size / Color</th>
                <th>Est. Sales</th>
            </tr>
        </thead>
        <tbody id="trends-body">
            <tr><td colspan="4" class="text-center text-gray-400 py-10">Click Analyze to see trends</td></tr>
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

                <button type="submit" class="btn-gradient mt-1">
                    <i class="fas fa-plus"></i> Add Request
                </button>
            </form>
        </div>

        <div class="data-panel">
            <div class="order-panel-header">
                <h3><i class="fas fa-list"></i> Order Requests</h3>
                <div class="order-filter-bar">
                    <button class="order-filter-chip active" data-status="all" onclick="filterOrders('all')">
                        All <span class="chip-count" id="chip-count-all">0</span>
                    </button>
                    <button class="order-filter-chip" data-status="pending" onclick="filterOrders('pending')">
                        Pending <span class="chip-count" id="chip-count-pending">0</span>
                    </button>
                    <button class="order-filter-chip" data-status="ordered" onclick="filterOrders('ordered')">
                        Ordered <span class="chip-count" id="chip-count-ordered">0</span>
                    </button>
                    <button class="order-filter-chip" data-status="received" onclick="filterOrders('received')">
                        Received <span class="chip-count" id="chip-count-received">0</span>
                    </button>
                    <button class="order-filter-chip" data-status="completed" onclick="filterOrders('completed')">
                        Completed <span class="chip-count" id="chip-count-completed">0</span>
                    </button>
                    <button class="order-filter-chip" data-status="cancelled" onclick="filterOrders('cancelled')">
                        Cancelled <span class="chip-count" id="chip-count-cancelled">0</span>
                    </button>
                    <span class="order-filter-sep"></span>
                    <?php foreach ($locations as $loc): ?>
                    <button class="order-filter-chip" data-location="<?= $loc['id'] ?>" onclick="filterOrdersByLocation(<?= $loc['id'] ?>)">
                        <i class="fas fa-map-marker-alt"></i> <?= e($loc['name']) ?> <span class="chip-count" id="chip-loc-<?= $loc['id'] ?>">0</span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Member</th>
                        <th>Product</th>
                        <th>Size/Color</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="orders-body">
                    <tr><td colspan="8" class="text-center text-gray-400 py-5">Loading...</td></tr>
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
                <div class="form-group flex-none flex items-end">
                    <button type="submit" class="btn-gradient">
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
            <tr><td colspan="6" class="text-center text-gray-400 py-5">Loading...</td></tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// Last count dates per location
const lastCounts = <?= json_encode($last_counts) ?>;

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

    // Show last count badge for initial location
    updateLastCountBadge();

    // Load initial data
    const activeTab = '<?= $active_tab ?>';
    if (activeTab === 'counts') loadInventory();
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
let currentInventoryData = [];
let lastApiResponse = null;
let viewMode = 'cards';

function formatLastDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function updateLastCountBadge() {
    const locationId = document.getElementById('filter-location').value;
    const badge = document.getElementById('last-count-badge');
    const dateSpan = document.getElementById('last-count-date');
    const lastDate = lastCounts[locationId];

    if (lastDate) {
        dateSpan.textContent = formatLastDate(lastDate);
        badge.classList.remove('never');
    } else {
        dateSpan.textContent = 'Never';
        badge.classList.add('never');
    }
}

function onLocationChange() {
    const locationId = document.getElementById('filter-location').value;
    const lastDate = lastCounts[locationId] || new Date().toISOString().split('T')[0];

    // Update date picker to last count date
    const dateInput = document.getElementById('filter-date');
    dateInput._flatpickr.setDate(lastDate, true);

    updateLastCountBadge();
    loadInventory();
}

function loadInventory() {
    const locationId = document.getElementById('filter-location').value;
    const categoryId = document.getElementById('filter-category').value;
    const countDate = document.getElementById('filter-date').value;

    fetch(`api/inventory_load.php?action=counts&location_id=${locationId}&category_id=${categoryId}&count_date=${countDate}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                lastApiResponse = { products: data.products, counts: data.counts, prevCounts: data.prev_counts };
                currentInventoryData = data.products.map(p => ({
                    product_id: p.id,
                    category_name: p.category_name,
                    size: p.size || '',
                    color: p.color || '',
                    variant_type: p.variant_type || 'standard',
                    low_stock_threshold: p.low_stock_threshold || 8,
                    current_qty: data.counts[p.id] ?? 0,
                    previous_qty: data.prev_counts[p.id] ?? 0
                }));
                if (viewMode === 'cards') {
                    renderInventoryCards(currentInventoryData);
                } else {
                    renderInventoryTable(data.products, data.counts, data.prev_counts);
                }
            } else {
                showNotification(data.message || 'Error loading inventory', 'error');
            }
        });
}

function renderInventoryTable(products, counts, prevCounts) {
    const tableWrapper = document.getElementById('inventory-table-wrapper');
    const cardsWrapper = document.getElementById('inventory-cards-wrapper');
    if (tableWrapper) tableWrapper.style.display = '';
    if (cardsWrapper) cardsWrapper.style.display = 'none';

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
                <td class="text-muted-center">${prevCount}</td>
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
        html = '<tr><td colspan="5" class="text-center text-gray-400 py-10">No products found</td></tr>';
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

            // Update last count date for this location
            lastCounts[locationId] = countDate;
            updateLastCountBadge();

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
        html = '<tr><td colspan="4" class="text-center text-gray-400 py-10">No sales data found for this period</td></tr>';
    }

    tbody.innerHTML = html;
}

let allOrders = [];
let activeOrderFilter = 'all';
let activeLocationFilter = null;
const STATUS_ORDER = { pending: 1, ordered: 2, received: 3, completed: 4, cancelled: 5 };

function loadOrders() {
    fetch('api/inventory_load.php?action=orders')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                allOrders = data.orders;
                updateOrderFilterCounts();
                filterOrders(activeOrderFilter);
                updatePendingBadge(data.pending_count || 0);
            }
        });
}

function updateOrderFilterCounts() {
    const locFiltered = activeLocationFilter
        ? allOrders.filter(o => o.location_id == activeLocationFilter)
        : allOrders;

    const counts = { all: locFiltered.length };
    ['pending', 'ordered', 'received', 'completed', 'cancelled'].forEach(s => {
        counts[s] = locFiltered.filter(o => o.status === s).length;
    });
    Object.entries(counts).forEach(([status, count]) => {
        const el = document.getElementById('chip-count-' + status);
        if (el) el.textContent = count;
    });

    // Location counts (unaffected by location filter)
    document.querySelectorAll('[data-location]').forEach(btn => {
        const locId = btn.dataset.location;
        const locCount = allOrders.filter(o => o.location_id == locId).length;
        const el = document.getElementById('chip-loc-' + locId);
        if (el) el.textContent = locCount;
    });
}

function filterOrders(status) {
    activeOrderFilter = status;
    document.querySelectorAll('.order-filter-chip[data-status]').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.status === status);
    });
    renderOrdersTable();
}

function filterOrdersByLocation(locationId) {
    activeLocationFilter = activeLocationFilter == locationId ? null : locationId;
    document.querySelectorAll('.order-filter-chip[data-location]').forEach(chip => {
        chip.classList.toggle('active', activeLocationFilter != null && chip.dataset.location == activeLocationFilter);
    });
    updateOrderFilterCounts();
    renderOrdersTable();
}

function renderOrdersTable() {
    let filtered = allOrders;
    if (activeLocationFilter) filtered = filtered.filter(o => o.location_id == activeLocationFilter);
    if (activeOrderFilter !== 'all') filtered = filtered.filter(o => o.status === activeOrderFilter);

    // Sort: status priority → category sort_order → product name → size → color
    const sorted = [...filtered].sort((a, b) => {
        const sa = STATUS_ORDER[a.status] || 99;
        const sb = STATUS_ORDER[b.status] || 99;
        if (sa !== sb) return sa - sb;

        const ca = a.category_sort_order ?? 999;
        const cb = b.category_sort_order ?? 999;
        if (ca !== cb) return ca - cb;

        const na = (a.product_name || a.product_description || '').toLowerCase();
        const nb = (b.product_name || b.product_description || '').toLowerCase();
        if (na !== nb) return na.localeCompare(nb);

        const sizeA = a.product_size || a.size_requested || '';
        const sizeB = b.product_size || b.size_requested || '';
        const sizeCmp = compareSizes(sizeA, sizeB);
        if (sizeCmp !== 0) return sizeCmp;

        const colorA = (a.product_color || a.color_requested || '').toLowerCase();
        const colorB = (b.product_color || b.color_requested || '').toLowerCase();
        return colorA.localeCompare(colorB);
    });

    const tbody = document.getElementById('orders-body');
    let html = '';

    sorted.forEach(order => {
        const productName = order.product_name || order.product_description || 'Custom Item';
        const sizeColor = [order.size_requested, order.color_requested].filter(x => x).join(' / ') || '-';

        html += `
            <tr data-order-id="${order.id}">
                <td><strong>${escapeHtml(order.location_name)}</strong></td>
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
                    <div class="note-cell">
                        ${buildNotePreview(order.notes)}
                        <button class="btn-add-note" onclick="openNoteModal(${order.id})" title="Add note">+</button>
                    </div>
                </td>
                <td>
                    <button class="btn-icon danger" onclick="deleteOrder(${order.id})"><i class="fas fa-trash"></i></button>
                </td>
            </tr>
        `;
    });

    if (sorted.length === 0) {
        html = '<tr><td colspan="8" class="text-center text-gray-400 py-5">No order requests</td></tr>';
    }

    tbody.innerHTML = html;
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

let noteModalOrderId = null;

function buildNotePreview(notes) {
    if (!notes || !notes.trim()) return '';
    const lines = notes.split('\n').filter(l => l.trim());
    const count = lines.length;
    const last = lines[lines.length - 1].replace(/^\[[^\]]+\]\s*/, '');
    const preview = last.length > 32 ? last.substring(0, 32) + '…' : last;
    return `<span class="note-preview" title="${escapeHtml(notes)}">${escapeHtml(preview)}</span>`
         + (count > 1 ? `<span class="note-count-badge">${count}</span>` : '');
}

function openNoteModal(orderId) {
    noteModalOrderId = orderId;
    const order = allOrders.find(o => o.id == orderId);
    if (!order) return;

    const productName = order.product_name || order.product_description || 'Custom Item';
    const sizeColor = [order.size_requested, order.color_requested].filter(x => x).join(' / ');
    document.getElementById('note-modal-product').textContent = productName + (sizeColor ? ' — ' + sizeColor : '');

    renderNoteLog(order.notes || '');
    document.getElementById('note-modal-input').value = '';
    document.getElementById('note-modal-overlay').classList.add('open');
    document.getElementById('note-modal-input').focus();
}

function renderNoteLog(notes) {
    const logEl = document.getElementById('note-modal-log');
    if (!notes.trim()) {
        logEl.innerHTML = '<p class="note-log-empty">No notes yet — add the first one below.</p>';
        return;
    }
    const lines = notes.split('\n').filter(l => l.trim());
    logEl.innerHTML = lines.map(line => {
        const match = line.match(/^\[([^\]]+)\]\s*(.*)$/);
        if (match) {
            return `<div class="note-log-entry">
                <span class="note-log-ts">${escapeHtml(match[1])}</span>
                <span class="note-log-text">${escapeHtml(match[2])}</span>
            </div>`;
        }
        return `<div class="note-log-entry"><span class="note-log-legacy">${escapeHtml(line)}</span></div>`;
    }).join('');
    logEl.scrollTop = logEl.scrollHeight;
}

function closeNoteModal() {
    document.getElementById('note-modal-overlay').classList.remove('open');
    noteModalOrderId = null;
}

function saveNoteEntry() {
    const text = document.getElementById('note-modal-input').value.trim();
    if (!text) return;

    const order = allOrders.find(o => o.id == noteModalOrderId);
    if (!order) return;

    const now = new Date();
    const ts = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' '
             + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    const newEntry = `[${ts}] ${text}`;
    const newNotes = order.notes ? order.notes + '\n' + newEntry : newEntry;

    const saveBtn = document.getElementById('note-modal-save');
    saveBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'update_note');
    formData.append('order_id', noteModalOrderId);
    formData.append('note', newNotes);

    fetch('api/order_request_update.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            saveBtn.disabled = false;
            if (data.success) {
                order.notes = newNotes;
                document.getElementById('note-modal-input').value = '';
                renderNoteLog(newNotes);
                renderOrdersTable();
            } else {
                showNotification(data.message || 'Error saving note', 'error');
            }
        })
        .catch(() => {
            saveBtn.disabled = false;
            showNotification('Error saving note', 'error');
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
        html = '<tr><td colspan="6" class="text-center text-gray-400 py-5">No products found</td></tr>';
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

function setViewMode(mode) {
    viewMode = mode;
    document.querySelectorAll('.view-toggle-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });
    if (!lastApiResponse) return;
    if (mode === 'cards') {
        renderInventoryCards(currentInventoryData);
    } else {
        renderInventoryTable(lastApiResponse.products, lastApiResponse.counts, lastApiResponse.prevCounts);
    }
}

function compareSizes(a, b) {
    const ORDER = ['Y0','Y1','Y2','Y3','Y4','Y5','Y6',
                   'XS','S','M','L','XL','XXL','2XL',
                   'A0','A1','A1L','A1H','A2','A2L','A2H','A3','A3H','A4','A5','A6'];
    const ai = ORDER.indexOf(a.toUpperCase());
    const bi = ORDER.indexOf(b.toUpperCase());
    if (ai !== -1 && bi !== -1) return ai - bi;
    if (ai !== -1) return -1;
    if (bi !== -1) return 1;
    return a.localeCompare(b);
}

function compareColors(a, b) {
    const ORDER = [
        'white',
        'gray/white','grey/white',
        'gray','grey',
        'gray/black','grey/black',
        'yellow/white',
        'yellow',
        'yellow/black',
        'orange/white',
        'orange',
        'orange/black',
        'green/white',
        'green',
        'green/black',
        'blue',
        'purple',
        'brown',
        'black',
        'navy',
        'red',
        'pink'
    ];
    const ai = ORDER.indexOf(a.toLowerCase());
    const bi = ORDER.indexOf(b.toLowerCase());
    if (ai !== -1 && bi !== -1) return ai - bi;
    if (ai !== -1) return -1;
    if (bi !== -1) return 1;
    return a.localeCompare(b);
}

// ── Check-off helpers (per-location localStorage) ──────────────────────────
function getChecks() {
    const locId = document.getElementById('filter-location').value;
    try { return new Set(JSON.parse(localStorage.getItem('inventory_checks_' + locId) || '[]')); }
    catch(e) { return new Set(); }
}

function persistChecks(checks) {
    const locId = document.getElementById('filter-location').value;
    localStorage.setItem('inventory_checks_' + locId, JSON.stringify([...checks]));
}

function toggleCheck(productId, btn) {
    const checks = getChecks();
    const nowChecked = !checks.has(productId);
    nowChecked ? checks.add(productId) : checks.delete(productId);
    persistChecks(checks);

    const cell = btn.closest('.qty-cell');
    cell.classList.toggle('cell-checked', nowChecked);
    btn.classList.toggle('checked', nowChecked);
    btn.title = nowChecked ? 'Unmark as ordered' : 'Mark as ordered';
}

function clearAllChecks() {
    const locId = document.getElementById('filter-location').value;
    localStorage.removeItem('inventory_checks_' + locId);
    renderInventoryCards(currentInventoryData);
}

function updateCardCellStyle(input) {
    const cell = input.closest('.qty-cell');
    if (!cell) return;
    const product = currentInventoryData.find(p => p.product_id == input.dataset.productId);
    const threshold = product ? product.low_stock_threshold : 8;
    const qty = parseInt(input.value) || 0;
    cell.classList.remove('qty-zero', 'qty-low');
    if (qty === 0) cell.classList.add('qty-zero');
    else if (qty < threshold) cell.classList.add('qty-low');
}

// ── Main card renderer ──────────────────────────────────────────────────────
function renderInventoryCards(items) {
    const tableWrapper = document.getElementById('inventory-table-wrapper');
    const cardsWrapper = document.getElementById('inventory-cards-wrapper');

    tableWrapper.style.display = 'none';
    cardsWrapper.style.display = 'block';

    if (!items || items.length === 0) {
        cardsWrapper.innerHTML = '<p style="color:#adb5bd;text-align:center;padding:40px;">No products found</p>';
        return;
    }

    const checks = getChecks();

    // Count categories per name to detect multi-variant categories
    const catVariantCounts = {};
    items.forEach(item => {
        const cat = item.category_name;
        if (!catVariantCounts[cat]) catVariantCounts[cat] = new Set();
        catVariantCounts[cat].add(item.variant_type);
    });

    // Group by category + variant_type
    const groups = {};
    items.forEach(item => {
        const key = item.category_name + '|' + item.variant_type;
        if (!groups[key]) {
            groups[key] = { category_name: item.category_name, variant_type: item.variant_type, items: [] };
        }
        groups[key].items.push(item);
    });

    let totalCritical = 0;
    let totalLow = 0;

    const groupList = Object.values(groups).map(group => {
        const sizes = [...new Set(group.items.map(i => i.size))].sort(compareSizes);
        const colors = [...new Set(group.items.map(i => i.color))].sort(compareColors);
        const lookup = {};
        group.items.forEach(item => { lookup[item.size + '|' + item.color] = item; });

        let alerts = 0;
        group.items.forEach(item => {
            if (item.current_qty === 0) { alerts++; totalCritical++; }
            else if (item.current_qty < item.low_stock_threshold) { alerts++; totalLow++; }
        });

        return { ...group, sizes, colors, lookup, alerts };
    });

    groupList.sort((a, b) => b.alerts - a.alerts);

    // Summary banner
    const hasChecks = checks.size > 0;
    let summaryHtml = '';
    if (totalCritical > 0 || totalLow > 0 || hasChecks) {
        summaryHtml = `<div class="buying-summary-banner">
            ${totalCritical > 0 || totalLow > 0 ? '<i class="fas fa-exclamation-triangle"></i>' : ''}
            ${totalCritical > 0 ? `<span class="stat-critical"><i class="fas fa-times-circle"></i> ${totalCritical} out of stock</span>` : ''}
            ${totalLow > 0 ? `<span class="stat-low"><i class="fas fa-exclamation-circle"></i> ${totalLow} low stock</span>` : ''}
            ${hasChecks ? `<button class="clear-checks-btn" onclick="clearAllChecks()"><i class="fas fa-times"></i> Clear ${checks.size} check${checks.size > 1 ? 's' : ''}</button>` : ''}
        </div>`;
    }

    // Build cards
    let cardsHtml = '<div class="buying-cards-grid">';

    groupList.forEach(group => {
        const multiVariant = catVariantCounts[group.category_name].size > 1;
        let title = escapeHtml(group.category_name);
        if (multiVariant && group.variant_type && group.variant_type !== 'standard') {
            const label = group.variant_type.charAt(0).toUpperCase() + group.variant_type.slice(1);
            title += ` <span style="font-weight:400;opacity:0.75;font-size:0.85em">(${escapeHtml(label)})</span>`;
        }

        const alertBadge = group.alerts > 0
            ? `<span class="alert-badge">${group.alerts} alert${group.alerts > 1 ? 's' : ''}</span>`
            : '';

        let matrixHtml = '<table class="buying-matrix"><thead><tr>';
        matrixHtml += '<th class="size-col">Size</th>';
        group.colors.forEach(color => {
            matrixHtml += `<th>${escapeHtml(color || '—')}</th>`;
        });
        matrixHtml += '</tr></thead><tbody>';

        group.sizes.forEach(size => {
            matrixHtml += `<tr><td class="size-label">${escapeHtml(size || '—')}</td>`;
            group.colors.forEach(color => {
                const item = group.lookup[size + '|' + color];
                if (!item) {
                    matrixHtml += '<td class="qty-cell no-product">—</td>';
                } else {
                    const isChecked = checks.has(item.product_id);
                    let cellClass = 'qty-cell';
                    if (item.current_qty === 0) cellClass += ' qty-zero';
                    else if (item.current_qty < item.low_stock_threshold) cellClass += ' qty-low';
                    if (isChecked) cellClass += ' cell-checked';

                    matrixHtml += `<td class="${cellClass}" data-product-id="${item.product_id}">
                        <input type="number" class="stock-input card-qty-input"
                               value="${item.current_qty}" min="0"
                               data-product-id="${item.product_id}"
                               data-original="${item.current_qty}"
                               onfocus="this.select()"
                               onchange="markUnsaved(); updateCardCellStyle(this)">
                        <button class="cell-check-btn${isChecked ? ' checked' : ''}"
                                onclick="toggleCheck(${item.product_id}, this)"
                                title="${isChecked ? 'Unmark as ordered' : 'Mark as ordered'}">✓</button>
                    </td>`;
                }
            });
            matrixHtml += '</tr>';
        });

        matrixHtml += '</tbody></table>';

        const wideClass = group.colors.length > 6 ? ' card-wide' : '';
        cardsHtml += `
            <div class="buying-card${wideClass}">
                <div class="buying-card-header">
                    <span>${title}</span>
                    ${alertBadge}
                </div>
                <div class="buying-card-body">
                    ${matrixHtml}
                </div>
            </div>`;
    });

    cardsHtml += '</div>';
    cardsWrapper.innerHTML = summaryHtml + cardsHtml;
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

// Close modal on overlay click
document.getElementById('note-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeNoteModal();
});

// Save note on Ctrl+Enter inside textarea
document.getElementById('note-modal-input').addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') saveNoteEntry();
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', function(e) {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<!-- Note Log Modal -->
<div class="note-modal-overlay" id="note-modal-overlay">
    <div class="note-modal">
        <div class="note-modal-header">
            <div>
                <h4><i class="fas fa-sticky-note"></i> Order Notes</h4>
                <div class="modal-subtitle" id="note-modal-product"></div>
            </div>
            <button class="note-modal-close" onclick="closeNoteModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="note-modal-log" id="note-modal-log">
            <p class="note-log-empty">No notes yet.</p>
        </div>
        <div class="note-modal-footer">
            <textarea id="note-modal-input" rows="2" placeholder="Add a note… (Ctrl+Enter to save)"></textarea>
            <button id="note-modal-save" class="btn-gradient btn-save-note" onclick="saveNoteEntry()">
                <i class="fas fa-plus"></i> Add
            </button>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
