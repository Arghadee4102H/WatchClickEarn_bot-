<?php
// --- DATABASE CONFIGURATION ---
// Replace with your InfinityFree MySQL details
define('DB_HOST', 'sql100.infinityfree.com'); // e.g., sqlXXX.infinityfree.com
define('DB_USER', 'if0_38992815');
define('DB_PASS', 'arghadeep858066');
define('DB_NAME', 'if0_38992815_watchearn_db'); // e.g., if0_38992815_watchearn_db

// --- APPLICATION CONFIGURATION ---
define('BOT_USERNAME', 'WatchClickEarn_bot'); // Your Telegram Bot Username
define('POINTS_PER_TAP', 1);
define('POINTS_PER_REFERRAL', 20);
define('POINTS_PER_TASK', 50);
define('POINTS_PER_AD', 40);
define('MAX_CLICKS_PER_DAY', 2500);
define('MAX_ADS_PER_DAY', 45);
define('INITIAL_MAX_ENERGY', 100); // Default max energy for new users
define('ENERGY_REGEN_RATE_PER_MINUTE', 20); // e.g., 20 energy per minute (1 per 3 sec)
define('AD_COOLDOWN_MINUTES', 3); // Cooldown in minutes between watching ads

// Timezone for daily resets (ALWAYS UTC for server-side logic)
date_default_timezone_set('UTC');

// Function to establish database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        // Log error, don't expose details to client
        error_log("Connection failed: " . $conn->connect_error);
        die(json_encode(['success' => false, 'message' => 'Database connection error. Please try again later.']));
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// IMPORTANT: Telegram WebApp Validation (Simplified for now)
// For production, you MUST validate initData to prevent cheating
// See: https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
function validateTelegramData($telegram_init_data_str) {
    // This is a placeholder. Real validation is complex.
    // You'd typically check the hash against your bot token.
    // For this example, we'll parse it assuming it's valid if present.
    if (empty($telegram_init_data_str)) {
        return null;
    }
    parse_str($telegram_init_data_str, $initData);
    if (isset($initData['user'])) {
        return json_decode($initData['user'], true);
    }
    return null;
}
?>
