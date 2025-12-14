<?php
// user_save.php
require 'db.php';

// Retrieve form data
$id = $_POST['id'];
$name = $_POST['name'];
$email = $_POST['email'];
$location = $_POST['location'];
$password = $_POST['password'];
$rate_head = $_POST['rate_head_coach'];
$rate_helper = $_POST['rate_helper'];
$coach_type = $_POST['coach_type'];
$role = $_POST['role'];
$color = $_POST['color_code'];

try {
    if (!empty($id)) {
        // --- UPDATE EXISTING USER ---
        
        // Only update password if the user typed a new one
        if (!empty($password)) {
            $sql = "UPDATE users SET name=?, email=?, location=?, password=?, rate_head_coach=?, rate_helper=?, coach_type=?, role=?, color_code=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            // Hash the new password
            $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
            $stmt->execute([$name, $email, $location, $hashed_pass, $rate_head, $rate_helper, $coach_type, $role, $color, $id]);
        } else {
            // Keep old password
            $sql = "UPDATE users SET name=?, email=?, location=?, rate_head_coach=?, rate_helper=?, coach_type=?, role=?, color_code=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $email, $location, $rate_head, $rate_helper, $coach_type, $role, $color, $id]);
        }

    } else {
        // --- INSERT NEW USER ---
        
        $sql = "INSERT INTO users (name, email, location, password, rate_head_coach, rate_helper, coach_type, role, color_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        // Hash the password for security
        $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$name, $email, $location, $hashed_pass, $rate_head, $rate_helper, $coach_type, $role, $color]);
    }

    // Redirect back to list
    header("Location: users.php");
    exit();

} catch (PDOException $e) {
    echo "Error saving user: " . $e->getMessage();
}
?>