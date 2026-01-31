<?php
// api/load_coaches.php - FIXED TO INCLUDE BOTH 'user' AND 'admin' ROLES
require '../db.php';
session_start();
// Security check: Only administrators should be able to drag and drop
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

$location_id = $_GET['location_id'] ?? '0'; // Default to '0' for all locations
// *** FIX: Filter by role to include both 'user' (coach) and 'admin' ***
$martial_art_filter = $_GET['martial_art'] ?? 'all';

// We want to fetch users whose role is 'user' OR 'admin'
$sql = "SELECT id, name, color_code FROM users WHERE role IN ('user', 'admin') ";
$params = [];

// Apply location filter (if not '0')
if ($location_id !== '0') {
    $sql .= " AND location = :location_id";
    $params['location_id'] = $location_id;
}

// *** Martial Art Filter ***
// Filter coaches based on their coach_type (SET column with 'bjj', 'mt', 'mma')
if ($martial_art_filter === 'bjj') {
    $sql .= " AND FIND_IN_SET('bjj', coach_type) > 0 ";
} elseif ($martial_art_filter === 'mt') {
    $sql .= " AND FIND_IN_SET('mt', coach_type) > 0 ";
} elseif ($martial_art_filter === 'mma') {
    $sql .= " AND FIND_IN_SET('mma', coach_type) > 0 ";
}

$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($coaches);
