<?php
// api.php
ini_set('display_errors', 0); // Set to 0 for production. Use .htaccess for dev debugging.
error_reporting(0); // Set to 0 for production.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_config.php'; // This will handle DB connection or exit with JSON error if connection fails

// --- Helper Functions ---
function generateUniqueAppId($conn) {
    do {
        $randomString = bin2hex(random_bytes(8));
        $stmt = $conn->prepare("SELECT id FROM users WHERE unique_app_id = ?");
        $stmt->bind_param("s", $randomString);
        $stmt->execute();
        $stmt->store_result();
    } while ($stmt->num_rows > 0);
    $stmt->close();
    return $randomString;
}

function getCurrentUtcDate() { return gmdate('Y-m-d'); }
function getCurrentUtcTimestamp() { return gmdate('Y-m-d H:i:s'); }

function getUserData($conn, $telegram_user_id) {
    if (!$conn || $conn->connect_error) { // Extra check though db_config should handle it
        return null;
    }
    $stmt = $conn->prepare("SELECT *, UNIX_TIMESTAMP(last_energy_update_ts) as last_energy_update_unix FROM users WHERE telegram_user_id = ?");
    if (!$stmt) { /* Log prepare error */ return null; }
    $stmt->bind_param("i", $telegram_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        $now_unix = time();
        $last_update_unix = $user['last_energy_update_unix'];
        $seconds_passed = $now_unix - $last_update_unix;
        
        $energy_to_add = 0;
        if ($seconds_passed > 0 && $user['energy_refill_rate_seconds'] > 0) {
            $energy_to_add = floor($seconds_passed / $user['energy_refill_rate_seconds']);
        }

        $new_energy = $user['energy'] + $energy_to_add;
        if ($new_energy > $user['max_energy']) {
             $new_energy = $user['max_energy'];
        }


        if ($new_energy != $user['energy'] || $seconds_passed > ($user['energy_refill_rate_seconds'] * 2) ) { // Update if energy changed or significant time passed
            $user['energy'] = $new_energy;
            $new_last_energy_update_ts = getCurrentUtcTimestamp();
            
            $update_stmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update_ts = ? WHERE telegram_user_id = ?");
            if ($update_stmt) {
                $update_stmt->bind_param("isi", $new_energy, $new_last_energy_update_ts, $telegram_user_id);
                $update_stmt->execute();
                $update_stmt->close();
                $user['last_energy_update_ts'] = $new_last_energy_update_ts;
            }
        }

        $current_utc_date = getCurrentUtcDate();
        if ($user['last_tap_date_utc'] != $current_utc_date) {
            $stmt_reset_taps = $conn->prepare("UPDATE users SET daily_taps = 0, last_tap_date_utc = ? WHERE telegram_user_id = ?");
             if ($stmt_reset_taps) {
                $stmt_reset_taps->bind_param("si", $current_utc_date, $telegram_user_id);
                $stmt_reset_taps->execute();
                $stmt_reset_taps->close();
                $user['daily_taps'] = 0;
                $user['last_tap_date_utc'] = $current_utc_date;
            }
        }

        if ($user['last_ads_reset_date_utc'] != $current_utc_date) {
            $stmt_reset_ads = $conn->prepare("UPDATE users SET daily_ads_watched_count = 0, last_ads_reset_date_utc = ? WHERE telegram_user_id = ?");
            if ($stmt_reset_ads) {
                $stmt_reset_ads->bind_param("si", $current_utc_date, $telegram_user_id);
                $stmt_reset_ads->execute();
                $stmt_reset_ads->close();
                $user['daily_ads_watched_count'] = 0;
                $user['last_ads_reset_date_utc'] = $current_utc_date;
            }
        }
        
        $user['points_per_ad'] = POINTS_PER_AD;
        $user['ad_cooldown_seconds'] = AD_COOLDOWN_SECONDS;
    }
    return $user;
}

// --- Main Action Handler ---
$action = $_POST['action'] ?? null;
// telegram_user_id is passed in POST data for most actions, except init_user where it's part of the payload.
// For actions needing it, it will be retrieved from POST or from existing session if that was implemented.
// For this structure, it's mostly passed in POST data.

