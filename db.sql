CREATE DATABASE gb_schedule;
USE gb_schedule;

-- 1. Locations
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL -- e.g., 'Davenport', 'Celebration'
);

-- 2. Users (Coaches)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    rate_head_coach DECIMAL(10, 2) DEFAULT 0.00,
    rate_helper DECIMAL(10, 2) DEFAULT 0.00,
    coach_type ENUM('bjj', 'mt', 'both') NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    color_code VARCHAR(7) DEFAULT '#3788d8' -- To differentiate coaches visually
);

-- 3. Classes (The specific time slots created on the calendar)
CREATE TABLE schedule_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location_id INT,
    martial_art ENUM('bjj', 'mt'),
    title VARCHAR(100), -- e.g. "6:00 AM - BJJ"
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id)
);

-- 4. Assignments (Who is teaching that specific class)
CREATE TABLE event_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT,
    user_id INT,
    position ENUM('head', 'helper') DEFAULT 'head', -- 'head' gets the bold font
    FOREIGN KEY (event_id) REFERENCES schedule_events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);