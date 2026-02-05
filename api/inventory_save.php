<?php
session_start();
require '../db.php';
header('Content-Type: application/json');

// Check auth
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Handle JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Handle form data if not JSON
    if (!$input) {
        $input = $_POST;
        $input['action'] = $_POST['action'] ?? '';
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'save_counts':
            $locationId = $input['location_id'] ?? '';
            $countDate = $input['count_date'] ?? '';
            $counts = $input['counts'] ?? [];

            if (!$locationId || !$countDate) {
                echo json_encode(['success' => false, 'message' => 'Location and date required']);
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO inventory_counts (product_id, location_id, count_date, quantity, created_by)
                                   VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()");

            foreach ($counts as $productId => $quantity) {
                $stmt->execute([$productId, $locationId, $countDate, (int)$quantity, $userId]);
            }

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Inventory saved']);
            break;

        case 'create':
            // Create new product (admin only)
            if ($_SESSION['user_role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin only']);
                exit;
            }

            $categoryId = $input['category_id'] ?? '';
            $name = trim($input['name'] ?? '');
            $sku = trim($input['sku'] ?? '') ?: null;
            $size = trim($input['size'] ?? '') ?: null;
            $color = trim($input['color'] ?? '') ?: null;
            $variantType = $input['variant_type'] ?? 'standard';
            $threshold = (int)($input['low_stock_threshold'] ?? 8);

            if (!$categoryId || !$name) {
                echo json_encode(['success' => false, 'message' => 'Category and name required']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO products (category_id, name, sku, size, color, variant_type, low_stock_threshold, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$categoryId, $name, $sku, $size, $color, $variantType, $threshold, $userId]);

            echo json_encode(['success' => true, 'product_id' => $pdo->lastInsertId()]);
            break;

        case 'update_threshold':
            // Update product threshold (admin only)
            if ($_SESSION['user_role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin only']);
                exit;
            }

            $productId = $input['product_id'] ?? '';
            $threshold = (int)($input['threshold'] ?? 8);

            if (!$productId || $threshold < 1) {
                echo json_encode(['success' => false, 'message' => 'Invalid product or threshold']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE products SET low_stock_threshold = ? WHERE id = ?");
            $stmt->execute([$threshold, $productId]);

            echo json_encode(['success' => true]);
            break;

        case 'toggle_product':
            // Deactivate/activate product (admin only)
            if ($_SESSION['user_role'] !== 'admin') {
                echo json_encode(['success' => false, 'message' => 'Admin only']);
                exit;
            }

            $productId = $input['product_id'] ?? '';
            $isActive = (int)($input['is_active'] ?? 0);

            if (!$productId) {
                echo json_encode(['success' => false, 'message' => 'Product ID required']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE products SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $productId]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
