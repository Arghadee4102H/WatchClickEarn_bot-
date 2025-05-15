<?php
// db_config.php
define('DB_SERVER', 'sql305.infinityfree.com'); // Replace with your SQL server from InfinityFree (e.g., sql101.infinityfree.com)
define('DB_USERNAME', 'if0_38990174');      // Replace with your InfinityFree username
define('DB_PASSWORD', 'art454500');  // Replace with your InfinityFree account/database password
define('DB_NAME', 'if0_38990174_watchearn_db'); // Your database name

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($conn->connect_error){ // Use connect_error for object-oriented mysqli
    // For production, you might want to log this error instead of die() with details
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    // die("ERROR: Could not connect. " . $conn->connect_error);
    exit; // Exit script after sending error
}

// Set charset to utf8mb4
if (!$conn->set_charset("utf8mb4")) {
    // Log error or handle
}

// Set timezone to UTC for this connection for all session queries
if (!$conn->query("SET time_zone = '+00:00'")) {
    // Log error or handle
}

// --- Global Game Configuration ---
define('POINTS_PER_TAP', 1); // Example: 1 point per tap. Can be dynamic later.
define('POINTS_PER_REFERRAL', 20);
define('ENERGY_REFILL_INTERVAL_SECONDS', 3); // 1 energy point every 3 seconds
define('POINTS_PER_AD', 50);
define('AD_COOLDOWN_SECONDS', 3 * 60); // 3 minutes in seconds
define('POINTS_PER_TASK', 50); // Default points per task

// Predefined tasks
$PREDEFINED_TASKS_CONFIG = [
    1 => ['id' => 1, 'title' => 'Join Telegram Channel Alpha', 'link' => 'https://t.me/WatchClickEarn', 'points' => 50, 'type' => 'telegram_channel'],
    2 => ['id' => 2, 'title' => 'Join Telegram Group Beta', 'link' => 'https://t.me/WatchClickEarnchat', 'points' => 50, 'type' => 'telegram_group'],
    3 => ['id' => 3, 'title' => 'Follow Telegram Channel Gamma', 'link' => 'https://t.me/earningsceret', 'points' => 50, 'type' => 'telegram_channel'],
    4 => ['id' => 4, 'title' => 'Subscribe to Channel Delta', 'link' => 'https://t.me/ShopEarnHub4102h', 'points' => 50, 'type' => 'telegram_channel'],
];
// Note: Ensure these links are actual valid Telegram links.
?>
