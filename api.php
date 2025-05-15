<?php
header('Content-Type: application/json');
require_once 'db_config.php'; // Includes $pdo and constants

// --- Utility Functions ---
function getTelegramUserData() {
    if (isset($_GET['tg_user_data'])) {
        $userData = json_decode($_GET['tg_user_data'], true);
        if ($userData && isset($userData['id'])) {
            return $userData;
        }
    }
    return null;
}

function sendJsonResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function getCurrentUtcDate() {
    return gmdate('Y-m-d');
}

function getCurrentUtcTimestamp() {
    return gmdate('Y-m-d H:i:s');
}

// Fetch user by Telegram ID, creates if not exists
function getOrCreateUser($tgUserData, $referrerTgId = null) {
    global $pdo;

    if (!$tgUserData || !isset($tgUserData['id'])) {
        return null;
    }
    $telegram_user_id = $tgUserData['id'];
    $username = isset($tgUserData['username']) ? $tgUserData['username'] : null;
    $first_name = isset($tgUserData['first_name']) ? $tgUserData['first_name'] : ($username ?: 'User');

    // Try to fetch user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_user_id = ?");
    $stmt->execute([$telegram_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $isNewUser = false;
    if (!$user) {
        $isNewUser = true;
        $referred_by_id_to_store = null;
        if ($referrerTgId && $referrerTgId != $telegram_user_id) { // Check if referrerTgId is not the user themselves
            // Find referrer's internal ID
            $refStmt = $pdo->prepare("SELECT id FROM users WHERE telegram_user_id = ?");
            $refStmt->execute([$referrerTgId]);
            $referrerUser = $refStmt->fetch(PDO::FETCH_ASSOC);
            if ($referrerUser) {
                $referred_by_id_to_store = $referrerTgId; // Store telegram_user_id of referrer
            }
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO users (telegram_user_id, username, first_name, max_energy, energy_refill_rate_per_second, max_clicks_per_day, max_ads_per_day, referred_by_telegram_user_id, last_energy_update, last_click_date_utc, last_ad_day_utc, last_tasks_reset_date_utc) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, ?)
        ");
        $currentUtcDate = getCurrentUtcDate();
        $insertStmt->execute([
            $telegram_user_id, $username, $first_name, 
            DEFAULT_MAX_ENERGY, ENERGY_REFILL_RATE_PER_SECOND, MAX_CLICKS_PER_DAY, MAX_ADS_PER_DAY, 
            $referred_by_id_to_store, $currentUtcDate, $currentUtcDate, $currentUtcDate
        ]);
        $userId = $pdo->lastInsertId();

        // Award points to referrer if new user and valid referrer
        if ($referred_by_id_to_store) {
            $updateRefPointsStmt = $pdo->prepare("UPDATE users SET points = points + ? WHERE telegram_user_id = ?");
            $updateRefPointsStmt->execute([POINTS_PER_REFERRAL, $referred_by_id_to_store]);
        }
        
        $stmt->execute([$telegram_user_id]); // Re-fetch the newly created user
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Add bot username and points_per_tap and points_per_ad to user data dynamically for frontend
    $user['bot_username'] = TELEGRAM_BOT_USERNAME;
    $user['points_per_tap'] = POINTS_PER_TAP;
    $user['points_per_ad'] = POINTS_PER_AD_WATCH;

    // Count total referrals
    $refCountStmt = $pdo->prepare("SELECT COUNT(*) as total_referrals FROM users WHERE referred_by_telegram_user_id = ?");
    $refCountStmt->execute([$user['telegram_user_id']]);
    $referralData = $refCountStmt->fetch(PDO::FETCH_ASSOC);
    $user['total_referrals'] = $referralData['total_referrals'] ?? 0;


    return $user;
}

// Update energy based on time passed
function updateUserEnergy($user) {
    global $pdo;
    $currentTime = time();
    $lastUpdate = strtotime($user['last_energy_update']);
    $timeDiffSeconds = $currentTime - $lastUpdate;

    if ($timeDiffSeconds > 0 && $user['energy'] < $user['max_energy']) {
        $energyGained = floor($timeDiffSeconds * $user['energy_refill_rate_per_second']);
        if ($energyGained > 0) {
            $newEnergy = min($user['max_energy'], $user['energy'] + $energyGained);
            $stmt = $pdo->prepare("UPDATE users SET energy = ?, last_energy_update = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$newEnergy, $user['id']]);
            $user['energy'] = $newEnergy;
            $user['last_energy_update'] = getCurrentUtcTimestamp(); // Reflect update
        }
    }
    return $user;
}

// Check and reset daily limits
function checkAndResetDailyLimits($user) {
    global $pdo;
    $currentUtcDate = getCurrentUtcDate();
    $updateFields = [];
    $updateParams = [];

    // Clicks
    if ($user['last_click_date_utc'] != $currentUtcDate) {
        $updateFields[] = "clicks_today = 0";
        $updateFields[] = "last_click_date_utc = ?";
        $updateParams[] = $currentUtcDate;
        $user['clicks_today'] = 0;
        $user['last_click_date_utc'] = $currentUtcDate;
    }

    // Ads
    if ($user['last_ad_day_utc'] != $currentUtcDate) {
        $updateFields[] = "ads_watched_today = 0";
        $updateFields[] = "last_ad_day_utc = ?";
        $updateParams[] = $currentUtcDate;
        $user['ads_watched_today'] = 0;
        $user['last_ad_day_utc'] = $currentUtcDate;
    }
    
    // Tasks
    if ($user['last_tasks_reset_date_utc'] != $currentUtcDate) {
        $updateFields[] = "tasks_completed_today = NULL"; // Reset to empty JSON or NULL
        $updateFields[] = "last_tasks_reset_date_utc = ?";
        $updateParams[] = $currentUtcDate;
        $user['tasks_completed_today'] = null;
        $user['last_tasks_reset_date_utc'] = $currentUtcDate;
    }


    if (!empty($updateFields)) {
        $updateQuery = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateParams[] = $user['id'];
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($updateParams);
    }
    return $user;
}


// --- API Actions ---
$action = isset($_GET['action']) ? $_GET['action'] : '';
$tgUserData = getTelegramUserData();

if (!$tgUserData && !in_array($action, ['some_public_action_if_any'])) { // Allow certain actions without tg_user_data if needed
    sendJsonResponse(false, 'Telegram user data not found or invalid.');
}

// For most actions, we need the user object.
$user = null;
if ($tgUserData) {
    $referrerId = isset($_GET['referrer_id']) ? $_GET['referrer_id'] : null;
    $user = getOrCreateUser($tgUserData, $referrerId);
    if (!$user) {
        sendJsonResponse(false, 'Failed to get or create user profile.');
    }
    $user = updateUserEnergy($user);
    $user = checkAndResetDailyLimits($user); // Check and reset daily counters
}


switch ($action) {
    case 'init_user':
        if ($user) {
            sendJsonResponse(true, 'User initialized successfully.', $user);
        } else {
            sendJsonResponse(false, 'Failed to initialize user.');
        }
        break;

    case 'tap':
        if (!$user) sendJsonResponse(false, 'User not found.');

        if ($user['energy'] < 1) {
            sendJsonResponse(false, 'Not enough energy.', $user);
        }
        if ($user['clicks_today'] >= $user['max_clicks_per_day']) {
            sendJsonResponse(false, 'Daily tap limit reached.', $user);
        }

        $newPoints = $user['points'] + POINTS_PER_TAP;
        $newEnergy = $user['energy'] - 1;
        $newClicksToday = $user['clicks_today'] + 1;

        $stmt = $pdo->prepare("UPDATE users SET points = ?, energy = ?, clicks_today = ?, last_energy_update = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$newPoints, $newEnergy, $newClicksToday, $user['id']])) {
            $user['points'] = $newPoints;
            $user['energy'] = $newEnergy;
            $user['clicks_today'] = $newClicksToday;
            $user['last_energy_update'] = getCurrentUtcTimestamp();
            sendJsonResponse(true, 'Tap successful!', $user);
        } else {
            sendJsonResponse(false, 'Failed to record tap.', $user);
        }
        break;

    case 'get_tasks':
        if (!$user) sendJsonResponse(false, 'User not found.');
        $stmt = $pdo->prepare("SELECT id, title, description, link, points_reward FROM tasks WHERE active = TRUE ORDER BY id ASC");
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completedToday = $user['tasks_completed_today'] ? json_decode($user['tasks_completed_today'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            $completedToday = []; // Handle potential JSON decode error
        }

        sendJsonResponse(true, 'Tasks fetched.', ['tasks' => $tasks, 'completed_today' => $completedToday]);
        break;

    case 'complete_task':
        if (!$user) sendJsonResponse(false, 'User not found.');
        $taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;

        if ($taskId <= 0) {
            sendJsonResponse(false, 'Invalid task ID.', ['user_data' => $user]);
        }

        $stmt = $pdo->prepare("SELECT points_reward FROM tasks WHERE id = ? AND active = TRUE");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            sendJsonResponse(false, 'Task not found or not active.', ['user_data' => $user]);
        }

        $completedToday = $user['tasks_completed_today'] ? json_decode($user['tasks_completed_today'], true) : [];
        if (json_last_error() !== JSON_ERROR_NONE) $completedToday = [];

        if (in_array($taskId, $completedToday)) {
            sendJsonResponse(false, 'Task already completed today.', ['user_data' => $user]);
        }

        $completedToday[] = $taskId;
        $newPoints = $user['points'] + $task['points_reward'];

        $updateStmt = $pdo->prepare("UPDATE users SET points = ?, tasks_completed_today = ? WHERE id = ?");
        if ($updateStmt->execute([$newPoints, json_encode($completedToday), $user['id']])) {
            $user['points'] = $newPoints;
            $user['tasks_completed_today'] = json_encode($completedToday); // Update local user object
            sendJsonResponse(true, 'Task completed!', ['user_data' => $user, 'reward' => $task['points_reward']]);
        } else {
            sendJsonResponse(false, 'Failed to update task completion.', ['user_data' => $user]);
        }
        break;

    case 'watched_ad':
        if (!$user) sendJsonResponse(false, 'User not found.');

        if ($user['ads_watched_today'] >= $user['max_ads_per_day']) {
            sendJsonResponse(false, 'Daily ad limit reached.', $user);
        }

        // Check ad cooldown (3 minutes)
        if ($user['last_ad_watched_timestamp']) {
            $lastAdTime = strtotime($user['last_ad_watched_timestamp']);
            $currentTime = time();
            if (($currentTime - $lastAdTime) < (AD_COOLDOWN_MINUTES * 60)) {
                $cooldownRemaining = (AD_COOLDOWN_MINUTES * 60) - ($currentTime - $lastAdTime);
                $user['next_ad_available_at'] = date('Y-m-d H:i:s', $currentTime + $cooldownRemaining);
                sendJsonResponse(false, 'Please wait for ad cooldown.', $user);
            }
        }

        $newPoints = $user['points'] + POINTS_PER_AD_WATCH;
        $newAdsWatchedToday = $user['ads_watched_today'] + 1;
        $currentUtcTimestamp = getCurrentUtcTimestamp();

        $stmt = $pdo->prepare("UPDATE users SET points = ?, ads_watched_today = ?, last_ad_watched_timestamp = ? WHERE id = ?");
        if ($stmt->execute([$newPoints, $newAdsWatchedToday, $currentUtcTimestamp, $user['id']])) {
            $user['points'] = $newPoints;
            $user['ads_watched_today'] = $newAdsWatchedToday;
            $user['last_ad_watched_timestamp'] = $currentUtcTimestamp;
            $user['next_ad_available_at'] = date('Y-m-d H:i:s', time() + (AD_COOLDOWN_MINUTES * 60));
            sendJsonResponse(true, 'Ad reward claimed!', $user);
        } else {
            sendJsonResponse(false, 'Failed to claim ad reward.', $user);
        }
        break;

    case 'request_withdrawal':
        if (!$user) sendJsonResponse(false, 'User not found.');

        $amount = isset($_GET['amount']) ? intval($_GET['amount']) : 0;
        $method = isset($_GET['method']) ? trim($_GET['method']) : '';
        $detailsJson = isset($_GET['details']) ? $_GET['details'] : '{}';
        $details = json_decode($detailsJson, true);

        $validAmounts = [85000, 160000, 300000];
        if (!in_array($amount, $validAmounts)) {
            sendJsonResponse(false, 'Invalid withdrawal amount selected.', ['user_data' => $user]);
        }
        if (empty($method) || !in_array($method, ['UPI', 'Binance'])) {
            sendJsonResponse(false, 'Invalid withdrawal method.', ['user_data' => $user]);
        }
        if (empty($details)) {
            sendJsonResponse(false, 'Withdrawal details are required.', ['user_data' => $user]);
        }
        if ($method === 'UPI' && empty($details['upi_id'])) {
             sendJsonResponse(false, 'UPI ID is required for UPI withdrawal.', ['user_data' => $user]);
        }
        if ($method === 'Binance' && empty($details['binance_address'])) {
             sendJsonResponse(false, 'Binance Address is required for Binance withdrawal.', ['user_data' => $user]);
        }


        if ($user['points'] < $amount) {
            sendJsonResponse(false, 'Insufficient points for withdrawal.', ['user_data' => $user]);
        }

        $pdo->beginTransaction();
        try {
            // Deduct points
            $newPoints = $user['points'] - $amount;
            $updatePointsStmt = $pdo->prepare("UPDATE users SET points = ? WHERE id = ? AND points >= ?");
            $updatePointsStmt->execute([$newPoints, $user['id'], $amount]);

            if ($updatePointsStmt->rowCount() == 0) {
                $pdo->rollBack(); // Should not happen if initial check passed, but good for concurrency
                sendJsonResponse(false, 'Failed to deduct points. Insufficient balance or error.', ['user_data' => $user]);
            }

            // Insert withdrawal request
            $insertWithdrawalStmt = $pdo->prepare("
                INSERT INTO withdrawals (user_id, points_withdrawn, method, details, status) 
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $insertWithdrawalStmt->execute([$user['id'], $amount, $method, $detailsJson]);
            
            $pdo->commit();
            $user['points'] = $newPoints; // Update user object for response
            sendJsonResponse(true, 'Withdrawal request submitted successfully.', ['user_data' => $user]);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Withdrawal error: " . $e->getMessage());
            sendJsonResponse(false, 'An error occurred during withdrawal request. Please try again.', ['user_data' => $user]);
        }
        break;

    case 'get_withdrawal_history':
        if (!$user) sendJsonResponse(false, 'User not found.');
        $stmt = $pdo->prepare("SELECT points_withdrawn, method, status, requested_at FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20");
        $stmt->execute([$user['id']]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendJsonResponse(true, 'Withdrawal history fetched.', $history);
        break;

    default:
        sendJsonResponse(false, 'Invalid action specified.');
        break;
}

?>
