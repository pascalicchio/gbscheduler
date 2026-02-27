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
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $memberName = trim($_POST['member_name'] ?? '');
            $locationId = $_POST['location_id'] ?? '';
            $productId = $_POST['product_id'] ?: null;
            $productDescription = trim($_POST['product_description'] ?? '') ?: null;
            $sizeRequested = trim($_POST['size_requested'] ?? '') ?: null;
            $colorRequested = trim($_POST['color_requested'] ?? '') ?: null;
            $quantity = (int)($_POST['quantity'] ?? 1);
            $notes = trim($_POST['notes'] ?? '') ?: null;

            if (!$memberName || !$locationId) {
                echo json_encode(['success' => false, 'message' => 'Member name and location required']);
                exit;
            }

            if (!$productId && !$productDescription) {
                echo json_encode(['success' => false, 'message' => 'Select a product or enter a description']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO order_requests
                                   (product_id, location_id, member_name, product_description, size_requested, color_requested, quantity, notes, created_by)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $locationId, $memberName, $productDescription, $sizeRequested, $colorRequested, $quantity, $notes, $userId]);

            echo json_encode(['success' => true, 'order_id' => $pdo->lastInsertId()]);
            break;

        case 'update_status':
            $orderId = $_POST['order_id'] ?? '';
            $status = $_POST['status'] ?? '';

            $validStatuses = ['pending', 'ordered', 'received', 'completed', 'cancelled'];
            if (!$orderId || !in_array($status, $validStatuses)) {
                echo json_encode(['success' => false, 'message' => 'Invalid order or status']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE order_requests SET status = ? WHERE id = ?");
            $stmt->execute([$status, $orderId]);

            echo json_encode(['success' => true]);
            break;

        case 'update_note':
            $orderId = $_POST['order_id'] ?? '';
            $note = trim($_POST['note'] ?? '') ?: null;

            if (!$orderId) {
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE order_requests SET notes = ? WHERE id = ?");
            $stmt->execute([$note, $orderId]);

            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $orderId = $_POST['order_id'] ?? '';

            if (!$orderId) {
                echo json_encode(['success' => false, 'message' => 'Order ID required']);
                exit;
            }

            // Soft delete
            $stmt = $pdo->prepare("UPDATE order_requests SET is_active = 0 WHERE id = ?");
            $stmt->execute([$orderId]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
