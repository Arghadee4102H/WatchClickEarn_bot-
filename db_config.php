<?php
// db_config.php

// ---- IMPORTANT: REPLACE THESE WITH YOUR ACTUAL INFINITYFREE DETAILS ----
define('DB_SERVER', 'sql305.infinityfree.com'); // Example: sql101.infinityfree.com (Check your InfinityFree cPanel)
define('DB_USERNAME', 'if0_38990174');          // Your InfinityFree username (or specific DB username if different)
define('DB_PASSWORD', 'art454500');  // Your InfinityFree ACCOUNT password
define('DB_NAME', 'if0_38990174_watchearn_db'); // Your full database name from InfinityFree
// -------------------------------------------------------------------------

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($conn->connect_error){
    // This will output an error and stop script execution if DB connection fails.
    // The JavaScript will likely receive this as HTML, causing the JSON parse error.
    header('Content-Type: application/json'); // Still attempt to set, but error below will be HTML
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

if (!$conn->set_charset("utf8mb4")) {
    // Log error or handle if charset setting fails
}
if (!$conn->query("SET time_zone = '+00:00'")) {
    // Log error or handle if timezone setting fails
}

// --- Global Game Configuration ---
define('POINTS_PER_TAP', 1);
define('POINTS_PER_REFERRAL', 20);
define('ENERGY_REFILL_INTERVAL_SECONDS', 3);
define('POINTS_PER_AD', 50);
define('AD_COOLDOWN_SECONDS', 3 * 60); // 3 minutes
define('POINTS_PER_TASK', 50);

// ---- IMPORTANT: REPLACE THESE WITH YOUR ACTUAL TELEGRAM LINKS ----
$PREDEFINED_TASKS_CONFIG = [
    1 => ['id' => 1, 'title' => 'Join Telegram Channel', 'link' => 'https://t.me/WatchClickEarn', 'points' => 50, 'type' => 'telegram_channel'],
    2 => ['id' => 2, 'title' => 'Join Telegram Group', 'link' => 'https://t.me/WatchClickEarnchat', 'points' => 50, 'type' => 'telegram_group'],
    3 => ['id' => 3, 'title' => 'Follow Telegram Channel', 'link' => 'https://t.me/earningsceret', 'points' => 50, 'type' => 'telegram_channel'],
    4 => ['id' => 4, 'title' => 'Subscribe to Channel', 'link' => 'https://t.me/ShopEarnHub4102h', 'points' => 50, 'type' => 'telegram_channel'],
];
// ----------------------------------------------------------------
?>