switch ($action) {
    case 'init_user':
        $tg_id_param = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        $username = $_POST['username'] ?? null;
        $first_name = $_POST['first_name'] ?? ($username ?: "User{$tg_id_param}");
        $referred_by_app_id = $_POST['referred_by_app_id'] ?? null;

        if ($tg_id_param <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Telegram User ID.']);
            exit;
        }
        
        $user = getUserData($conn, $tg_id_param);

        if (!$user) {
            $unique_app_id = generateUniqueAppId($conn);
            $current_ts = getCurrentUtcTimestamp();
            $current_date_utc = getCurrentUtcDate();

            $stmt = $conn->prepare("INSERT INTO users (telegram_user_id, username, first_name, unique_app_id, referred_by_app_id, last_tap_date_utc, last_ads_reset_date_utc, last_energy_update_ts, created_at, max_energy, energy_per_tap, energy_refill_rate_seconds, max_daily_taps, max_daily_ads) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 1, 3, 2500, 35)");
            if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Server error preparing user creation.']); exit; }
            $stmt->bind_param("issssssss", $tg_id_param, $username, $first_name, $unique_app_id, $referred_by_app_id, $current_date_utc, $current_date_utc, $current_ts, $current_ts);
            
            if ($stmt->execute()) {
                $stmt->close();
                $user = getUserData($conn, $tg_id_param); 
                if ($referred_by_app_id && $user) {
                    $stmt_referrer = $conn->prepare("UPDATE users SET points = points + ?, total_referrals_verified = total_referrals_verified + 1 WHERE unique_app_id = ? AND telegram_user_id != ?");
                    if($stmt_referrer){
                        $points_per_ref = POINTS_PER_REFERRAL;
                        $stmt_referrer->bind_param("isi", $points_per_ref, $referred_by_app_id, $tg_id_param); // Prevent self-referral points
                        $stmt_referrer->execute();
                        $stmt_referrer->close();
                    }
                }
                // Refetch user data after potential referral bonus to referrer
                $user = getUserData($conn, $tg_id_param); // Ensure $user is the latest after all operations
                echo json_encode(['success' => true, 'data' => $user, 'message' => 'User initialized.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $stmt->error]);
                $stmt->close();
            }
        } else {
            echo json_encode(['success' => true, 'data' => $user, 'message' => 'User data loaded.']);
        }
        break;

    case 'sync_user_data':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required.']); exit; }
        $user = getUserData($conn, $telegram_user_id);
        if ($user) { echo json_encode(['success' => true, 'data' => $user]); }
        else { echo json_encode(['success' => false, 'message' => 'User not found for sync.']); }
        break;

    case 'tap':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required for tap.']); exit; }
        
        $user = getUserData($conn, $telegram_user_id);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }

        if ($user['daily_taps'] >= $user['max_daily_taps']) { echo json_encode(['success' => false, 'message' => 'Daily tap limit reached.', 'data' => $user]); exit; }
        if ($user['energy'] < $user['energy_per_tap']) { echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'data' => $user]); exit; }

        $new_energy = $user['energy'] - $user['energy_per_tap'];
        $points_earned_this_tap = POINTS_PER_TAP;
        // $new_points = $user['points'] + $points_earned_this_tap; // Handled by single query
        $new_daily_taps = $user['daily_taps'] + 1;
        $current_ts = getCurrentUtcTimestamp();

        $stmt = $conn->prepare("UPDATE users SET points = points + ?, energy = ?, daily_taps = ?, last_energy_update_ts = ? WHERE telegram_user_id = ?");
        if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Server error preparing tap.']); exit; }
        $stmt->bind_param("iiisi", $points_earned_this_tap, $new_energy, $new_daily_taps, $current_ts, $telegram_user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => true, 'message' => "+{$points_earned_this_tap}!", 'points_earned' => $points_earned_this_tap, 'data' => getUserData($conn, $telegram_user_id)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tap failed to record.', 'data' => $user]);
            $stmt->close();
        }
        break;

    case 'get_tasks':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required.']); exit; }
        global $PREDEFINED_TASKS_CONFIG;
        $current_utc_date = getCurrentUtcDate();
        $stmt = $conn->prepare("SELECT task_id FROM user_tasks WHERE telegram_user_id = ? AND completion_date_utc = ?");
        if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Server error preparing tasks.']); exit; }
        $stmt->bind_param("is", $telegram_user_id, $current_utc_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $completed_tasks_today = [];
        while ($row = $result->fetch_assoc()) { $completed_tasks_today[] = $row['task_id']; }
        $stmt->close();

        $tasks_to_send = [];
        foreach ($PREDEFINED_TASKS_CONFIG as $task_id => $task_details) {
            $tasks_to_send[] = array_merge($task_details, ['completed_today' => in_array($task_id, $completed_tasks_today)]);
        }
        echo json_encode(['success' => true, 'tasks' => $tasks_to_send]);
        break;

    case 'complete_task':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required.']); exit; }
        global $PREDEFINED_TASKS_CONFIG;
        $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

        if (!isset($PREDEFINED_TASKS_CONFIG[$task_id])) { echo json_encode(['success' => false, 'message' => 'Invalid task ID.']); exit; }
        
        $task_details = $PREDEFINED_TASKS_CONFIG[$task_id];
        $points_for_task = $task_details['points'];
        $current_utc_date = getCurrentUtcDate();

        $stmt_check = $conn->prepare("SELECT id FROM user_tasks WHERE telegram_user_id = ? AND task_id = ? AND completion_date_utc = ?");
        if (!$stmt_check) { echo json_encode(['success' => false, 'message' => 'Server error checking task.']); exit; }
        $stmt_check->bind_param("iis", $telegram_user_id, $task_id, $current_utc_date);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) { echo json_encode(['success' => false, 'message' => 'Task already completed today.', 'data' => getUserData($conn, $telegram_user_id)]); $stmt_check->close(); exit; }
        $stmt_check->close();

        $conn->begin_transaction();
        try {
            $stmt_insert_completion = $conn->prepare("INSERT INTO user_tasks (telegram_user_id, task_id, completion_date_utc) VALUES (?, ?, ?)");
            if (!$stmt_insert_completion) throw new Exception("Prepare insert failed");
            $stmt_insert_completion->bind_param("iis", $telegram_user_id, $task_id, $current_utc_date);
            $stmt_insert_completion->execute();
            $stmt_insert_completion->close();
            
            $stmt_update_points = $conn->prepare("UPDATE users SET points = points + ? WHERE telegram_user_id = ?");
            if (!$stmt_update_points) throw new Exception("Prepare update points failed");
            $stmt_update_points->bind_param("ii", $points_for_task, $telegram_user_id);
            $stmt_update_points->execute();
            $stmt_update_points->close();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Task '{$task_details['title']}' completed! +{$points_for_task} points.", 'points_earned' => $points_for_task, 'data' => getUserData($conn, $telegram_user_id)]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Task completion error for user $telegram_user_id, task $task_id: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to complete task. DB error.']);
        }
        break;

    case 'watched_ad':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required.']); exit; }
        $user = getUserData($conn, $telegram_user_id);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }

        if ($user['daily_ads_watched_count'] >= $user['max_daily_ads']) { echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'data' => $user]); exit; }

        $current_time_unix = time();
        if ($user['last_ad_watched_ts']) {
            $last_ad_time_unix = strtotime($user['last_ad_watched_ts']);
            $ad_cooldown_seconds = AD_COOLDOWN_SECONDS;
            if (($current_time_unix - $last_ad_time_unix) < $ad_cooldown_seconds) { echo json_encode(['success' => false, 'message' => 'Please wait for ad cooldown.', 'data' => $user]); exit; }
        }
        
        $points_for_ad = POINTS_PER_AD;
        $current_ts = getCurrentUtcTimestamp();

        $stmt = $conn->prepare("UPDATE users SET points = points + ?, daily_ads_watched_count = daily_ads_watched_count + 1, last_ad_watched_ts = ? WHERE telegram_user_id = ?");
        if (!$stmt) { echo json_encode(['success' => false, 'message' => 'Server error preparing ad watch.']); exit; }
        $stmt->bind_param("isi", $points_for_ad, $current_ts, $telegram_user_id);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode(['success' => true, 'message' => "Ad reward! +{$points_for_ad} points.", 'points_earned' => $points_for_ad, 'data' => getUserData($conn, $telegram_user_id)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record ad view.', 'data' => $user]);
            $stmt->close();
        }
        break;

    case 'request_withdrawal':
        $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : 0;
        if (!$telegram_user_id) { echo json_encode(['success' => false, 'message' => 'User ID required.']); exit; }
        
        $points_to_withdraw = isset($_POST['points_withdrawn']) ? (int)$_POST['points_withdrawn'] : 0;
        $method = $_POST['method'] ?? '';
        $details = $_POST['details'] ?? '';

        if (!in_array($points_to_withdraw, [85000, 160000, 300000])) { echo json_encode(['success' => false, 'message' => 'Invalid withdrawal amount.']); exit; }
        if (empty($method) || empty($details)) { echo json_encode(['success' => false, 'message' => 'Payment method and details are required.']); exit; }
        if (!in_array($method, ['UPI', 'Binance'])) { echo json_encode(['success' => false, 'message' => 'Invalid payment method.']); exit; }

        $user = getUserData($conn, $telegram_user_id);
        if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        if ($user['points'] < $points_to_withdraw) { echo json_encode(['success' => false, 'message' => 'Insufficient points.', 'data' => $user]); exit; }

        $conn->begin_transaction();
        try {
            $stmt_deduct = $conn->prepare("UPDATE users SET points = points - ? WHERE telegram_user_id = ? AND points >= ?");
            if (!$stmt_deduct) throw new Exception("Prepare deduct failed");
            $stmt_deduct->bind_param("iii", $points_to_withdraw, $telegram_user_id, $points_to_withdraw);
            $stmt_deduct->execute();

            if ($stmt_deduct->affected_rows > 0) {
                $stmt_deduct->close();
                $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (telegram_user_id, points_withdrawn, method, details, status) VALUES (?, ?, ?, ?, 'pending')");
                if (!$stmt_insert_withdrawal) throw new Exception("Prepare insert withdrawal failed");
                $stmt_insert_withdrawal->bind_param("iisss", $telegram_user_id, $points_to_withdraw, $method, $details);
                $stmt_insert_withdrawal->execute();
                $stmt_insert_withdrawal->close();
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'data' => getUserData($conn, $telegram_user_id)]);
            } else {
                $stmt_deduct->close();
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Withdrawal failed. Points may have changed.', 'data' => getUserData($conn, $telegram_user_id)]);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Withdrawal error for user $telegram_user_id: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'An error occurred processing withdrawal.']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        break;
}

if ($conn) {
    $conn->close();
}
?>
