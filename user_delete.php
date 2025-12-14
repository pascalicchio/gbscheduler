<?php
// user_delete.php
require 'db.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: users.php");
    } catch (PDOException $e) {
        // This might fail if the user is already assigned to classes (Foreign Key Constraint)
        // You generally shouldn't delete a coach who has taught classes, or the history will break.
        die("Cannot delete this user. They might be assigned to classes. Error: " . $e->getMessage());
    }
}
?>