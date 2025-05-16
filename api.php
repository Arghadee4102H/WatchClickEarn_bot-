<?php
// --- Error Handling Setup ---
ini_set('display_errors', 0); // Turn off displaying errors directly to the browser/client
error_reporting(E_ALL);     // Report all errors to be caught by logs or shutdown function

// Custom shutdown function to catch fatal errors and output JSON
function api_shutdown_function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // If headers haven't been sent, set them
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500); // Internal Server Error
        }
        // Output a JSON error
        echo json_encode([
            'error' => 'PHP_FATAL_ERROR',
            'message' => 'A critical server error occurred.', // Generic message for client
            'debug_message' => $error['message'], // More detailed for your debugging
            'debug_file' => basename($error['file']),
            'debug_line' => $error['line']
        ]);
    }
}
register_shutdown_function('api_shutdown_function');

// --- Main API Logic ---
header('Content-Type: application/json; charset=utf-8'); // Set content type early

require_once 'db_config.php'; // $conn variable will be available here

// Check for DB connection error immediately after including db_config.php
if ($conn->connect_error) {
    error_log("Database connection failed in api.php: " . $conn->connect_error); // Log the specific error
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'error' => 'DB_CONNECTION_FAILED',
        'message' => 'Could not connect to the database. Please try again later.'
        // 'debug_details' => $conn->connect_error // Optionally include for your debugging, remove for client
    ]);
    exit; // Stop script execution
}

// Constants
define('POINTS_PER_TAP', 1);
define('REFERRAL_BONUS_POINTS', 20);
define('POINTS_PER_AD', 40);
define('MAX_DAILY_ADS', 45);
define('POINTS_PER_TASK_SET', 200);

$action = $_GET['action'] ?? '';
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Check if JSON decoding failed
if ($inputJSON && json_last_error() !== JSON_ERROR_NONE && (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' && !empty($inputJSON) ) ) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'error' => 'INVALID_JSON_INPUT',
        'message' => 'The input data was not valid JSON.',
        'debug_json_error' => json_last_error_msg()
    ]);
    exit;
}
$input = $input ?: []; // Ensure $input is an array even if JSON is empty or not provided for GET

// Always expect telegram_id for most actions after initialization
$telegram_id = $input['telegram_id'] ?? null;
$user = null;

if ($telegram_id && $action !== 'initializeUser') {
    $user = getUser($conn, $telegram_id);
    if ($user) {
        $user = checkAndResetDailyLimits($conn, $user); // Modifies $user by reference or returns new array
        $user = calculateAndUpdateEnergy($conn, $user); // Modifies $user by reference or returns new array
    } else {
        // getUser returned null, meaning user not found for this telegram_id
        echo json_encode(['error' => 'User not found. Please restart or reinitialize.']);
        exit;
    }
} else if (!$telegram_id && $action !== 'initializeUser' && $action !== '' /* allow no action for testing api.php */) {
    // If telegram_id is missing for actions that require it (excluding initializeUser)
    echo json_encode(['error' => 'Telegram ID is missing for this action.']);
    exit;
}


switch ($action) {
    case 'initializeUser':
        initializeUser($conn, $input);
        break;
    case 'getUserData':
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            // This case should ideally not be reached if telegram_id was provided due to checks above
            echo json_encode(['error' => 'User data not available. Initialize first.']);
        }
        break;
    case 'tap':
        handleTap($conn, $user); // $user should be valid here due to checks above
        break;
    case 'getTasks':
        getTasks($conn, $user); // $user should be valid
        break;
    case 'completeDailyTasks':
        completeDailyTasks($conn, $user); // $user should be valid
        break;
    case 'watchAd':
        handleWatchAd($conn, $user); // $user should be valid
        break;
    case 'requestWithdrawal':
        handleWithdrawal($conn, $user, $input); // $user should be valid
        break;
    case 'syncEnergy':
        if ($user) {
            echo json_encode(['success' => true, 'data' => [
                'energy' => $user['energy'],
                'points' => $user['points'],
                'last_energy_update_ts' => $user['last_energy_update_ts']
            ]]);
        } else {
            echo json_encode(['error' => 'User not found for energy sync.']);
        }
        break;
    case '': // No action specified, useful for testing if api.php is reachable
        echo json_encode(['status' => 'API is alive and awaiting action.']);
        break;
    default:
        echo json_encode(['error' => 'Invalid action specified: ' . htmlspecialchars($action)]);
}

