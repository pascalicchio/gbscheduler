<?php
// api/clone_classes.php
session_start();
require '../db.php';

header('Content-Type: application/json');

// 1. Security Check: Only Admins can clone
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// 2. Get Input Data (JSON)
$input = json_decode(file_get_contents('php://input'), true);
$sourceWeekStart = $input['sourceWeekStart'] ?? null;

if (!$sourceWeekStart) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing source week start date.']);
    exit();
}

try {
    // 3. Calculate Date Ranges
    // We assume the source week is Monday to Sunday
    $sourceStartObj = new DateTime($sourceWeekStart);
    $sourceEndObj = clone $sourceStartObj;
    $sourceEndObj->modify('+6 days'); // Monday + 6 days = Sunday

    $sStart = $sourceStartObj->format('Y-m-d');
    $sEnd = $sourceEndObj->format('Y-m-d');

    // 4. Perform the Clone using INSERT ... SELECT
    // This query finds all assignments in the source week, adds 7 days to their date,
    // and inserts them into the table.
    // The "AND NOT EXISTS" clause prevents creating duplicate entries if you run this twice.

    $sql = "
        INSERT INTO event_assignments (template_id, class_date, user_id, position)
        SELECT 
            ea.template_id, 
            DATE_ADD(ea.class_date, INTERVAL 7 DAY) as new_date,
            ea.user_id, 
            ea.position
        FROM event_assignments ea
        WHERE ea.class_date BETWEEN :sStart AND :sEnd
        AND NOT EXISTS (
            SELECT 1 FROM event_assignments ea2 
            WHERE ea2.template_id = ea.template_id 
            AND ea2.class_date = DATE_ADD(ea.class_date, INTERVAL 7 DAY)
            AND ea2.user_id = ea.user_id
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sStart' => $sStart, 'sEnd' => $sEnd]);

    $count = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => "Successfully cloned $count assignments to the next week.",
        'clonedCount' => $count,
        'debug' => [] // Empty array to satisfy frontend check
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
