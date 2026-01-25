<?php
session_start();
require '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$cid = $_POST['coach_id'] ?? '';
$pos = $_POST['position'] ?? 'head';

try {
    if ($action === 'create_assignment' || $action === 'reassign_assignment') {
        $tid = $_POST['template_id'];
        $date = $_POST['class_date'];

        // If reassign, delete old first
        if ($action === 'reassign_assignment') {
            $stmt = $pdo->prepare("DELETE FROM event_assignments WHERE user_id=? AND template_id=? AND class_date=?");
            $stmt->execute([$cid, $_POST['old_template_id'], $_POST['old_class_date']]);
        }

        // Get max sort order to append to bottom
        $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM event_assignments WHERE template_id=? AND class_date=?");
        $stmt->execute([$tid, $date]);
        $maxSort = $stmt->fetchColumn() ?: 0;
        $newSort = $maxSort + 1;

        $stmt = $pdo->prepare("INSERT INTO event_assignments (template_id, class_date, user_id, position, sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=?, position=?, sort_order=?");
        $stmt->execute([$tid, $date, $cid, $pos, $newSort, $cid, $pos, $newSort]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'delete_assignment') {
        $stmt = $pdo->prepare("DELETE FROM event_assignments WHERE user_id=? AND template_id=? AND class_date=?");
        $stmt->execute([$cid, $_POST['template_id'], $_POST['class_date']]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'bulk_assign_by_time') {
        $week_start = $_POST['week_start'];
        $time = $_POST['class_time'];
        $name = $_POST['class_name'];
        $loc = $_POST['location_id'];
        $art = $_POST['martial_art'];

        $sql = "SELECT id, day_of_week FROM class_templates WHERE start_time = ? AND class_name = ? AND location_id = ?";
        $params = [$time, $name, $loc];

        if ($art !== 'all') {
            $sql .= " AND martial_art = ?";
            $params[] = $art;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($templates)) {
            echo json_encode(['success' => false, 'message' => 'No matching classes found.']);
            exit;
        }

        $pdo->beginTransaction();
        $days_map = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];
        $start_ts = strtotime($week_start);
        $ins = $pdo->prepare("INSERT INTO event_assignments (template_id, class_date, user_id, position, sort_order) VALUES (?,?,?,?,100) ON DUPLICATE KEY UPDATE user_id=?, position=?");

        foreach ($templates as $t) {
            $dname = $t['day_of_week'];
            if (!isset($days_map[$dname])) continue;
            $offset = $days_map[$dname];
            $date = date('Y-m-d', strtotime("+$offset days", $start_ts));
            $ins->execute([$t['id'], $date, $cid, $pos, $cid, $pos]);
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    }

    // *** NEW: UPDATE ORDER ***
    elseif ($action === 'update_order') {
        $tid = $_POST['template_id'];
        $date = $_POST['class_date'];
        $order = $_POST['order'] ?? [];

        if (is_array($order) && count($order) > 0) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE event_assignments SET sort_order = ? WHERE user_id = ? AND template_id = ? AND class_date = ?");
            foreach ($order as $index => $userId) {
                $stmt->execute([$index + 1, $userId, $tid, $date]); // +1 so order starts at 1
            }
            $pdo->commit();
        }
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
