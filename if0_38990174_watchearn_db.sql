-- Database: if0_38990174_watchearn_db (You'll create this name in InfinityFree's control panel)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Table structure for table `users`
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) DEFAULT NULL,
  `points` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `energy` INT NOT NULL DEFAULT 100,
  `max_energy` INT NOT NULL DEFAULT 100, -- Default max energy
  `energy_refill_rate_per_second` DECIMAL(5,2) NOT NULL DEFAULT 0.33, -- Approx 1 energy every 3 seconds
  `last_energy_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `clicks_today` INT NOT NULL DEFAULT 0,
  `max_clicks_per_day` INT NOT NULL DEFAULT 2500,
  `last_click_date_utc` DATE DEFAULT NULL,
  `ads_watched_today` INT NOT NULL DEFAULT 0,
  `max_ads_per_day` INT NOT NULL DEFAULT 45,
  `last_ad_watched_timestamp` TIMESTAMP NULL DEFAULT NULL,
  `last_ad_day_utc` DATE DEFAULT NULL,
  `tasks_completed_today` JSON DEFAULT NULL, -- Stores array of completed task IDs, e.g., [1, 2]
  `last_tasks_reset_date_utc` DATE DEFAULT NULL,
  `referred_by_telegram_user_id` BIGINT UNSIGNED DEFAULT NULL, -- Stores the telegram_user_id of the referrer
  `referral_code` VARCHAR(50) AS (CAST(`telegram_user_id` AS CHAR)) STORED, -- Auto-generates referral code from telegram_user_id
  `join_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_telegram_user_id` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `withdrawals`
CREATE TABLE `withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL, -- FK to users.id
  `points_withdrawn` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(50) NOT NULL, -- e.g., 'UPI', 'Binance'
  `details` TEXT NOT NULL, -- e.g., UPI ID, Binance Address + Network
  `status` ENUM('pending', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id_status` (`user_id`, `status`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `tasks`
CREATE TABLE `tasks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `link` VARCHAR(512) NOT NULL,
  `points_reward` INT UNSIGNED NOT NULL DEFAULT 50,
  `is_daily` BOOLEAN NOT NULL DEFAULT TRUE,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-populate tasks
INSERT INTO `tasks` (`id`, `title`, `description`, `link`, `points_reward`, `is_daily`, `active`) VALUES
(1, 'Join Telegram Channel 1', 'Join our official news channel.', 'https://t.me/WatchClickEarn', 50, TRUE, TRUE),
(2, 'Join Telegram Group', 'Become a member of our community group.', 'https://t.me/WatchClickEarnchat', 50, TRUE, TRUE),
(3, 'Join Telegram Channel 2', 'Subscribe to our partner channel.', 'https://t.me/ShopEarnHub4102h', 50, TRUE, TRUE),
(4, 'Join Telegram Channel 3', 'Get updates from this channel.', 'https://t.me/earningsceret', 50, TRUE, TRUE);

-- Add password_hash column as requested, though not used for TWA login
ALTER TABLE `users` ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `first_name`;

COMMIT;