if ($conn) { // Ensure $conn is an object before trying to close
    $conn->close();
}


// --- Core Functions ---
// (Keep the core functions: getUser, initializeUser, calculateAndUpdateEnergy, checkAndResetDailyLimits, handleTap, getTasks, hasCompletedDailyTasks, completeDailyTasks, getAdsWatchedToday, handleWatchAd, handleWithdrawal, log_db_error as provided in the previous response)
// Make sure these functions don't echo or die unexpectedly. They should return data or throw exceptions (if you implement more advanced try/catch).

function log_db_error($conn, $message) {
    // Basic error logging. In production, you might use a more robust logging system.
    // Ensure $conn is an object before accessing ->error
    $mysql_error = ($conn && is_object($conn) && isset($conn->error)) ? $conn->error : "N/A (Connection object invalid or error property not set)";
    error_log("Database Error: " . $message . " | MySQL Error: " . $mysql_error);
}


// (Paste all the function definitions from the previous api.php here)
// Example: getUser, initializeUser, etc.

function getUser($conn, $telegram_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE telegram_id = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed: getUser"); return null; }
    $stmt->bind_param("i", $telegram_id);
    if(!$stmt->execute()){ log_db_error($conn, "Execute failed: getUser - " . $stmt->error); $stmt->close(); return null; }
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    if ($userData) {
        $userData['ads_watched_today'] = getAdsWatchedToday($conn, $userData['user_id']);
        $userData['max_daily_ads'] = MAX_DAILY_ADS;
        $userData['tasks_completed_today'] = hasCompletedDailyTasks($conn, $userData['user_id']);
    }
    return $userData;
}

function initializeUser($conn, $input) {
    $telegram_id = $input['telegram_id'] ?? null;
    $username = $input['username'] ?? null;
    $first_name = $input['first_name'] ?? null;
    $start_param = $input['start_param'] ?? null;

    if (!$telegram_id) {
        echo json_encode(['error' => 'Telegram ID is required for initialization.']);
        exit; // Exit after echoing JSON
    }

    $user = getUser($conn, $telegram_id);

    if (!$user) {
        $conn->begin_transaction();
        try {
            $referred_by_user_id = null;
            if ($start_param) {
                $referrer_telegram_id = filter_var($start_param, FILTER_VALIDATE_INT);
                if ($referrer_telegram_id && $referrer_telegram_id != $telegram_id) {
                    $referrerUser = getUser($conn, $referrer_telegram_id);
                    if ($referrerUser) {
                        $referred_by_user_id = $referrerUser['user_id'];
                        $new_referrer_points = $referrerUser['points'] + REFERRAL_BONUS_POINTS;
                        $new_total_referrals = $referrerUser['total_referrals'] + 1;
                        $stmt_ref = $conn->prepare("UPDATE users SET points = ?, total_referrals = ? WHERE user_id = ?");
                        if (!$stmt_ref) { throw new Exception("Prepare failed (referrer update): " . $conn->error); }
                        $stmt_ref->bind_param("iii", $new_referrer_points, $new_total_referrals, $referrerUser['user_id']);
                        if(!$stmt_ref->execute()) { throw new Exception("Execute failed (referrer update): " . $stmt_ref->error); }
                        $stmt_ref->close();
                    }
                }
            }

            $current_ts_for_energy = time();
            $stmt_new = $conn->prepare("INSERT INTO users (telegram_id, username, first_name, referred_by_user_id, energy, max_energy, energy_per_tap, energy_refill_rate_seconds, last_energy_update_ts, daily_clicks_count, max_daily_clicks, last_click_reset_date_utc) VALUES (?, ?, ?, ?, 100, 100, 1, 30, ?, 0, 2500, CURDATE())");
            if (!$stmt_new) { throw new Exception("Prepare failed (new user insert): " . $conn->error); }
            $stmt_new->bind_param("issii", $telegram_id, $username, $first_name, $referred_by_user_id, $current_ts_for_energy); // Removed one 's' from "issiiis"
             if(!$stmt_new->execute()) { throw new Exception("Execute failed (new user insert): " . $stmt_new->error); }
            // $new_user_id = $stmt_new->insert_id; // Not strictly needed here unless used immediately
            $stmt_new->close();
            $conn->commit();
            $user = getUser($conn, $telegram_id); 
        } catch (Exception $e) {
            $conn->rollback();
            log_db_error($conn, "Transaction failed (initializeUser): " . $e->getMessage());
            echo json_encode(['error' => 'Failed to create user profile.', 'debug_info' => $e->getMessage()]);
            exit; // Exit after echoing JSON
        }
    }
    
    if(!$user) { // Safety check if user is still null after creation attempt
        echo json_encode(['error' => 'User initialization failed unexpectedly.']);
        exit;
    }

    $user = checkAndResetDailyLimits($conn, $user);
    $user = calculateAndUpdateEnergy($conn, $user);

    echo json_encode(['success' => true, 'data' => $user]);
    exit; // Exit after echoing JSON
}

