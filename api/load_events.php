<?php
// api/load_events.php

require '../db.php';
date_default_timezone_set('America/New_York'); // Set your timezone

// --- 1. GET FILTER PARAMETERS ---
$start_str = $_GET['start'] ?? date('Y-m-d');
$end_str = $_GET['end'] ?? date('Y-m-d', strtotime('+1 week'));

// The 'location' parameter now directly contains the location ID OR '0' (for All Locations)
$filter_location_id = $_GET['location'] ?? '0';

// --- 2. BUILD THE BASE QUERY (GRAY SLOTS) ---
// Join schedule_events (se) with locations (l) using location_id

$base_sql = "
    SELECT 
        se.id AS event_id,
        se.title, 
        se.start_datetime,
        se.end_datetime,
        l.name AS location_name
    FROM 
        schedule_events se
    JOIN 
        locations l ON se.location_id = l.id
    WHERE 
        se.start_datetime BETWEEN :start AND :end
";

$base_params = [
    'start' => $start_str,
    'end' => $end_str
];

// Apply the location filter if a specific ID is selected (not '0')
if ($filter_location_id !== '0') {
    $base_sql .= " AND se.location_id = :location_id ";
    $base_params['location_id'] = $filter_location_id;
}

$stmt_base = $pdo->prepare($base_sql);
$stmt_base->execute($base_params);
$base_events = $stmt_base->fetchAll(PDO::FETCH_ASSOC);


// --- 3. BUILD THE ASSIGNMENT QUERY (COACHES) ---
$event_ids = array_column($base_events, 'event_id');
$events_data = [];

if (!empty($event_ids)) {
    $placeholders = implode(',', array_fill(0, count($event_ids), '?'));

    $assignment_sql = "
        SELECT 
            ea.event_id,
            u.name,
            u.color_code,
            ea.position,
            se.start_datetime,
            se.end_datetime
        FROM 
            event_assignments ea
        JOIN 
            users u ON ea.user_id = u.id 
        JOIN 
            schedule_events se ON ea.event_id = se.id
        WHERE 
            ea.event_id IN ($placeholders)
        ORDER BY 
            se.start_datetime ASC, FIELD(ea.position, 'head', 'helper') ASC
    ";

    $stmt_assignments = $pdo->prepare($assignment_sql);
    $stmt_assignments->execute($event_ids);
    $assignments = $stmt_assignments->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assignments = [];
}

// --- 4. FORMAT EVENTS FOR FULLCALENDAR ---

// 4a. Format Base Class Slots (Gray Boxes)
foreach ($base_events as $event) {
    $events_data[] = [
        'id' => 'base-' . $event['event_id'],
        'title' => $event['title'] . " (" . $event['location_name'] . ")",
        'start' => $event['start_datetime'],
        'end' => $event['end_datetime'],
        'backgroundColor' => '#cccccc',
        'borderColor' => '#999999',
        'className' => 'fc-class-slot',
        'extendedProps' => [
            'event_id' => $event['event_id'],
            'is_base_slot' => true
        ]
    ];
}

// 4b. Format Assigned Coaches
foreach ($assignments as $assignment) {
    $title = $assignment['name'] . " (" . ucfirst($assignment['position']) . ")";

    $events_data[] = [
        'id' => $assignment['event_id'] . '-coach-' . $assignment['name'],
        'title' => $title,
        'start' => $assignment['start_datetime'],
        'end' => $assignment['end_datetime'],
        'backgroundColor' => $assignment['color_code'],
        'borderColor' => $assignment['color_code'],
        'editable' => false,
        'durationEditable' => false,
        'resourceEditable' => false,
        'extendedProps' => [
            'event_id' => $assignment['event_id'],
            'position' => $assignment['position']
        ]
    ];
}

// --- 5. OUTPUT JSON ---
header('Content-Type: application/json');
echo json_encode($events_data);
