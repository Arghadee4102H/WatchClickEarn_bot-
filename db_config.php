<?php
// Database configuration for InfinityFree
define('DB_SERVER', 'sql305.infinityfree.com'); // Get this from InfinityFree MySQL Databases section
define('DB_USERNAME', 'if0_38990174');      // Your InfinityFree username (or specific DB user if created)
define('DB_PASSWORD', 'art454500'); // Your InfinityFree account password or DB password
define('DB_NAME', 'if0_38990174_watchearn_db'); // Your database name

// Attempt to connect to MySQL database
try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set character set
    $pdo->exec("SET NAMES 'utf8mb4'");
} catch(PDOException $e){
    // Important: Do not echo detailed error messages in production
    error_log("ERROR: Could not connect to database. " . $e->getMessage());
    // Send a generic error response to the client
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Database connection error. Please try again later.']);
    exit; // Stop script execution
}

// --- Constants ---
define('POINTS_PER_TAP', 1);
define('POINTS_PER_REFERRAL', 20);
define('POINTS_PER_AD_WATCH', 40);
define('AD_COOLDOWN_MINUTES', 3);
define('DEFAULT_MAX_ENERGY', 100);
define('ENERGY_REFILL_RATE_PER_SECOND', 0.33); // Approx 1 energy every 3 seconds
define('MAX_CLICKS_PER_DAY', 2500);
define('MAX_ADS_PER_DAY', 45);

// Telegram Bot Username (for referral links)
define('TELEGRAM_BOT_USERNAME', 'WatchClickEarn_bot');
?>