function calculateAndUpdateEnergy($conn, $currentUserData) { // Changed name to avoid conflict if $user is global
    if (!$currentUserData || !isset($currentUserData['user_id'])) {
        log_db_error($conn, "calculateAndUpdateEnergy called with invalid user data.");
        return $currentUserData; // Return original if invalid
    }
    $user = $currentUserData; // Work with a copy or ensure modifications are intended for the original array

    $currentTime = time(); 
    $lastUpdate = (int)$user['last_energy_update_ts'];
    $energyRefillRate = (int)$user['energy_refill_rate_seconds']; 
    $maxEnergy = (int)$user['max_energy'];
    $currentEnergy = (int)$user['energy'];

    if ($currentEnergy >= $maxEnergy) {
        if ($currentTime - $lastUpdate > $energyRefillRate) { 
            $stmt = $conn->prepare("UPDATE users SET last_energy_update_ts = ? WHERE user_id = ?");
            if (!$stmt) { log_db_error($conn, "Prepare failed (energy timestamp update)"); return $user; }
            $stmt->bind_param("ii", $currentTime, $user['user_id']);
            if($stmt->execute()){ $user['last_energy_update_ts'] = $currentTime; }
            else { log_db_error($conn, "Execute failed (energy ts update): " . $stmt->error); }
            $stmt->close();
        }
        return $user; 
    }

    if ($energyRefillRate <= 0) return $user; 

    $timePassed = $currentTime - $lastUpdate;
    if ($timePassed <= 0) return $user;

    $energyGained = floor($timePassed / $energyRefillRate);

    if ($energyGained > 0) {
        $newEnergy = min($maxEnergy, $currentEnergy + $energyGained);
        $newLastUpdateTs = $lastUpdate + ($energyGained * $energyRefillRate); 
        
        $stmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE user_id = ?");
        if (!$stmt) { log_db_error($conn, "Prepare failed (energy update)"); return $user; } 
        $stmt->bind_param("iii", $newEnergy, $newLastUpdateTs, $user['user_id']);
        
        if ($stmt->execute()) {
            $user['energy'] = $newEnergy;
            $user['last_energy_update_ts'] = $newLastUpdateTs;
        } else {
            log_db_error($conn, "Execute failed (energy update): " . $stmt->error);
        }
        $stmt->close();
    }
    return $user; // Return the modified user array
}


