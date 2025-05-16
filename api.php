<?php
header('Content-Type: application/json');
require_once 'db_config.php'; // $conn variable will be available here

// Constants
define('POINTS_PER_TAP', 1);
define('REFERRAL_BONUS_POINTS', 20);
define('POINTS_PER_AD', 40);
define('MAX_DAILY_ADS', 45);
define('POINTS_PER_TASK_SET', 200); // Total for completing all 4 daily tasks

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// Always expect telegram_id for most actions after initialization
$telegram_id = $input['telegram_id'] ?? null;
$user = null;

if ($telegram_id && $action !== 'initializeUser') { // For most actions, load user data first
    $user = getUser($conn, $telegram_id);
    if ($user) {
        $user = checkAndResetDailyLimits($conn, $user);
        $user = calculateAndUpdateEnergy($conn, $user); // Calculate energy on each relevant request
    } else if ($action !== 'initializeUser') {
        echo json_encode(['error' => 'User not found or not initialized. Please restart the app.']);
        exit;
    }
}


switch ($action) {
    case 'initializeUser':
        initializeUser($conn, $input);
        break;
    case 'getUserData': // Could be used for manual refresh
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            echo json_encode(['error' => 'User not found. Please initialize first.']);
        }
        break;
    case 'tap':
        handleTap($conn, $user);
        break;
    case 'getTasks':
        getTasks($conn, $user);
        break;
    case 'completeDailyTasks':
        completeDailyTasks($conn, $user);
        break;
    case 'watchAd':
        handleWatchAd($conn, $user);
        break;
    case 'requestWithdrawal':
        handleWithdrawal($conn, $user, $input);
        break;
    case 'syncEnergy': // For client to sync its energy calculation with server's authoritative one
        if ($user) {
             // The calculateAndUpdateEnergy function already updates $user['energy'] and $user['last_energy_update_ts']
            // Just return the fresh user data which now includes calculated energy
            echo json_encode(['success' => true, 'data' => [
                'energy' => $user['energy'],
                'points' => $user['points'], // also send points
                'last_energy_update_ts' => $user['last_energy_update_ts']
            ]]);
        } else {
            echo json_encode(['error' => 'User not found for energy sync.']);
        }
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

$conn->close();

// --- Core Functions ---

