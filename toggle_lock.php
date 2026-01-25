<?php
// toggle_lock.php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$location_id = $input['location_id'];
$martial_art = $input['type'];
$week_start  = $input['week_start'];
$action      = $input['action']; // 'lock' or 'unlock'

$is_locked = ($action === 'lock') ? 1 : 0;

try {
    // Insert or Update the lock record
    $sql = "INSERT INTO schedule_locks (location_id, martial_art, week_start, is_locked, locked_by) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_locked = VALUES(is_locked), locked_by = VALUES(locked_by)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$location_id, $martial_art, $week_start, $is_locked, $_SESSION['user_id']]);

    echo json_encode(['success' => true, 'is_locked' => $is_locked]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}