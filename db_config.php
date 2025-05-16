<?php
// Database configuration for InfinityFree
// Replace with your actual database credentials

define('DB_SERVER', 'sql100.infinityfree.com'); // e.g., sql202.infinityfree.com
define('DB_USERNAME', 'if0_38992815');    // Your InfinityFree username (e.g., if0_12345678)
define('DB_PASSWORD', 'arghadeep858066');    // Your database password
define('DB_NAME', 'if0_38992815_watchearn_db'); // Your database name (e.g., if0_12345678_watchearn_db)

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// DO NOT die() or echo here. Error handling will be in api.php
// This allows api.php to control the JSON output for errors.

if (!$conn->connect_error) {
    // Set charset to utf8mb4 if connection is successful
    $conn->set_charset("utf8mb4");
}

// Timezone setting for PHP script to UTC (align with database and game logic)
date_default_timezone_set('UTC');
?>