function checkAndResetDailyLimits($conn, $currentUserData) { // Changed name
     if (!$currentUserData || !isset($currentUserData['user_id'])) {
        log_db_error($conn, "checkAndResetDailyLimits called with invalid user data.");
        return $currentUserData;
    }
    $user = $currentUserData; // Work with a copy

    $current_utc_date_str = gmdate('Y-m-d'); 
    $current_utc_date_obj = new DateTime($current_utc_date_str, new DateTimeZone('UTC'));

    $needsUpdate = false;
    $update_query_parts = [];
    $bind_params_values = []; // Store values for bind_param
    $types_string = "";       // Store types for bind_param

    $last_click_reset_date_str = $user['last_click_reset_date_utc'] ?? null; // Handle null case
    if ($last_click_reset_date_str) {
        try {
            $last_click_reset_date_obj = new DateTime($last_click_reset_date_str, new DateTimeZone('UTC'));
            if ($current_utc_date_obj > $last_click_reset_date_obj) {
                $user['daily_clicks_count'] = 0; // Optimistic update to user array
                $update_query_parts[] = "daily_clicks_count = 0";
                $update_query_parts[] = "last_click_reset_date_utc = ?";
                $bind_params_values[] = $current_utc_date_str;
                $types_string .= "s";
                $needsUpdate = true;
            }
        } catch (Exception $e) { // Catch if date string is invalid
            log_db_error($conn, "Invalid date format for last_click_reset_date_utc: " . $last_click_reset_date_str);
            // Force reset if date is problematic
            $update_query_parts[] = "last_click_reset_date_utc = ?";
            $bind_params_values[] = $current_utc_date_str;
            $types_string .= "s";
            $needsUpdate = true;
        }
    } else { 
        $update_query_parts[] = "last_click_reset_date_utc = ?";
        $bind_params_values[] = $current_utc_date_str;
        $types_string .= "s";
        $needsUpdate = true;
    }
    
    if ($needsUpdate) {
        $user['ads_watched_today'] = getAdsWatchedToday($conn, $user['user_id']);
        $user['tasks_completed_today'] = hasCompletedDailyTasks($conn, $user['user_id']);
    }


    if ($needsUpdate && count($update_query_parts) > 0) {
        $sql = "UPDATE users SET " . implode(", ", $update_query_parts) . " WHERE user_id = ?";
        $types_string .= "i";
        $bind_params_values[] = $user['user_id'];

        $stmt = $conn->prepare($sql);
        if (!$stmt) { log_db_error($conn, "Prepare failed (reset daily limits)"); return $user; }
        
        // Use call_user_func_array for dynamic bind_param
        if (!empty($types_string)) {
            $stmt->bind_param($types_string, ...$bind_params_values);
        }
        
        if (!$stmt->execute()) {
            log_db_error($conn, "Execute failed (reset daily limits): " . $stmt->error);
        }
        $stmt->close();
        // Re-fetch user to get the most accurate state after updates, or merge changes.
        // For simplicity, the optimistic update to $user array for daily_clicks_count is done above.
        // The ads_watched_today and tasks_completed_today are also updated.
    }
    return $user; // Return modified user array
}


function handleTap($conn, $user) { // $user is passed by value (array)
    if (!$user) { echo json_encode(['error' => 'User data not available.']); exit; }

    if ($user['energy'] < $user['energy_per_tap']) {
        echo json_encode(['error' => 'Not enough energy.']); exit;
    }
    if ($user['daily_clicks_count'] >= $user['max_daily_clicks']) {
        echo json_encode(['error' => 'Daily click limit reached.']); exit;
    }

    $new_points = $user['points'] + POINTS_PER_TAP;
    $new_energy = $user['energy'] - $user['energy_per_tap'];
    $new_daily_clicks = $user['daily_clicks_count'] + 1;
    $current_ts_for_energy = time(); 

    $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, daily_clicks_count = ?, last_energy_update_ts = ? WHERE user_id = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (tap)"); echo json_encode(['error' => 'Server error processing tap.']); exit; }
    
    $stmt->bind_param("iiiii", $new_points, $new_energy, $new_daily_clicks, $current_ts_for_energy, $user['user_id']);

    if ($stmt->execute()) {
        $user['points'] = $new_points;
        $user['energy'] = $new_energy;
        $user['daily_clicks_count'] = $new_daily_clicks;
        $user['last_energy_update_ts'] = $current_ts_for_energy;
        // Re-fetch ads_watched_today and tasks_completed_today if tap action could affect them (it doesn't directly)
        // $user['ads_watched_today'] = getAdsWatchedToday($conn, $user['user_id']);
        // $user['tasks_completed_today'] = hasCompletedDailyTasks($conn, $user['user_id']);
        echo json_encode(['success' => true, 'data' => $user, 'points_earned_this_tap' => POINTS_PER_TAP]);
    } else {
        log_db_error($conn, "Execute failed (tap): " . $stmt->error);
        echo json_encode(['error' => 'Failed to record tap.']);
    }
    $stmt->close();
    exit; // Exit after processing
}

