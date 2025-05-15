<?php
// api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // For local development, restrict in production

require_once 'db_config.php'; // Includes $conn and $PREDEFINED_TASKS_CONFIG

// --- Helper Functions ---
function generateUniqueAppId($conn) {
    do {
        // Simple random string generator
        $randomString = bin2hex(random_bytes(8)); // 16 chars
        $stmt = $conn->prepare("SELECT id FROM users WHERE unique_app_id = ?");
        $stmt->bind_param("s", $randomString);
        $stmt->execute();
        $stmt->store_result();
    } while ($stmt->num_rows > 0);
    $stmt->close();
    return $randomString;
}

function getCurrentUtcDate() {
    return gmdate('Y-m-d');
}
function getCurrentUtcTimestamp() {
    return gmdate('Y-m-d H:i:s');
}

function getUserData($conn, $telegram_user_id) {
    $stmt = $conn->prepare("SELECT *, UNIX_TIMESTAMP(last_energy_update_ts) as last_energy_update_unix FROM users WHERE telegram_user_id = ?");
    $stmt->bind_param("i", $telegram_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Recalculate energy before returning
        $now_unix = time(); // Current UTC timestamp (PHP time() is UNIX timestamp)
        $last_update_unix = $user['last_energy_update_unix'];
        $seconds_passed = $now_unix - $last_update_unix;
        
        $energy_to_add = 0;
        if ($seconds_passed > 0 && $user['energy_refill_rate_seconds'] > 0) {
            $energy_to_add = floor($seconds_passed / $user['energy_refill_rate_seconds']);
        }

        $new_energy = $user['energy'] + $energy_to_add;
        $new_energy = min($new_energy, $user['max_energy']); // Cap at max_energy

        if ($new_energy != $user['energy']) {
            $user['energy'] = $new_energy;
            // Update last_energy_update_ts to effectively now (or the point up to which energy was calculated)
            // This ensures energy isn't repeatedly added for the same period.
            // For simplicity, update to current time if energy changed.
            $new_last_energy_update_ts = getCurrentUtcTimestamp();
            
            $update_stmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE telegram_user_id = ?");
            $update_stmt->bind_param("isi", $new_energy, $new_last_energy_update_ts, $telegram_user_id);
            $update_stmt->execute();
            $update_stmt->close();
            $user['last_energy_update_ts'] = $new_last_energy_update_ts; // Reflect updated timestamp
        }

        // Check and reset daily tap count
        $current_utc_date = getCurrentUtcDate();
        if ($user['last_tap_date_utc'] != $current_utc_date) {
            $stmt_reset_taps = $conn->prepare("UPDATE users SET daily_taps = 0, last_tap_date_utc = ? WHERE telegram_user_id = ?");
            $stmt_reset_taps->bind_param("si", $current_utc_date, $telegram_user_id);
            $stmt_reset_taps->execute();
            $stmt_reset_taps->close();
            $user['daily_taps'] = 0;
            $user['last_tap_date_utc'] = $current_utc_date;
        }

        // Check and reset daily ad watch count
        if ($user['last_ads_reset_date_utc'] != $current_utc_date) {
            $stmt_reset_ads = $conn->prepare("UPDATE users SET daily_ads_watched_count = 0, last_ads_reset_date_utc = ? WHERE telegram_user_id = ?");
            $stmt_reset_ads->bind_param("si", $current_utc_date, $telegram_user_id);
            $stmt_reset_ads->execute();
            $stmt_reset_ads->close();
            $user['daily_ads_watched_count'] = 0;
            $user['last_ads_reset_date_utc'] = $current_utc_date;
        }
        
        // Add constants for client-side use if needed
        $user['points_per_ad'] = POINTS_PER_AD;
        $user['ad_cooldown_seconds'] = AD_COOLDOWN_SECONDS;
    }
    return $user;
}

// --- Main Action Handler ---
$action = $_POST['action'] ?? null;
$telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : null;

