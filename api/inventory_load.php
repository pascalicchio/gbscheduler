<?php
session_start();
require '../db.php';
header('Content-Type: application/json');

// Check auth
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'counts':
            $locationId = $_GET['location_id'] ?? '';
            $categoryId = $_GET['category_id'] ?? '';
            $countDate = $_GET['count_date'] ?? date('Y-m-d');

            if (!$locationId) {
                echo json_encode(['success' => false, 'message' => 'Location required']);
                exit;
            }

            // Calculate previous week date
            $prevDate = date('Y-m-d', strtotime($countDate . ' -7 days'));

            // Fetch products
            $sql = "SELECT p.id, p.name, p.size, p.color, p.variant_type, p.low_stock_threshold,
                           c.name as category_name
                    FROM products p
                    JOIN product_categories c ON p.category_id = c.id
                    WHERE p.is_active = 1";
            $params = [];

            if ($categoryId) {
                $sql .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }

            $sql .= " ORDER BY c.sort_order, p.name, p.size";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch current counts
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM inventory_counts
                                   WHERE location_id = ? AND count_date = ?");
            $stmt->execute([$locationId, $countDate]);
            $counts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $counts[$row['product_id']] = (int)$row['quantity'];
            }

            // Fetch previous week counts
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM inventory_counts
                                   WHERE location_id = ? AND count_date = ?");
            $stmt->execute([$locationId, $prevDate]);
            $prevCounts = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prevCounts[$row['product_id']] = (int)$row['quantity'];
            }

            echo json_encode([
                'success' => true,
                'products' => $products,
                'counts' => $counts,
                'prev_counts' => $prevCounts
            ]);
            break;

        case 'trends':
            $locationId = $_GET['location_id'] ?? '';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            if (!$locationId) {
                echo json_encode(['success' => false, 'message' => 'Location required']);
                exit;
            }

            // Calculate estimated sales by comparing inventory drops
            // Get all counts in date range, ordered by date
            $sql = "SELECT ic.product_id, ic.count_date, ic.quantity,
                           p.name, p.size, p.color
                    FROM inventory_counts ic
                    JOIN products p ON ic.product_id = p.id
                    WHERE ic.location_id = ? AND ic.count_date BETWEEN ? AND ?
                    ORDER BY ic.product_id, ic.count_date";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$locationId, $fromDate, $toDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by product and calculate drops
            $productData = [];
            foreach ($rows as $row) {
                $pid = $row['product_id'];
                if (!isset($productData[$pid])) {
                    $productData[$pid] = [
                        'name' => $row['name'],
                        'size' => $row['size'],
                        'color' => $row['color'],
                        'counts' => []
                    ];
                }
                $productData[$pid]['counts'][$row['count_date']] = (int)$row['quantity'];
            }

            // Calculate estimated sales (drops in inventory)
            $trends = [];
            foreach ($productData as $pid => $data) {
                $counts = $data['counts'];
                ksort($counts);
                $dates = array_keys($counts);
                $totalSales = 0;

                for ($i = 1; $i < count($dates); $i++) {
                    $prev = $counts[$dates[$i - 1]];
                    $curr = $counts[$dates[$i]];
                    // Only count drops (sales), not restocks
                    if ($prev > $curr) {
                        $totalSales += ($prev - $curr);
                    }
                }

                if ($totalSales > 0) {
                    $trends[] = [
                        'product_id' => $pid,
                        'name' => $data['name'],
                        'size' => $data['size'],
                        'color' => $data['color'],
                        'estimated_sales' => $totalSales
                    ];
                }
            }

            // Sort by sales descending
            usort($trends, function($a, $b) {
                return $b['estimated_sales'] - $a['estimated_sales'];
            });

            // Limit to top 20
            $trends = array_slice($trends, 0, 20);

            echo json_encode([
                'success' => true,
                'trends' => $trends
            ]);
            break;

        case 'orders':
            $sql = "SELECT o.*, p.name as product_name, p.size as product_size, p.color as product_color,
                           l.name as location_name, c.sort_order as category_sort_order, c.name as category_name
                    FROM order_requests o
                    LEFT JOIN products p ON o.product_id = p.id
                    LEFT JOIN product_categories c ON p.category_id = c.id
                    JOIN locations l ON o.location_id = l.id
                    WHERE o.is_active = 1
                    ORDER BY o.created_at DESC";

            $stmt = $pdo->query($sql);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get pending count for badge
            $pendingStmt = $pdo->query("SELECT COUNT(*) FROM order_requests WHERE status = 'pending' AND is_active = 1");
            $pendingCount = (int)$pendingStmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'orders' => $orders,
                'pending_count' => $pendingCount
            ]);
            break;

        case 'products':
            $categoryId = $_GET['category_id'] ?? '';

            $sql = "SELECT p.*, c.name as category_name
                    FROM products p
                    JOIN product_categories c ON p.category_id = c.id
                    WHERE p.is_active = 1";
            $params = [];

            if ($categoryId) {
                $sql .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }

            $sql .= " ORDER BY c.sort_order, p.name, p.size";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'products' => $products
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