function getTasks($conn, $user) {
    if (!$user) { echo json_encode(['error' => 'User data not available.']); exit; }
    $tasks = [];
    $result = $conn->query("SELECT task_id, name, description, link, points_reward FROM tasks WHERE is_daily = TRUE ORDER BY task_id ASC LIMIT 4");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
        $result->free();
    } else {
        log_db_error($conn, "Query failed (getTasks)");
        echo json_encode(['error' => 'Could not fetch tasks.']); exit;
    }
    
    $tasks_completed_today = hasCompletedDailyTasks($conn, $user['user_id']);
    echo json_encode(['success' => true, 'data' => ['tasks' => $tasks, 'tasks_completed_today' => $tasks_completed_today]]);
    exit;
}

function hasCompletedDailyTasks($conn, $user_id) {
    $current_utc_date = gmdate('Y-m-d');
    $stmt = $conn->prepare("SELECT 1 FROM user_daily_task_sets WHERE user_id = ? AND completion_date_utc = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (hasCompletedDailyTasks)"); return false; }
    $stmt->bind_param("is", $user_id, $current_utc_date);
    if(!$stmt->execute()){ log_db_error($conn, "Execute failed (hasCompletedDailyTasks) " . $stmt->error); $stmt->close(); return false; }
    $result = $stmt->get_result();
    $completed = $result->num_rows > 0;
    $stmt->close();
    return $completed;
}

function completeDailyTasks($conn, $user) {
    if (!$user) { echo json_encode(['error' => 'User data not available.']); exit; }
    if (hasCompletedDailyTasks($conn, $user['user_id'])) {
        echo json_encode(['error' => 'Daily tasks already completed today.']); exit;
    }

    $current_utc_date = gmdate('Y-m-d');
    $new_total_points = $user['points'] + POINTS_PER_TASK_SET;

    $conn->begin_transaction();
    try {
        $stmt_log = $conn->prepare("INSERT INTO user_daily_task_sets (user_id, completion_date_utc) VALUES (?, ?)");
        if (!$stmt_log) throw new Exception("Prepare failed (task log): " . $conn->error);
        $stmt_log->bind_param("is", $user['user_id'], $current_utc_date);
        if (!$stmt_log->execute()) throw new Exception("Execute failed (task log): " . $stmt_log->error);
        $stmt_log->close();

        $stmt_update = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
        if (!$stmt_update) throw new Exception("Prepare failed (user points update): " . $conn->error);
        $stmt_update->bind_param("ii", $new_total_points, $user['user_id']);
        if (!$stmt_update->execute()) throw new Exception("Execute failed (user points update): " . $stmt_update->error);
        $stmt_update->close();

        $conn->commit();
        $user['points'] = $new_total_points;
        $user['tasks_completed_today'] = true; 
        echo json_encode(['success' => true, 'data' => $user, 'points_earned' => POINTS_PER_TASK_SET]); // Return updated user
    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (completeDailyTasks): " . $e->getMessage());
        echo json_encode(['error' => 'Failed to complete tasks.', 'debug_info' => $e->getMessage()]);
    }
    exit;
}


function getAdsWatchedToday($conn, $user_id) {
    $current_utc_date_start = gmdate('Y-m-d 00:00:00');
    $current_utc_date_end = gmdate('Y-m-d 23:59:59');

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_ads_log WHERE user_id = ? AND watched_at_utc BETWEEN ? AND ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (getAdsWatchedToday)"); return 0; }
    $stmt->bind_param("iss", $user_id, $current_utc_date_start, $current_utc_date_end);
    if(!$stmt->execute()){ log_db_error($conn, "Execute failed (getAdsWatchedToday) " . $stmt->error); $stmt->close(); return 0; }
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['count'] ?? 0;
}

