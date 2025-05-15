<?php
header('Content-Type: application/json');
require_once 'db_config.php';

// --- Main Request Handler ---
if (!isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'No action specified.']);
    exit;
}

$action = $_POST['action'];
$telegram_init_data_str = isset($_POST['telegram_init_data']) ? $_POST['telegram_init_data'] : null;

// For actions requiring user data, parse and validate Telegram initData
$telegram_user_data = null;
if ($telegram_init_data_str) {
    // In a real app, you'd validate this string securely.
    // For now, we parse it to get user info.
    parse_str($telegram_init_data_str, $init_data_array);
    if (isset($init_data_array['user'])) {
        $telegram_user_data = json_decode($init_data_array['user'], true);
    }
}

// If telegram_user_data is null for protected actions, deny.
// init_user is special as it might be the first time we see a user.
if ($action !== 'init_user' && !$telegram_user_data) {
    echo json_encode(['success' => false, 'message' => 'User authentication failed. Please open via Telegram.']);
    exit;
}

$conn = getDBConnection();
$current_user_id = null; // Internal DB user ID
$current_telegram_id = null;

if ($telegram_user_data && isset($telegram_user_data['id'])) {
    $current_telegram_id = (int)$telegram_user_data['id'];
    // Fetch internal user ID if user already exists
    $stmt_get_user = $conn->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt_get_user->bind_param("i", $current_telegram_id);
    $stmt_get_user->execute();
    $result_user = $stmt_get_user->get_result();
    if ($user_row = $result_user->fetch_assoc()) {
        $current_user_id = $user_row['id'];
    }
    $stmt_get_user->close();
}


