-- Database: `if0_38992815_watchearn_db`

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `users`
--
CREATE TABLE `users` (
  `user_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) DEFAULT NULL,
  `points` BIGINT UNSIGNED DEFAULT 0,
  `energy` INT DEFAULT 100,
  `max_energy` INT DEFAULT 100,
  `energy_per_tap` INT DEFAULT 1,
  `energy_refill_rate_seconds` INT DEFAULT 30, -- Seconds to refill 1 energy point
  `last_energy_update_ts` BIGINT DEFAULT 0, -- Store as UNIX timestamp for easier calculation
  `daily_clicks_count` INT DEFAULT 0,
  `max_daily_clicks` INT DEFAULT 2500,
  `last_click_reset_date_utc` DATE DEFAULT NULL,
  `referred_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `total_referrals` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_telegram_id` (`telegram_id`),
  FOREIGN KEY (`referred_by_user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `withdrawals`
--
CREATE TABLE `withdrawals` (
  `withdrawal_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `points_withdrawn` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(50) NOT NULL, -- e.g., 'UPI', 'Binance'
  `details` TEXT NOT NULL, -- e.g., UPI ID, Binance ID
  `status` ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
  `withdrawal_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`withdrawal_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Table structure for table `tasks`
--
CREATE TABLE `tasks` (
  `task_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `link` VARCHAR(255) NOT NULL,
  `points_reward` INT UNSIGNED NOT NULL,
  `is_daily` BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `tasks`
--
INSERT INTO `tasks` (`name`, `description`, `link`, `points_reward`, `is_daily`) VALUES
('Join Telegram Channel 1', 'Join our official news channel.', 'https://t.me/WatchClickEarn', 50, TRUE),
('Join Telegram Group', 'Join our community group.', 'https://t.me/WatchClickEarnchat', 50, TRUE),
('Join Telegram Channel 2', 'Join our partner channel.', 'https://t.me/earningsceret', 50, TRUE),
('Join Telegram Channel 3', 'Join another partner channel.', 'https://t.me/ShopEarnHub4102h', 50, TRUE);

--
-- Table structure for table `user_tasks_completed`
-- (Tracks completion of the set of 4 daily tasks)
--
CREATE TABLE `user_daily_task_sets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `completion_date_utc` DATE NOT NULL,
  `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_daily_task_set_unique` (`user_id`, `completion_date_utc`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


--
-- Table structure for table `user_ads_log`
--
CREATE TABLE `user_ads_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `watched_at_utc` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `points_earned` INT DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_user_ads_log_user_date` (`user_id`, `watched_at_utc`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
