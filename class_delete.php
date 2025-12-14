<?php
// class_delete.php - SIMPLIFIED: ONLY DELETES THE MASTER CLASS TEMPLATE
session_start();
require 'db.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {

    $class_id = (int)$_GET['id'];

    try {
        // --- ONLY DELETE THE MASTER TEMPLATE ---
        $sql_delete_template = "DELETE FROM class_templates WHERE id = ?";
        $stmt_template = $pdo->prepare($sql_delete_template);
        $stmt_template->execute([$class_id]);

        if ($stmt_template->rowCount() > 0) {
            $_SESSION['message'] = "Master class template successfully removed. Note: Future generated classes remain on the calendar.";
        } else {
            $_SESSION['error'] = "Error: No master class found with that ID.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: Could not delete master class template.";
        // In a real application, you would log $e->getMessage()
    }
} else {
    $_SESSION['error'] = "Error: Invalid class ID provided.";
}

// Redirect back to the main class list page
header("Location: classes.php");
exit();
