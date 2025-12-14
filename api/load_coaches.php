<?php
// api/load_coaches.php
require '../db.php';
session_start();
// Security check: Only administrators should be able to drag and drop
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

$location_id = $_GET['location_id'] ?? '0'; // Default to '0' for all locations

$sql = "SELECT id, name, color_code FROM users WHERE 1=1 ";
$params = [];

// Filter if a specific location ID is provided (and it's not '0')
// The users.location column stores the location ID as a string.
if ($location_id !== '0') {
    $sql .= " AND location = :location_id";
    $params['location_id'] = $location_id;
}

$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$coaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($coaches);
?>