// --- Action Routing ---
switch ($action) {
    case 'init_user':
        handle_init_user($conn, $telegram_user_data, isset($_POST['start_param']) ? $_POST['start_param'] : null);
        break;
    case 'get_user_data': // Generic endpoint to refresh user data
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        $user_data = get_user_full_data($conn, $current_user_id);
        echo json_encode(['success' => true, 'data' => $user_data]);
        break;
    case 'tap':
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        handle_tap($conn, $current_user_id);
        break;
    case 'get_tasks':
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        handle_get_tasks($conn, $current_user_id);
        break;
    case 'complete_task':
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        handle_complete_task($conn, $current_user_id, $task_id);
        break;
    case 'watch_ad':
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        handle_watch_ad($conn, $current_user_id);
        break;
    case 'submit_withdrawal':
        if (!$current_user_id) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
        $method = isset($_POST['method']) ? $_POST['method'] : '';
        $details = isset($_POST['details']) ? $_POST['details'] : '';
        handle_submit_withdrawal($conn, $current_user_id, $amount, $method, $details);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

$conn->close();

// --- Handler Functions ---

function handle_init_user($conn, $telegram_user_data, $start_param) {
    if (!$telegram_user_data || !isset($telegram_user_data['id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid Telegram data.']);
        return;
    }

    $telegram_id = (int)$telegram_user_data['id'];
    $username = isset($telegram_user_data['username']) ? $telegram_user_data['username'] : null;
    $first_name = isset($telegram_user_data['first_name']) ? $telegram_user_data['first_name'] : null;

    $stmt = $conn->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->bind_param("i", $telegram_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_db_id = null;

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_db_id = $user['id'];
        // Optionally update username/firstname if changed
        $updateStmt = $conn->prepare("UPDATE users SET username = ?, first_name = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("ssi", $username, $first_name, $user_db_id);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        // New user
        $referred_by_user_id = null;
        if ($start_param) {
            // $start_param is the telegram_id of the referrer
            $referrer_telegram_id = (int)$start_param;
            if ($referrer_telegram_id !== $telegram_id) { // Cannot refer self
                $stmt_ref = $conn->prepare("SELECT id FROM users WHERE telegram_id = ?");
                $stmt_ref->bind_param("i", $referrer_telegram_id);
                $stmt_ref->execute();
                $result_ref = $stmt_ref->get_result();
                if ($ref_user = $result_ref->fetch_assoc()) {
                    $referred_by_user_id = $ref_user['id'];
                }
                $stmt_ref->close();
            }
        }

        $insertStmt = $conn->prepare("INSERT INTO users (telegram_id, username, first_name, max_energy, referred_by_user_id, join_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())");
        $max_energy_val = INITIAL_MAX_ENERGY;
        $insertStmt->bind_param("issii", $telegram_id, $username, $first_name, $max_energy_val, $referred_by_user_id);
        if ($insertStmt->execute()) {
            $user_db_id = $conn->insert_id;
            if ($referred_by_user_id) {
                // Award points to referrer and log referral
                $conn->query("UPDATE users SET points = points + " . POINTS_PER_REFERRAL . " WHERE id = $referred_by_user_id");
                $conn->query("INSERT INTO referrals (referrer_user_id, referred_user_id) VALUES ($referred_by_user_id, $user_db_id)");
            }
        } else {
            error_log("Failed to insert new user: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Error creating user profile.']);
            return;
        }
        $insertStmt->close();
    }
    $stmt->close();

    if ($user_db_id) {
        $user_data = get_user_full_data($conn, $user_db_id);
        echo json_encode(['success' => true, 'data' => $user_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not initialize user.']);
    }
}


function get_user_full_data($conn, $user_db_id) {
    // Regenerate energy first
    regenerate_energy($conn, $user_db_id);

    $stmt = $conn->prepare("SELECT u.*, COUNT(r.id) as total_referrals 
                            FROM users u 
                            LEFT JOIN referrals r ON u.id = r.referrer_user_id
                            WHERE u.id = ?
                            GROUP BY u.id");
    $stmt->bind_param("i", $user_db_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) return null;

    // Reset daily counters if date has changed (UTC)
    $today_utc = gmdate('Y-m-d');

    if ($user['last_click_date'] != $today_utc) {
        $user['click_count_today'] = 0;
        $conn->query("UPDATE users SET click_count_today = 0, last_click_date = '$today_utc' WHERE id = $user_db_id");
    }
    if ($user['last_ad_watch_date'] != $today_utc) {
        $user['ads_watched_today'] = 0;
        $conn->query("UPDATE users SET ads_watched_today = 0, last_ad_watch_date = '$today_utc' WHERE id = $user_db_id");
    }

    // Add config values to user data for frontend use
    $user['points_per_tap_config'] = POINTS_PER_TAP;
    $user['max_clicks_per_day_config'] = MAX_CLICKS_PER_DAY;
    $user['points_per_ad_config'] = POINTS_PER_AD;
    $user['max_ads_per_day_config'] = MAX_ADS_PER_DAY;
    $user['energy_regen_rate_per_minute_config'] = ENERGY_REGEN_RATE_PER_MINUTE;
    $user['WatchClickEarn_bot'] = BOT_USERNAME; // Send bot username for referral link

    // Ad cooldown status
    $user['ad_cooldown_active'] = false;
    $user['ad_cooldown_remaining_seconds'] = 0;
    if ($user['last_ad_reward_timestamp']) {
        $last_ad_time = strtotime($user['last_ad_reward_timestamp']);
        $cooldown_seconds = AD_COOLDOWN_MINUTES * 60;
        $time_since_last_ad = time() - $last_ad_time;
        if ($time_since_last_ad < $cooldown_seconds) {
            $user['ad_cooldown_active'] = true;
            $user['ad_cooldown_remaining_seconds'] = $cooldown_seconds - $time_since_last_ad;
        }
    }
    
    return $user;
}

function regenerate_energy($conn, $user_db_id) {
    $stmt = $conn->prepare("SELECT energy, max_energy, last_energy_update FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_db_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_energy_data = $result->fetch_assoc();
    $stmt->close();

    if (!$user_energy_data || $user_energy_data['energy'] >= $user_energy_data['max_energy']) {
        return; // No need to regenerate or user not found
    }

    $now = time();
    $last_update_ts = strtotime($user_energy_data['last_energy_update']);
    $seconds_passed = $now - $last_update_ts;

    if ($seconds_passed <= 0) return;

    $energy_per_second = ENERGY_REGEN_RATE_PER_MINUTE / 60.0;
    $energy_to_add = floor($seconds_passed * $energy_per_second);

    if ($energy_to_add > 0) {
        $new_energy = $user_energy_data['energy'] + $energy_to_add;
        if ($new_energy > $user_energy_data['max_energy']) {
            $new_energy = $user_energy_data['max_energy'];
        }
        
        // Only update if energy actually changed
        if ($new_energy != $user_energy_data['energy']) {
            $updateStmt = $conn->prepare("UPDATE users SET energy = ?, last_energy_update = NOW() WHERE id = ?");
            $updateStmt->bind_param("ii", $new_energy, $user_db_id);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
}


function handle_tap($conn, $user_db_id) {
    $user = get_user_full_data($conn, $user_db_id); // Gets latest data including daily resets & energy regen

    if ($user['energy'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'Not enough energy.', 'data' => $user]);
        return;
    }
    if ($user['click_count_today'] >= MAX_CLICKS_PER_DAY) {
        echo json_encode(['success' => false, 'message' => 'Daily click limit reached.', 'data' => $user]);
        return;
    }

    $new_energy = $user['energy'] - 1;
    $new_points = $user['points'] + POINTS_PER_TAP;
    $new_click_count = $user['click_count_today'] + 1;

    $stmt = $conn->prepare("UPDATE users SET energy = ?, points = ?, click_count_today = ?, last_energy_update = NOW() WHERE id = ?");
    $stmt->bind_param("iiii", $new_energy, $new_points, $new_click_count, $user_db_id);
    if ($stmt->execute()) {
        // Fetch updated data to send back
        $updated_user_data = get_user_full_data($conn, $user_db_id);
        echo json_encode(['success' => true, 'message' => 'Tap successful!', 'data' => $updated_user_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tap failed. Please try again.', 'data' => $user]);
    }
    $stmt->close();
}

function handle_get_tasks($conn, $user_db_id) {
    $today_utc = gmdate('Y-m-d');
    $sql = "SELECT t.*, EXISTS (
                SELECT 1 FROM user_completed_tasks uct 
                WHERE uct.user_id = ? AND uct.task_id = t.id AND uct.completion_date = ?
            ) as completed_today
            FROM tasks t WHERE t.active = TRUE";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $user_db_id, $today_utc);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];
    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }
    $stmt->close();
    echo json_encode(['success' => true, 'tasks' => $tasks]);
}

function handle_complete_task($conn, $user_db_id, $task_id) {
    if (!$task_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid task ID.']);
        return;
    }

    // Check if task exists and get its reward
    $stmt_task = $conn->prepare("SELECT points_reward, is_daily_refresh FROM tasks WHERE id = ? AND active = TRUE");
    $stmt_task->bind_param("i", $task_id);
    $stmt_task->execute();
    $result_task = $stmt_task->get_result();
    $task_data = $result_task->fetch_assoc();
    $stmt_task->close();

    if (!$task_data) {
        echo json_encode(['success' => false, 'message' => 'Task not found or inactive.']);
        return;
    }
    $points_reward = $task_data['points_reward'];
    $is_daily = $task_data['is_daily_refresh'];
    $today_utc = gmdate('Y-m-d');

    // Check if already completed today (if daily) or ever (if not daily)
    $completion_check_sql = "SELECT id FROM user_completed_tasks WHERE user_id = ? AND task_id = ?";
    if ($is_daily) {
        $completion_check_sql .= " AND completion_date = ?";
    }
    $stmt_check = $conn->prepare($completion_check_sql);
    if ($is_daily) {
        $stmt_check->bind_param("iis", $user_db_id, $task_id, $today_utc);
    } else {
        $stmt_check->bind_param("ii", $user_db_id, $task_id);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Task already completed.', 'data' => get_user_full_data($conn, $user_db_id)]);
        $stmt_check->close();
        return;
    }
    $stmt_check->close();

    // Start transaction
    $conn->begin_transaction();
    try {
        $stmt_update_points = $conn->prepare("UPDATE users SET points = points + ? WHERE id = ?");
        $stmt_update_points->bind_param("ii", $points_reward, $user_db_id);
        $stmt_update_points->execute();
        $stmt_update_points->close();

        $stmt_log_task = $conn->prepare("INSERT INTO user_completed_tasks (user_id, task_id, completion_date) VALUES (?, ?, ?)");
        $stmt_log_task->bind_param("iis", $user_db_id, $task_id, $today_utc);
        $stmt_log_task->execute();
        $stmt_log_task->close();
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Task completed! Points awarded.', 'data' => get_user_full_data($conn, $user_db_id)]);
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log("Task completion transaction failed: " . $exception->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to complete task due to a server error.']);
    }
}

function handle_watch_ad($conn, $user_db_id) {
    $user = get_user_full_data($conn, $user_db_id); // Gets latest including daily resets

    if ($user['ads_watched_today'] >= MAX_ADS_PER_DAY) {
        echo json_encode(['success' => false, 'message' => 'Daily ad limit reached.', 'data' => $user]);
        return;
    }

    // Check cooldown
    if ($user['ad_cooldown_active']) {
        echo json_encode(['success' => false, 'message' => 'Ad cooldown active. Please wait.', 'data' => $user]);
        return;
    }

    $new_points = $user['points'] + POINTS_PER_AD;
    $new_ads_watched = $user['ads_watched_today'] + 1;
    $now_timestamp_for_db = gmdate('Y-m-d H:i:s'); // UTC timestamp

    $stmt = $conn->prepare("UPDATE users SET points = points + ?, ads_watched_today = ?, last_ad_reward_timestamp = ? WHERE id = ?");
    $stmt->bind_param("iisi", POINTS_PER_AD, $new_ads_watched, $now_timestamp_for_db, $user_db_id);
    
    if ($stmt->execute()) {
        $updated_user_data = get_user_full_data($conn, $user_db_id);
        echo json_encode(['success' => true, 'message' => 'Ad reward processed!', 'data' => $updated_user_data]);
    } else {
        error_log("Ad reward failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Failed to process ad reward.', 'data' => $user]);
    }
    $stmt->close();
}

function handle_submit_withdrawal($conn, $user_db_id, $amount, $method, $details) {
    if (!in_array($amount, [85000, 160000, 300000])) {
        echo json_encode(['success' => false, 'message' => 'Invalid withdrawal amount.']);
        return;
    }
    if (empty($method) || empty($details)) {
        echo json_encode(['success' => false, 'message' => 'Withdrawal method and details are required.']);
        return;
    }
    if (strlen($details) > 500) { // Basic validation
        echo json_encode(['success' => false, 'message' => 'Details too long.']);
        return;
    }

    $user = get_user_full_data($conn, $user_db_id);
    if ($user['points'] < $amount) {
        echo json_encode(['success' => false, 'message' => 'Not enough points.', 'data' => $user]);
        return;
    }

    // Start transaction
    $conn->begin_transaction();
    try {
        $stmt_deduct = $conn->prepare("UPDATE users SET points = points - ? WHERE id = ? AND points >= ?");
        $stmt_deduct->bind_param("iii", $amount, $user_db_id, $amount);
        $stmt_deduct->execute();

        if ($stmt_deduct->affected_rows > 0) {
            $stmt_insert_withdrawal = $conn->prepare("INSERT INTO withdrawals (user_id, points_withdrawn, method, details, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt_insert_withdrawal->bind_param("iiss", $user_db_id, $amount, $method, $details);
            $stmt_insert_withdrawal->execute();
            $stmt_insert_withdrawal->close();
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Withdrawal request submitted.', 'data' => get_user_full_data($conn, $user_db_id)]);
        } else {
            $conn->rollback(); // Points might have changed since check
            echo json_encode(['success' => false, 'message' => 'Failed to process withdrawal. Insufficient points or error.', 'data' => get_user_full_data($conn, $user_db_id)]);
        }
        $stmt_deduct->close();

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        error_log("Withdrawal transaction failed: " . $exception->getMessage());
        echo json_encode(['success' => false, 'message' => 'Withdrawal request failed due to a server error.']);
    }
}

?>