function handleWatchAd($conn, $user) {
    if (!$user) { echo json_encode(['error' => 'User data not available.']); exit; }
    $ads_watched_today = getAdsWatchedToday($conn, $user['user_id']); // Re-fetch for accuracy
    if ($ads_watched_today >= MAX_DAILY_ADS) {
        echo json_encode(['error' => 'Daily ad watch limit reached.']); exit;
    }

    $new_total_points = $user['points'] + POINTS_PER_AD;

    $conn->begin_transaction();
    try {
        $stmt_log = $conn->prepare("INSERT INTO user_ads_log (user_id, points_earned, watched_at_utc) VALUES (?, ?, UTC_TIMESTAMP())");
        if (!$stmt_log) throw new Exception("Prepare failed (ad log): " . $conn->error);
        $stmt_log->bind_param("ii", $user['user_id'], POINTS_PER_AD);
        if (!$stmt_log->execute()) throw new Exception("Execute failed (ad log): " . $stmt_log->error);
        $stmt_log->close();

        $stmt_update = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
        if (!$stmt_update) throw new Exception("Prepare failed (user points for ad): " . $conn->error);
        $stmt_update->bind_param("ii", $new_total_points, $user['user_id']);
        if (!$stmt_update->execute()) throw new Exception("Execute failed (user points for ad): " . $stmt_update->error);
        $stmt_update->close();
        
        $conn->commit();

        $user['points'] = $new_total_points;
        $user['ads_watched_today'] = $ads_watched_today + 1; 
        echo json_encode(['success' => true, 'data' => $user, 'points_earned_for_ad' => POINTS_PER_AD]);
    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (handleWatchAd): " . $e->getMessage());
        echo json_encode(['error' => 'Failed to record ad view.', 'debug_info' => $e->getMessage()]);
    }
    exit;
}

function handleWithdrawal($conn, $user, $input) {
    if (!$user) { echo json_encode(['error' => 'User data not available.']); exit; }
    $amount = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_INT);
    $method = trim($input['method'] ?? '');
    $details = trim($input['details'] ?? '');

    $valid_amounts = [85000, 160000, 300000];
    if (!in_array($amount, $valid_amounts)) {
        echo json_encode(['error' => 'Invalid withdrawal amount.']); exit;
    }
    if ($user['points'] < $amount) {
        echo json_encode(['error' => 'Insufficient points.']); exit;
    }
    if (empty($method) || empty($details)) {
        echo json_encode(['error' => 'Payment method and details are required.']); exit;
    }
    if (!in_array($method, ['UPI', 'Binance'])) {
        echo json_encode(['error' => 'Invalid payment method.']); exit;
    }

    $new_total_points = $user['points'] - $amount;

    $conn->begin_transaction();
    try {
        $stmt_insert = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, details, status) VALUES (?, ?, ?, ?, 'pending')");
        if (!$stmt_insert) throw new Exception("Prepare failed (withdrawal insert): " . $conn->error);
        $stmt_insert->bind_param("iiss", $user['user_id'], $amount, $method, $details);
        if (!$stmt_insert->execute()) throw new Exception("Execute failed (withdrawal insert): " . $stmt_insert->error);
        $stmt_insert->close();

        $stmt_update = $conn->prepare("UPDATE users SET points = ? WHERE user_id = ?");
         if (!$stmt_update) throw new Exception("Prepare failed (user points for withdrawal): " . $conn->error);
        $stmt_update->bind_param("ii", $new_total_points, $user['user_id']);
        if (!$stmt_update->execute()) throw new Exception("Execute failed (user points for withdrawal): " . $stmt_update->error);
        $stmt_update->close();

        $conn->commit();
        $user['points'] = $new_total_points; 
        echo json_encode(['success' => true, 'data' => ['new_total_points' => $new_total_points, 'updated_user_data_for_client' => $user]]); // Send back updated user for client
    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (handleWithdrawal): " . $e->getMessage());
        echo json_encode(['error' => 'Withdrawal request failed.', 'debug_info' => $e->getMessage()]);
    }
    exit;
}

?>
