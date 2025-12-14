<?php
// api/update_assignment.php - FINALIZED WITH REASSIGNMENT LOGIC

require '../db.php';
session_start();

// Security check: Only administrators should be able to modify assignments
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied.");
}

// Ensure required variables for any action are present
if (!isset($_POST['action']) || !isset($_POST['coach_id']) || !isset($_POST['event_id'])) {
    http_response_code(400); // Bad Request
    die("Missing required parameters (action, coach_id, or event_id).");
}

$action = $_POST['action'];
$coach_id = intval($_POST['coach_id']);
$event_id = intval($_POST['event_id']); // This is the TARGET event ID

if ($coach_id <= 0 || $event_id <= 0) {
    http_response_code(400);
    die("Invalid coach ID or event ID received.");
}


// ===================================
// 1. CREATE ASSIGNMENT LOGIC (DRAG/DROP FROM SIDEBAR)
// ===================================
if ($action == 'create_assignment') {
    $position = $_POST['position'] ?? null;

    // Validate position input
    if ($position !== 'head' && $position !== 'helper') {
        http_response_code(400);
        die("Invalid position submitted.");
    }

    $pdo->beginTransaction();

    try {
        // 1. Check for Duplicates
        $stmt_check = $pdo->prepare("SELECT id FROM event_assignments WHERE event_id = ? AND user_id = ?");
        $stmt_check->execute([$event_id, $coach_id]);
        if ($stmt_check->rowCount() > 0) {
            $pdo->rollBack();
            http_response_code(409);
            die("Error: Coach already assigned to this class.");
        }

        // 2. Insert Assignment with the selected position
        $sql = "INSERT INTO event_assignments (event_id, user_id, position) VALUES (?, ?, ?)";
        $stmt_insert = $pdo->prepare($sql);
        $stmt_insert->execute([$event_id, $coach_id, $position]);

        $pdo->commit();

        echo "Coach assigned as " . $position . " to Event ID: " . $event_id;
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        die("Database INSERT failed. Error: " . $e->getMessage());
    }
}
// ===================================
// 2. DELETE ASSIGNMENT LOGIC (CLICK X)
// ===================================
elseif ($action === 'delete_assignment') {

    try {
        // Delete the record matching both user_id (coach) and event_id (class slot)
        $stmt = $pdo->prepare("DELETE FROM event_assignments WHERE user_id = :coach_id AND event_id = :event_id LIMIT 1");
        $stmt->execute([
            'coach_id' => $coach_id,
            'event_id' => $event_id
        ]);

        if ($stmt->rowCount() > 0) {
            echo "Assignment deleted successfully.";
        } else {
            http_response_code(404);
            echo "No matching assignment found to delete.";
        }
    } catch (PDOException $e) {
        http_response_code(500);
        die("Database DELETE failed. Error: " . $e->getMessage());
    }
}
// ===================================
// 3. REASSIGNMENT LOGIC (DRAG/DROP BETWEEN SLOTS)
// ===================================
elseif ($action === 'reassign_assignment') {
    $old_event_id = intval($_POST['old_event_id'] ?? 0);
    $position = $_POST['position'] ?? null;

    if ($old_event_id <= 0 || !$position) {
        http_response_code(400);
        die("Missing required parameters for re-assignment (old_event_id or position).");
    }

    $pdo->beginTransaction();

    try {
        // A. DELETE the old assignment
        $stmt_delete = $pdo->prepare("DELETE FROM event_assignments WHERE user_id = :coach_id AND event_id = :old_event_id LIMIT 1");
        $stmt_delete->execute([
            'coach_id' => $coach_id,
            'old_event_id' => $old_event_id
        ]);

        if ($stmt_delete->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            die("Error: Original assignment not found to delete.");
        }

        // B. INSERT the new assignment
        $stmt_insert = $pdo->prepare("INSERT INTO event_assignments (event_id, user_id, position) VALUES (?, ?, ?)");
        $stmt_insert->execute([$event_id, $coach_id, $position]);

        $pdo->commit();

        echo "Coach ID {$coach_id} successfully moved from Event ID {$old_event_id} to Event ID {$event_id}.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        die("Database REASSIGNMENT failed. Error: " . $e->getMessage());
    }
}
// ===================================
// 4. INVALID ACTION
// ===================================
else {
    http_response_code(400);
    die("Invalid action specified.");
}
