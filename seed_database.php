<?php
// seed_database.php
// SAFETY: ONLY RUN ON LOCALHOST
$allowed_hosts = ['localhost', '127.0.0.1', 'gb-scheduler2.test'];
$is_local = false;

foreach ($allowed_hosts as $host) {
    if (strpos($_SERVER['HTTP_HOST'], $host) !== false) {
        $is_local = true;
        break;
    }
}

if (!$is_local) {
    die("<h1 style='color:red; text-align:center;'>⛔ ACCESS DENIED ⛔</h1><p style='text-align:center;'>This script can only be run in a local development environment.</p>");
}

require 'db.php';

try {
    // --- 1. CLEAN DATABASE ---
    echo "Cleaning database...<br>";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['users', 'locations', 'class_templates', 'user_locations', 'private_classes', 'private_rates', 'event_assignments'];
    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE $table");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // --- 2. CREATE LOCATIONS ---
    echo "Creating locations...<br>";
    $pdo->exec("INSERT INTO locations (id, name) VALUES (1, 'Davenport'), (2, 'Celebration')");
    $loc_dav = 1;
    $loc_cel = 2;

    // --- 3. CREATE ADMIN & MANAGER ---
    echo "Creating Admin and Manager...<br>";
    $pass_hash = password_hash('123', PASSWORD_DEFAULT);
    
    // Admin
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, coach_type, color_code) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['Fillipe Pascalicchio', 'pascalicchio@gmail.com', $pass_hash, 'admin', 'bjj', '#000000']);
    
    // Manager
    $stmt->execute(['Manager Info', 'info@gbdavenport.com', $pass_hash, 'manager', 'bjj', '#cccccc']);

    // --- 4. CREATE COACHES ---
    echo "Creating Coaches...<br>";
    
    $coaches = [];
    
    // Helper to create user
    function createCoach($pdo, $name, $type, $color, $locs, $pass_hash) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, coach_type, color_code) VALUES (?, ?, ?, 'user', ?, ?)");
        // Fake email based on name
        $email = strtolower(str_replace(' ', '.', $name)) . '@test.com';
        $stmt->execute([$name, $email, $pass_hash, $type, $color]);
        $uid = $pdo->lastInsertId();
        
        // Assign Locations
        foreach($locs as $lid) {
            $pdo->prepare("INSERT INTO user_locations (user_id, location_id) VALUES (?, ?)")->execute([$uid, $lid]);
            // Default rates
            $pdo->prepare("INSERT INTO private_rates (user_id, location_id, rate, discount_percent) VALUES (?, ?, 100.00, 0)")->execute([$uid, $lid]);
        }
    }

    // A. BJJ COACHES
    // 2 Shared (Davenport + Celebration)
    createCoach($pdo, 'BJJ Shared 1', 'bjj', '#e74c3c', [$loc_dav, $loc_cel], $pass_hash);
    createCoach($pdo, 'BJJ Shared 2', 'bjj', '#e67e22', [$loc_dav, $loc_cel], $pass_hash);

    // 4 Unique to Davenport (Total 6 BJJ in Dav)
    createCoach($pdo, 'BJJ Dav Only 1', 'bjj', '#f1c40f', [$loc_dav], $pass_hash);
    createCoach($pdo, 'BJJ Dav Only 2', 'bjj', '#2ecc71', [$loc_dav], $pass_hash);
    createCoach($pdo, 'BJJ Dav Only 3', 'bjj', '#1abc9c', [$loc_dav], $pass_hash);
    createCoach($pdo, 'BJJ Dav Only 4', 'bjj', '#3498db', [$loc_dav], $pass_hash);

    // 2 Unique to Celebration
    createCoach($pdo, 'BJJ Cel Only 1', 'bjj', '#9b59b6', [$loc_cel], $pass_hash);
    createCoach($pdo, 'BJJ Cel Only 2', 'bjj', '#8e44ad', [$loc_cel], $pass_hash);

    // B. MT COACHES
    // 2 Shared (Davenport + Celebration)
    createCoach($pdo, 'MT Shared 1', 'mt', '#34495e', [$loc_dav, $loc_cel], $pass_hash);
    createCoach($pdo, 'MT Shared 2', 'mt', '#2c3e50', [$loc_dav, $loc_cel], $pass_hash);

    // 1 Unique to Celebration
    createCoach($pdo, 'MT Cel Only 1', 'mt', '#7f8c8d', [$loc_cel], $pass_hash);

    // --- 5. CREATE CLASSES ---
    echo "Creating Classes...<br>";

    $stmt_class = $pdo->prepare("INSERT INTO class_templates (class_name, day_of_week, start_time, end_time, location_id, martial_art) VALUES (?, ?, ?, ?, ?, ?)");

    function addClass($stmt, $name, $days, $start, $end, $locs, $type) {
        foreach ($locs as $lid) {
            foreach ($days as $day) {
                $stmt->execute([$name, $day, $start, $end, $lid, $type]);
            }
        }
    }

    $mon_thu = ['Monday', 'Tuesday', 'Wednesday', 'Thursday'];
    $mon_fri = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $sat = ['Saturday'];
    $tue_thu = ['Tuesday', 'Thursday'];
    $mon_wed = ['Monday', 'Wednesday'];

    // === SHARED CLASSES (Both Locations) ===
    // 12PM - 1PM
    addClass($stmt_class, 'Lunch BJJ', $mon_thu, '12:00:00', '13:00:00', [$loc_dav, $loc_cel], 'bjj');
    // 6:30PM - 7:30PM
    addClass($stmt_class, 'Evening BJJ 1', $mon_thu, '18:30:00', '19:30:00', [$loc_dav, $loc_cel], 'bjj');
    // 7:30PM - 8:30PM
    addClass($stmt_class, 'Evening BJJ 2', $mon_thu, '19:30:00', '20:30:00', [$loc_dav, $loc_cel], 'bjj');
    
    // Little Champions (4:50 - 5:40)
    addClass($stmt_class, 'Little Champions', $mon_fri, '16:50:00', '17:40:00', [$loc_dav, $loc_cel], 'bjj');
    // Juniors (5:40 - 6:30)
    addClass($stmt_class, 'Juniors', $mon_fri, '17:40:00', '18:30:00', [$loc_dav, $loc_cel], 'bjj');
    
    // Saturday
    addClass($stmt_class, 'All Kids', $sat, '10:30:00', '12:00:00', [$loc_dav, $loc_cel], 'bjj');
    addClass($stmt_class, 'Teens & Adults', $sat, '12:00:00', '13:30:00', [$loc_dav, $loc_cel], 'bjj');

    // === DAVENPORT ONLY ===
    // 6AM - 7AM (Assumed 7AM based on "6AM to 7PM" typo context for BJJ morning)
    addClass($stmt_class, 'Teens and Adults', $tue_thu, '06:00:00', '07:00:00', [$loc_dav], 'bjj');
    
    // Homeschooling Mon/Wed 10:30 - 11:30
    addClass($stmt_class, 'Homeschooling', $mon_wed, '10:30:00', '11:30:00', [$loc_dav], 'bjj');
    // Homeschooling Tue/Thu 2PM (Assumed 1h duration)
    addClass($stmt_class, 'Homeschooling', $tue_thu, '14:00:00', '15:00:00', [$loc_dav], 'bjj');

    // Muay Thai (Mon-Fri: 5:30, 6:30, 7:30)
    addClass($stmt_class, 'Muay Thai', $mon_fri, '17:30:00', '18:30:00', [$loc_dav], 'mt');
    addClass($stmt_class, 'Muay Thai', $mon_fri, '18:30:00', '19:30:00', [$loc_dav], 'mt');
    addClass($stmt_class, 'Muay Thai', $mon_fri, '19:30:00', '20:30:00', [$loc_dav], 'mt');

    // === CELEBRATION ONLY ===
    // After School Class (Mon-Fri 4PM - Assumed 1h)
    addClass($stmt_class, 'After School Class', $mon_fri, '16:00:00', '17:00:00', [$loc_cel], 'bjj');

    echo "<hr><h2 style='color:green'>✅ Database seeded successfully!</h2>";
    echo "<a href='dashboard.php'>Go to Dashboard</a>";

} catch (PDOException $e) {
    echo "<h2 style='color:red'>Error: " . $e->getMessage() . "</h2>";
}
?>