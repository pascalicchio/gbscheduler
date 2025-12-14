<?php
session_start();
// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

require '../db.php';
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

// Global debug log array
$debug_log = [];
function log_debug($message)
{
    global $debug_log;
    $debug_log[] = $message;
}

// --- DATABASE CLONING FUNCTION ---
function clone_classes_and_assignments($pdo, $source_start_date, $source_end_date, $target_start_date)
{
    global $debug_log;
    $cloned_count = 0;
    $assigned_count = 0; // Track assignments, even if event was skipped
    $pdo->beginTransaction();

    // Prepare simple date strings for SQL
    $source_start_date_only = $source_start_date;
    $source_end_date_only = $source_end_date;

    log_debug("Source Week Calculated: $source_start_date_only to $source_end_date_only");

    try {
        // 1. SELECT all events from the source week
        $stmt_select = $pdo->prepare("
            SELECT id, title, location_id, martial_art, start_datetime, end_datetime 
            FROM schedule_events 
            WHERE DATE(start_datetime) BETWEEN :start_date AND :end_date
        ");

        $stmt_select->execute([
            'start_date' => $source_start_date_only,
            'end_date' => $source_end_date_only
        ]);
        $source_events = $stmt_select->fetchAll(PDO::FETCH_ASSOC);

        log_debug("SQL executed. Found " . count($source_events) . " events in the source week.");

        if (empty($source_events)) {
            $pdo->rollBack();
            return "No events found in the source week ($source_start_date to $source_end_date).";
        }

        // Calculate the difference in days (should be 7)
        $date1 = new DateTime($source_start_date);
        $date2 = new DateTime($target_start_date);
        $day_difference = $date1->diff($date2)->days;

        log_debug("Day difference between weeks: $day_difference days.");

        if ($day_difference !== 7) {
            throw new Exception("Date difference is not 7 days, aborting.");
        }


        // 2. Loop through each event, clone it, and clone its assignments
        foreach ($source_events as $event) {
            $original_event_id = $event['id'];

            // Calculate new datetime values by adding 7 days
            $new_start_datetime = date('Y-m-d H:i:s', strtotime($event['start_datetime'] . " +{$day_difference} days"));
            $new_end_datetime = date('Y-m-d H:i:s', strtotime($event['end_datetime'] . " +{$day_difference} days"));

            log_debug("Processing Original Event ID $original_event_id. New target time: $new_start_datetime");

            // --- CHECK FOR EXISTING TARGET EVENT ---
            $stmt_check = $pdo->prepare("
                SELECT id FROM schedule_events WHERE location_id = :location_id AND start_datetime = :new_start_datetime
            ");
            $stmt_check->execute([
                'location_id' => $event['location_id'],
                'new_start_datetime' => $new_start_datetime
            ]);
            $existing_target_event = $stmt_check->fetch(PDO::FETCH_ASSOC);

            $new_event_id = null;
            $was_skipped = false;

            if ($existing_target_event) {
                // CASE A: Target event already exists (IDs 5 and 6)
                $new_event_id = $existing_target_event['id'];
                $was_skipped = true;
                log_debug("Target event ID $new_event_id already exists. Skipping event creation.");
            } else {
                // CASE B: Target event does NOT exist (normal cloning)
                // Insert the new schedule_event record
                $stmt_insert_event = $pdo->prepare("
                    INSERT INTO schedule_events 
                    (title, location_id, start_datetime, end_datetime, martial_art) 
                    VALUES (:title, :location_id, :start_datetime, :end_datetime, :martial_art)
                ");
                $stmt_insert_event->execute([
                    'title' => $event['title'],
                    'location_id' => $event['location_id'],
                    'start_datetime' => $new_start_datetime,
                    'end_datetime' => $new_end_datetime,
                    'martial_art' => $event['martial_art'],
                ]);
                $new_event_id = $pdo->lastInsertId();

                if (!$new_event_id) {
                    throw new Exception("Failed to insert new event.");
                }
                $cloned_count++;
                log_debug("New event inserted with ID: $new_event_id");
            }

            // --- 2b. Select and clone the event_assignments ---
            // This runs regardless of whether the event was skipped or newly created.
            if ($new_event_id) {

                // 2b-i. Check if assignments already exist for the new/existing event
                $stmt_check_assignments = $pdo->prepare("
                    SELECT COUNT(*) FROM event_assignments WHERE event_id = :new_event_id
                ");
                $stmt_check_assignments->execute(['new_event_id' => $new_event_id]);

                if ($stmt_check_assignments->fetchColumn() > 0) {
                    log_debug("Assignments for target event ID $new_event_id already exist. Skipping assignment cloning.");
                    continue; // Skip the rest of the loop for this event
                }


                // 2b-ii. Fetch assignments from the source event
                $stmt_select_assignments = $pdo->prepare("
                    SELECT user_id, position FROM event_assignments WHERE event_id = :original_id
                ");
                $stmt_select_assignments->execute(['original_id' => $original_event_id]);
                $assignments = $stmt_select_assignments->fetchAll(PDO::FETCH_ASSOC);

                log_debug("Found " . count($assignments) . " assignments for source event ID $original_event_id.");

                if (!empty($assignments)) {
                    $stmt_insert_assignment = $pdo->prepare("
                        INSERT INTO event_assignments (event_id, user_id, position) 
                        VALUES (:new_event_id, :user_id, :position)
                    ");
                    foreach ($assignments as $assignment) {
                        $stmt_insert_assignment->execute([
                            'new_event_id' => $new_event_id,
                            'user_id' => $assignment['user_id'],
                            'position' => $assignment['position']
                        ]);
                        $assigned_count++;
                    }
                    log_debug("Cloned $assigned_count assignments successfully to event ID $new_event_id.");
                }
            }
        }

        $pdo->commit();
        // Return the count of *newly* created assignments, so the user knows work was done
        return $assigned_count;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_info = $pdo->errorInfo();
        error_log("Schedule cloning failed - SQL Error: " . $error_info[2] ?? $e->getMessage());
        return "SQL Error: " . ($error_info[2] ?? $e->getMessage());
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Schedule cloning failed - Logic Error: " . $e->getMessage());
        return "Logic Error: " . $e->getMessage();
    }
}

// --- EXECUTION ---

// ... (rest of the execution script remains the same) ...

// 1. Get the source date from the JSON request body
$data = json_decode(file_get_contents('php://input'), true);
$source_start_date_str = $data['sourceWeekStart'] ?? null;

log_debug("Received sourceWeekStart from front-end: $source_start_date_str");

if (!$source_start_date_str) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing source week start date.', 'debug' => $debug_log]);
    exit();
}

try {
    // 2. Calculate the source and target dates
    $source_start = new DateTime($source_start_date_str);
    $source_end = clone $source_start;
    $source_end->modify('+6 days');

    $target_start = clone $source_start;
    $target_start->modify('+7 days');

    $result = clone_classes_and_assignments(
        $pdo,
        $source_start->format('Y-m-d'),
        $source_end->format('Y-m-d'),
        $target_start->format('Y-m-d')
    );

    // Check if the result is an error string (either SQL error or the "No events found" message)
    if (is_string($result)) {
        // If no events were found, we send a 200/OK response, but with the warning message.
        if (strpos($result, 'No events found') !== false) {
            http_response_code(200); // Success, but nothing was cloned
            echo json_encode(['success' => true, 'clonedCount' => 0, 'message' => $result, 'debug' => $debug_log]);
        } else {
            // Actual SQL error
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $result, 'debug' => $debug_log]);
        }
    } else {
        http_response_code(200);
        // Change the success message to reflect the assignments were cloned
        $message = ($result > 0) ? "Cloned $result assignments successfully." : "Cloned 0 assignments (target week was already assigned).";
        echo json_encode(['success' => true, 'clonedCount' => $result, 'message' => $message, 'debug' => $debug_log]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error processing dates: ' . $e->getMessage(), 'debug' => $debug_log]);
}