if (!$conn) { // db_config.php already handles this, but as a fallback
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

switch ($action) {
    case 'init_user':
        $tg_id_param = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        $username = $_POST['username'] ?? null;
        $first_name = $_POST['first_name'] ?? null;
        $referred_by_app_id = $_POST['referred_by_app_id'] ?? null;

        if ($tg_id_param <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Telegram User ID.']);
            exit;
        }
        
        $user = getUserData($conn, $tg_id_param);

        if (!$user) {
            // New user
            $unique_app_id = generateUniqueAppId($conn);
            $current_ts = getCurrentUtcTimestamp();
            $current_date_utc = getCurrentUtcDate();

            $stmt = $conn->prepare("INSERT INTO users (telegram_user_id, username, first_name, unique_app_id, referred_by_app_id, last_tap_date_utc, last_ads_reset_date_utc, last_energy_update_ts, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssss", $tg_id_param, $username, $first_name, $unique_app_id, $referred_by_app_id, $current_date_utc, $current_date_utc, $current_ts, $current_ts);
            
            if ($stmt->execute()) {
                $user = getUserData($conn, $tg_id_param); // Fetch the newly created user data
                if ($referred_by_app_id) {
                    // Award points to referrer
                    $stmt_referrer = $conn->prepare("UPDATE users SET points = points + ?, total_referrals_verified = total_referrals_verified + 1 WHERE unique_app_id = ?");
                    $points_per_ref = POINTS_PER_REFERRAL;
                    $stmt_referrer->bind_param("is", $points_per_ref, $referred_by_app_id);
                    $stmt_referrer->execute();
                    $stmt_referrer->close();
                }
                echo json_encode(['success' => true, 'data' => $user, 'message' => 'User initialized.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            // Existing user, just return data (energy and daily limits already updated in getUserData)
            echo json_encode(['success' => true, 'data' => $user, 'message' => 'User data loaded.']);
        }
        break;

    case 'sync_user_data':
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required.']);
            exit;
        }
        $user = getUserData($conn, $telegram_user_id);
        if ($user) {
            echo json_encode(['success' => true, 'data' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
        }
        break;

    case 'tap':
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required for tap.']);
            exit;
        }
        
        $user = getUserData($conn, $telegram_user_id); // This also refreshes energy and daily counts
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        if ($user['daily_taps'] >= $user['max_daily_taps']) {
            echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'data' => $user]);
            exit;
        }
        if ($user['energy'] < $user['energy_per_tap']) {
            echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'data' => $user]);
            exit;
        }

        $new_energy = $user['energy'] - $user['energy_per_tap'];
        $points_earned_this_tap = POINTS_PER_TAP; // Can be made dynamic from user specific settings if needed
        $new_points = $user['points'] + $points_earned_this_tap;
        $new_daily_taps = $user['daily_taps'] + 1;
        $current_ts = getCurrentUtcTimestamp(); // For last_energy_update_ts

        $stmt = $conn->prepare("UPDATE users SET points = ?, energy = ?, daily_taps = ?, last_energy_update_ts = ? WHERE telegram_user_id = ?");
        $stmt->bind_param("iiisi", $new_points, $new_energy, $new_daily_taps, $current_ts, $telegram_user_id);
        
        if ($stmt->execute()) {
            $updated_user = getUserData($conn, $telegram_user_id); // Get fresh data
            echo json_encode(['success' => true, 'message' => "+{$points_earned_this_tap} Points!", 'points_earned' => $points_earned_this_tap, 'data' => $updated_user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tap failed to record.', 'data' => $user]);
        }
        $stmt->close();
        break;

    case 'get_tasks':
        global $PREDEFINED_TASKS_CONFIG; // Use the global from db_config.php
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required.']);
            exit;
        }
        $current_utc_date = getCurrentUtcDate();
        $stmt = $conn->prepare("SELECT task_id FROM user_tasks WHERE telegram_user_id = ? AND completion_date_utc = ?");
        $stmt->bind_param("is", $telegram_user_id, $current_utc_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $completed_tasks_today = [];
        while ($row = $result->fetch_assoc()) {
            $completed_tasks_today[] = $row['task_id'];
        }
        $stmt->close();

        $tasks_to_send = [];
        foreach ($PREDEFINED_TASKS_CONFIG as $task_id => $task_details) {
            $tasks_to_send[] = [
                'id' => $task_id,
                'title' => $task_details['title'],
                'link' => $task_details['link'],
                'points' => $task_details['points'],
                'completed_today' => in_array($task_id, $completed_tasks_today)
            ];
        }
        echo json_encode(['success' => true, 'tasks' => $tasks_to_send]);
        break;

    case 'complete_task':
        global $PREDEFINED_TASKS_CONFIG;
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required.']);
            exit;
        }
        $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

        if (!isset($PREDEFINED_TASKS_CONFIG[$task_id])) {
            echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
            exit;
        }
        $task_details = $PREDEFINED_TASKS_CONFIG[$task_id];
        $points_for_task = $task_details['points'];
        $current_utc_date = getCurrentUtcDate();

        // Check if already completed today
        $stmt_check = $conn->prepare("SELECT id FROM user_tasks WHERE telegram_user_id = ? AND task_id = ? AND completion_date_utc = ?");
        $stmt_check->bind_param("iis", $telegram_user_id, $task_id, $current_utc_date);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'data' => getUserData($conn, $telegram_user_id)]);
            $stmt_check->close();
            exit;
        }
        $stmt_check->close();

        $conn->begin_transaction();
        try {
            $stmt_insert_completion = $conn->prepare("INSERT INTO user_tasks (telegram_user_id, task_id, completion_date_utc) VALUES (?, ?, ?)");
            $stmt_insert_completion->bind_param("iis", $telegram_user_id, $task_id, $current_utc_date);
            $stmt_insert_completion->execute();
            
            $stmt_update_points = $conn->prepare("UPDATE users SET points = points + ? WHERE telegram_user_id = ?");
            $stmt_update_points->bind_param("ii", $points_for_task, $telegram_user_id);
            $stmt_update_points->execute();

            $conn->commit();
            $stmt_insert_completion->close();
            $stmt_update_points->close();
            echo json_encode(['success' => true, 'message' => "Task '{$task_details['title']}' completed! +{$points_for_task} points.", 'points_earned' => $points_for_task, 'data' => getUserData($conn, $telegram_user_id)]);

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to complete task. Database error.']);
        }
        break;

    case 'watched_ad':
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required.']);
            exit;
        }
        $user = getUserData($conn, $telegram_user_id); // Refreshes daily counts
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        if ($user['daily_ads_watched_count'] >= $user['max_daily_ads']) {
            echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'data' => $user]);
            exit;
        }

        // Check cooldown
        $current_time_unix = time();
        if ($user['last_ad_watched_ts']) {
            $last_ad_time_unix = strtotime($user['last_ad_watched_ts']);
             $ad_cooldown_seconds = AD_COOLDOWN_SECONDS;
            if (($current_time_unix - $last_ad_time_unix) < $ad_cooldown_seconds) {
                 echo json_encode(['success' => false, 'message' => 'Please wait for ad cooldown.', 'data' => $user]);
                 exit;
            }
        }
        
        $points_for_ad = POINTS_PER_AD;
        $new_points = $user['points'] + $points_for_ad;
        $new_daily_ads_count = $user['daily_ads_watched_count'] + 1;
        $current_ts = getCurrentUtcTimestamp();

        $stmt = $conn->prepare("UPDATE users SET points = points + ?, daily_ads_watched_count = ?, last_ad_watched_ts = ? WHERE telegram_user_id = ?");
        $stmt->bind_param("iisi", $points_for_ad, $new_daily_ads_count, $current_ts, $telegram_user_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => "Ad reward claimed! +{$points_for_ad} points.", 'points_earned' => $points_for_ad, 'data' => getUserData($conn, $telegram_user_id)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record ad view.', 'data' => $user]);
        }
        $stmt->close();
        break;

    case 'request_withdrawal':
        if (!$telegram_user_id) {
            echo json_encode(['success' => false, 'message' => 'User ID required.']);
            exit;
        }
        $points_to_withdraw = isset($_POST['points_withdrawn']) ? (int)$_POST['points_withdrawn'] : 0;
        $method = $_POST['method'] ?? '';
        $details = $_POST['details'] ?? '';

        if (!in_array($points_to_withdraw, [85000, 160000, 300000])) {
             echo json_encode(['success' => false, 'message' => 'Invalid withdrawal amount.']);
             exit;
        }
        if (empty($method) || empty($details)) {
            echo json_encode(['success' => false, 'message' => 'Payment method and details are required.']);
            exit;
        }
        if (!in_array($method, ['UPI', 'Binance'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment method.']);
            exit;
        }

        $user = getUserData($conn, $telegram_user_id);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }
        if ($user['points'] < $points_to_withdraw) {
            echo json_encode(['success' => false, 'message' => 'Insufficient points.', 'data' => $user]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $stmt_deduct = $conn->prepare("UPDATE users SET points = points - ? WHERE telegram_user_id = ? AND points >= ?");
            $stmt_deduct->bind_param("iii", $points_to_withdraw, $telegram_user_id, $points_to_withdraw);
            $stmt_deduct->execute();

            if ($stmt_deduct->affected_rows > 0) {
                $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (telegram_user_id, points_withdrawn, method, details, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmt_insert_withdrawal->bind_param("iisss", $telegram_user_id, $points_to_withdraw, $method, $details);
                $stmt_insert_withdrawal->execute();
                
                $conn->commit();
                $stmt_deduct->close();
                $stmt_insert_withdrawal->close();
                echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted successfully. It will be processed soon.', 'data' => getUserData($conn, $telegram_user_id)]);
            } else {
                $conn->rollback(); // Points deduction failed (e.g. race condition or points changed)
                 $stmt_deduct->close();
                echo json_encode(['success' => false, 'message' => 'Failed to process withdrawal. Please try again.', 'data' => getUserData($conn, $telegram_user_id)]);
            }
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log("Withdrawal error for user $telegram_user_id: " . $exception->getMessage()); // Log the actual error
            echo json_encode(['success' => false, 'message' => 'An error occurred while processing your withdrawal. Please contact support.']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        break;
}

$conn->close();
?>
