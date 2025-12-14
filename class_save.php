<?php
// class_save.php - UPDATED TO HANDLE MULTIPLE DAYS

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Ensure days_of_week is an array, which it should be from the multiple select
    if (!isset($_POST['days_of_week']) || !is_array($_POST['days_of_week'])) {
        // Handle error: No days were selected or the input was malformed
        echo "Error: Please select at least one day of the week.";
        exit;
    }

    // Capture variables that are constant for all templates
    $location_id = $_POST['location_id'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $martial_art = $_POST['martial_art'];
    $class_name = $_POST['class_name'];
    $days_to_save = $_POST['days_of_week']; // This is the array of days

    // Prepare the SQL statement once outside the loop
    $sql = "INSERT INTO class_templates (location_id, day_of_week, start_time, end_time, martial_art, class_name) 
            VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $templates_saved = 0;

    // Begin transaction for efficiency and atomicity
    $pdo->beginTransaction();

    try {
        // Loop through each selected day and execute the insert
        foreach ($days_to_save as $day) {
            $stmt->execute([
                $location_id,
                $day, // Use the current day from the loop
                $start_time,
                $end_time,
                $martial_art,
                $class_name
            ]);
            $templates_saved++;
        }

        // Commit all changes if the loop completed successfully
        $pdo->commit();

        // Redirect back to the classes management page
        header("Location: classes.php?success=" . $templates_saved);
        exit();
    } catch (PDOException $e) {
        // If an error occurs, roll back all attempted insertions
        $pdo->rollBack();

        // You may want to log the error instead of displaying it in production
        http_response_code(500);
        die("Database error saving class templates: " . $e->getMessage());
    }
} else {
    // Not a POST request
    header("Location: classes.php");
    exit;
}