function getUser($conn, $telegram_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE telegram_id = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed: getUser"); return null; }
    $stmt->bind_param("i", $telegram_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    if ($userData) {
        // Add derived/helper properties
        $userData['ads_watched_today'] = getAdsWatchedToday($conn, $userData['user_id']);
        $userData['max_daily_ads'] = MAX_DAILY_ADS; // Constant
        $userData['tasks_completed_today'] = hasCompletedDailyTasks($conn, $userData['user_id']);
    }
    return $userData;
}

function initializeUser($conn, $input) {
    $telegram_id = $input['telegram_id'] ?? null;
    $username = $input['username'] ?? null;
    $first_name = $input['first_name'] ?? null;
    $start_param = $input['start_param'] ?? null; // Referrer's Telegram ID

    if (!$telegram_id) {
        echo json_encode(['error' => 'Telegram ID is required for initialization.']);
        return;
    }

    $user = getUser($conn, $telegram_id);

    if (!$user) { // New user
        $conn->begin_transaction();
        try {
            $referred_by_user_id = null;
            if ($start_param) {
                $referrer_telegram_id = filter_var($start_param, FILTER_VALIDATE_INT);
                if ($referrer_telegram_id && $referrer_telegram_id != $telegram_id) {
                    $referrerUser = getUser($conn, $referrer_telegram_id);
                    if ($referrerUser) {
                        $referred_by_user_id = $referrerUser['user_id'];
                        // Award bonus to referrer
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

            $current_ts_for_energy = time(); // Current Unix timestamp
            $stmt_new = $conn->prepare("INSERT INTO users (telegram_id, username, first_name, referred_by_user_id, energy, max_energy, energy_per_tap, energy_refill_rate_seconds, last_energy_update_ts, daily_clicks_count, max_daily_clicks, last_click_reset_date_utc) VALUES (?, ?, ?, ?, 100, 100, 1, 30, ?, 0, 2500, CURDATE())");
            if (!$stmt_new) { throw new Exception("Prepare failed (new user insert): " . $conn->error); }
            $stmt_new->bind_param("issiiis", $telegram_id, $username, $first_name, $referred_by_user_id, $current_ts_for_energy);
             if(!$stmt_new->execute()) { throw new Exception("Execute failed (new user insert): " . $stmt_new->error); }
            $new_user_id = $stmt_new->insert_id;
            $stmt_new->close();
            $conn->commit();
            $user = getUser($conn, $telegram_id); // Fetch the newly created user
        } catch (Exception $e) {
            $conn->rollback();
            log_db_error($conn, "Transaction failed (initializeUser): " . $e->getMessage());
            echo json_encode(['error' => 'Failed to create user profile. ' . $e->getMessage()]);
            return;
        }
    }
    
    // For both new and existing users, ensure daily limits and energy are up-to-date
    $user = checkAndResetDailyLimits($conn, $user);
    $user = calculateAndUpdateEnergy($conn, $user);

    echo json_encode(['success' => true, 'data' => $user]);
}

function calculateAndUpdateEnergy($conn, &$user) {
    $currentTime = time(); // Current Unix timestamp
    $lastUpdate = (int)$user['last_energy_update_ts'];
    $energyRefillRate = (int)$user['energy_refill_rate_seconds']; // seconds per 1 energy
    $maxEnergy = (int)$user['max_energy'];
    $currentEnergy = (int)$user['energy'];

    if ($currentEnergy >= $maxEnergy) {
        // If energy is already full, just update timestamp if it's significantly old to prevent large accumulation on next spend.
        // Or, ensure last_energy_update_ts reflects a time when it was full.
        if ($currentTime - $lastUpdate > $energyRefillRate) { // Check if it's worth updating timestamp
            $stmt = $conn->prepare("UPDATE users SET last_energy_update_ts = ? WHERE user_id = ?");
            if (!$stmt) { log_db_error($conn, "Prepare failed (energy timestamp update)"); return $user; }
            $stmt->bind_param("ii", $currentTime, $user['user_id']);
            $stmt->execute();
            $stmt->close();
            $user['last_energy_update_ts'] = $currentTime;
        }
        return $user; // No change in energy value needed
    }

    if ($energyRefillRate <= 0) return $user; // Avoid division by zero

    $timePassed = $currentTime - $lastUpdate;
    if ($timePassed <= 0) return $user;

    $energyGained = floor($timePassed / $energyRefillRate);

    if ($energyGained > 0) {
        $newEnergy = min($maxEnergy, $currentEnergy + $energyGained);
        // Determine the "effective" last update timestamp. If energy filled up, it's the time it became full.
        // Otherwise, it's the current time minus any "unused" fraction of the refill interval.
        $newLastUpdateTs = $lastUpdate + ($energyGained * $energyRefillRate); 
        
        $stmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE user_id = ?");
        if (!$stmt) { log_db_error($conn, "Prepare failed (energy update)"); return $user; } // Log and return old user data
        $stmt->bind_param("iii", $newEnergy, $newLastUpdateTs, $user['user_id']);
        
        if ($stmt->execute()) {
            $user['energy'] = $newEnergy;
            $user['last_energy_update_ts'] = $newLastUpdateTs;
        } else {
            log_db_error($conn, "Execute failed (energy update): " . $stmt->error);
        }
        $stmt->close();
    }
    return $user;
}


function checkAndResetDailyLimits($conn, &$user) {
    $current_utc_date_str = gmdate('Y-m-d'); // Current UTC date as string
    $current_utc_date_obj = new DateTime($current_utc_date_str, new DateTimeZone('UTC'));

    $needsUpdate = false;
    $update_query_parts = [];
    $bind_params = [];
    $types = "";

    // Check daily click limit
    $last_click_reset_date_str = $user['last_click_reset_date_utc'];
    if ($last_click_reset_date_str) {
        $last_click_reset_date_obj = new DateTime($last_click_reset_date_str, new DateTimeZone('UTC'));
        if ($current_utc_date_obj > $last_click_reset_date_obj) {
            $user['daily_clicks_count'] = 0;
            $update_query_parts[] = "daily_clicks_count = 0";
            $update_query_parts[] = "last_click_reset_date_utc = ?";
            $bind_params[] = $current_utc_date_str;
            $types .= "s";
            $needsUpdate = true;
        }
    } else { // First time or null date
        $update_query_parts[] = "last_click_reset_date_utc = ?";
        $bind_params[] = $current_utc_date_str;
        $types .= "s";
        $needsUpdate = true;
    }
    
    // Ads and Tasks daily limits are implicitly handled by checking dates on their respective log tables
    // but we can update the derived properties in $user if date changed.
    if ($needsUpdate) {
        // $user['ads_watched_today'] would be reset if date changes (handled by getAdsWatchedToday)
        // $user['tasks_completed_today'] would be reset if date changes (handled by hasCompletedDailyTasks)
        $user['ads_watched_today'] = getAdsWatchedToday($conn, $user['user_id']);
        $user['tasks_completed_today'] = hasCompletedDailyTasks($conn, $user['user_id']);
    }


    if ($needsUpdate && count($update_query_parts) > 0) {
        $sql = "UPDATE users SET " . implode(", ", $update_query_parts) . " WHERE user_id = ?";
        $types .= "i";
        $bind_params[] = $user['user_id'];

        $stmt = $conn->prepare($sql);
        if (!$stmt) { log_db_error($conn, "Prepare failed (reset daily limits)"); return $user; }
        
        $stmt->bind_param($types, ...$bind_params);
        if (!$stmt->execute()) {
            log_db_error($conn, "Execute failed (reset daily limits): " . $stmt->error);
        }
        $stmt->close();
        // Re-fetch user to get the most accurate state after updates
        $user = getUser($conn, $user['telegram_id']);
    }
    return $user;
}


function handleTap($conn, &$user) {
    if ($user['energy'] < $user['energy_per_tap']) {
        echo json_encode(['error' => 'Not enough energy.']);
        return;
    }
    if ($user['daily_clicks_count'] >= $user['max_daily_clicks']) {
        echo json_encode(['error' => 'Daily click limit reached.']);
        return;
    }

    $new_points = $user['points'] + POINTS_PER_TAP;
    $new_energy = $user['energy'] - $user['energy_per_tap'];
    $new_daily_clicks = $user['daily_clicks_count'] + 1;
    $current_ts_for_energy = time(); // For updating last_energy_update_ts due to spending

    $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, daily_clicks_count = ?, last_energy_update_ts = ? WHERE user_id = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (tap)"); echo json_encode(['error' => 'Server error processing tap.']); return; }
    
    $stmt->bind_param("iiiii", $new_points, $new_energy, $new_daily_clicks, $current_ts_for_energy, $user['user_id']);

    if ($stmt->execute()) {
        // Update user object in memory
        $user['points'] = $new_points;
        $user['energy'] = $new_energy;
        $user['daily_clicks_count'] = $new_daily_clicks;
        $user['last_energy_update_ts'] = $current_ts_for_energy;
        echo json_encode(['success' => true, 'data' => $user, 'points_earned_this_tap' => POINTS_PER_TAP]);
    } else {
        log_db_error($conn, "Execute failed (tap): " . $stmt->error);
        echo json_encode(['error' => 'Failed to record tap.']);
    }
    $stmt->close();
}

function getTasks($conn, $user) {
    $tasks = [];
    $result = $conn->query("SELECT task_id, name, description, link, points_reward FROM tasks WHERE is_daily = TRUE ORDER BY task_id ASC LIMIT 4");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tasks[] = $row;
        }
    } else {
        log_db_error($conn, "Query failed (getTasks)");
        echo json_encode(['error' => 'Could not fetch tasks.']);
        return;
    }
    
    $tasks_completed_today = hasCompletedDailyTasks($conn, $user['user_id']);
    echo json_encode(['success' => true, 'data' => ['tasks' => $tasks, 'tasks_completed_today' => $tasks_completed_today]]);
}

function hasCompletedDailyTasks($conn, $user_id) {
    $current_utc_date = gmdate('Y-m-d');
    $stmt = $conn->prepare("SELECT 1 FROM user_daily_task_sets WHERE user_id = ? AND completion_date_utc = ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (hasCompletedDailyTasks)"); return false; }
    $stmt->bind_param("is", $user_id, $current_utc_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $completed = $result->num_rows > 0;
    $stmt->close();
    return $completed;
}

function completeDailyTasks($conn, &$user) {
    if (hasCompletedDailyTasks($conn, $user['user_id'])) {
        echo json_encode(['error' => 'Daily tasks already completed today.']);
        return;
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
        $user['tasks_completed_today'] = true; // Update in-memory user object
        echo json_encode(['success' => true, 'data' => ['new_total_points' => $new_total_points, 'points_earned' => POINTS_PER_TASK_SET]]);
    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (completeDailyTasks): " . $e->getMessage());
        echo json_encode(['error' => 'Failed to complete tasks. ' . $e->getMessage()]);
    }
}


function getAdsWatchedToday($conn, $user_id) {
    $current_utc_date_start = gmdate('Y-m-d 00:00:00');
    $current_utc_date_end = gmdate('Y-m-d 23:59:59');

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_ads_log WHERE user_id = ? AND watched_at_utc BETWEEN ? AND ?");
    if (!$stmt) { log_db_error($conn, "Prepare failed (getAdsWatchedToday)"); return 0; }
    $stmt->bind_param("iss", $user_id, $current_utc_date_start, $current_utc_date_end);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['count'] ?? 0;
}

function handleWatchAd($conn, &$user) {
    $ads_watched_today = getAdsWatchedToday($conn, $user['user_id']);
    if ($ads_watched_today >= MAX_DAILY_ADS) {
        echo json_encode(['error' => 'Daily ad watch limit reached.']);
        return;
    }

    // Cooldown is primarily client-side, but a server-side check could be added here if needed.
    // For now, we trust the client's 3-minute cooldown handling.

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
        $user['ads_watched_today'] = $ads_watched_today + 1; // Update in-memory count
        echo json_encode(['success' => true, 'data' => $user, 'points_earned_for_ad' => POINTS_PER_AD]);

    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (handleWatchAd): " . $e->getMessage());
        echo json_encode(['error' => 'Failed to record ad view. ' . $e->getMessage()]);
    }
}

function handleWithdrawal($conn, &$user, $input) {
    $amount = filter_var($input['amount'] ?? 0, FILTER_VALIDATE_INT);
    $method = trim($input['method'] ?? '');
    $details = trim($input['details'] ?? '');

    $valid_amounts = [85000, 160000, 300000];
    if (!in_array($amount, $valid_amounts)) {
        echo json_encode(['error' => 'Invalid withdrawal amount.']);
        return;
    }
    if ($user['points'] < $amount) {
        echo json_encode(['error' => 'Insufficient points.']);
        return;
    }
    if (empty($method) || empty($details)) {
        echo json_encode(['error' => 'Payment method and details are required.']);
        return;
    }
    if (!in_array($method, ['UPI', 'Binance'])) {
        echo json_encode(['error' => 'Invalid payment method.']);
        return;
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
        $user['points'] = $new_total_points; // Update in-memory user object
        echo json_encode(['success' => true, 'data' => ['new_total_points' => $new_total_points]]);
    } catch (Exception $e) {
        $conn->rollback();
        log_db_error($conn, "Transaction failed (handleWithdrawal): " . $e->getMessage());
        echo json_encode(['error' => 'Withdrawal request failed. ' . $e->getMessage()]);
    }
}

function log_db_error($conn, $message) {
    // Basic error logging. In production, you might use a more robust logging system.
    error_log("Database Error: " . $message . " | MySQL Error: " . $conn->error);
}

?>
