<?php
$host = '127.0.0.1';
$db   = 'gb_scheduler';
$user = 'root';
$pass = 'every1000';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// $conn = new mysqli($localserver, $username, $password, $database);

// // Check connection!
// if ($conn->connect_error) {
//     die("Connection failed: " . $conn->connect_error);
// }
?>