-- Database: `if0_38992815_watchearn_db`

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `users`
--
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `points` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `energy` INT NOT NULL DEFAULT 100,
  `max_energy` INT NOT NULL DEFAULT 100,
  `last_energy_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `click_count_today` INT NOT NULL DEFAULT 0,
  `last_click_date` DATE NULL,
  `ads_watched_today` INT NOT NULL DEFAULT 0,
  `last_ad_watch_date` DATE NULL,
  `last_ad_reward_timestamp` TIMESTAMP NULL,
  `referred_by_user_id` BIGINT UNSIGNED NULL COMMENT 'Internal ID of the referrer user',
  `join_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_id_unique` (`telegram_id`),
  KEY `idx_referred_by_user_id` (`referred_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `withdrawals`
--
CREATE TABLE `withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `points_withdrawn` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `status` ENUM('pending','processing','completed','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id_withdrawals` (`user_id`),
  CONSTRAINT `fk_withdrawals_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `tasks`
--
CREATE TABLE `tasks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `link` VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `points_reward` INT NOT NULL DEFAULT 0,
  `type` ENUM('telegram_join','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'telegram_join',
  `is_daily_refresh` BOOLEAN NOT NULL DEFAULT TRUE,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `user_completed_tasks`
--
CREATE TABLE `user_completed_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `task_id` INT NOT NULL,
  `completion_date` DATE NOT NULL COMMENT 'UTC date of completion',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_task_daily` (`user_id`,`task_id`,`completion_date`),
  KEY `idx_user_id_completed_tasks` (`user_id`),
  KEY `idx_task_id_completed_tasks` (`task_id`),
  CONSTRAINT `fk_completed_tasks_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_completed_tasks_task_id` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `referrals`
--
CREATE TABLE `referrals` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `referrer_user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Internal ID of user who referred',
  `referred_user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Internal ID of user who was referred',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_referral_pair` (`referrer_user_id`, `referred_user_id`),
  CONSTRAINT `fk_referrals_referrer_id` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_referrals_referred_id` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Add default tasks
--
INSERT INTO `tasks` (`name`, `description`, `link`, `points_reward`, `type`, `is_daily_refresh`, `active`) VALUES
('Join Telegram Channel 1', 'Join our main Telegram channel for updates.', 'https://t.me/WatchClickEarn', 50, 'telegram_join', TRUE, TRUE),
('Join Telegram Group', 'Join our community group.', 'https://t.me/WatchClickEarnchat', 50, 'telegram_join', TRUE, TRUE),
('Join Telegram Channel 2', 'Join our partner channel.', 'https://t.me/earningsceret', 50, 'telegram_join', TRUE, TRUE),
('Join Telegram Channel 3', 'Join for more news.', 'https://t.me/ShopEarnHub4102h', 50, 'telegram_join', TRUE, TRUE);

COMMIT;
