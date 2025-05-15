-- if0_38990174_watchearn_db.sql

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT UNSIGNED NOT NULL,
  `unique_app_id` VARCHAR(32) NOT NULL,
  `username` VARCHAR(255) DEFAULT NULL,
  `first_name` VARCHAR(255) DEFAULT NULL,
  `points` BIGINT UNSIGNED DEFAULT 0,
  `energy` INT DEFAULT 100,
  `max_energy` INT DEFAULT 100,
  `energy_per_tap` INT DEFAULT 1,
  `energy_refill_rate_seconds` INT DEFAULT 3, -- Time in seconds to refill 1 energy point
  `last_energy_update_ts` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `daily_taps` INT DEFAULT 0,
  `max_daily_taps` INT DEFAULT 2500,
  `last_tap_date_utc` DATE DEFAULT NULL,
  `referred_by_app_id` VARCHAR(32) DEFAULT NULL,
  `total_referrals_verified` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_ad_watched_ts` TIMESTAMP NULL DEFAULT NULL,
  `daily_ads_watched_count` INT DEFAULT 0,
  `max_daily_ads` INT DEFAULT 35,
  `last_ads_reset_date_utc` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_user_id` (`telegram_user_id`),
  UNIQUE KEY `unique_app_id` (`unique_app_id`),
  KEY `idx_referred_by_app_id` (`referred_by_app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `withdrawals` (
  `withdrawal_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT UNSIGNED NOT NULL,
  `points_withdrawn` BIGINT UNSIGNED NOT NULL,
  `method` VARCHAR(50) NOT NULL,
  `details` TEXT NOT NULL, -- e.g., UPI ID, Binance Address + Memo
  `status` ENUM('pending','completed','failed') DEFAULT 'pending',
  `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`withdrawal_id`),
  KEY `idx_user_id_status` (`telegram_user_id`, `status`),
  CONSTRAINT `fk_withdrawal_user` FOREIGN KEY (`telegram_user_id`) REFERENCES `users` (`telegram_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `telegram_user_id` BIGINT UNSIGNED NOT NULL,
  `task_id` INT UNSIGNED NOT NULL, -- Corresponds to predefined task IDs (1, 2, 3, 4)
  `completion_date_utc` DATE NOT NULL,
  `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_task_daily` (`telegram_user_id`,`task_id`,`completion_date_utc`),
  CONSTRAINT `fk_usertask_user` FOREIGN KEY (`telegram_user_id`) REFERENCES `users` (`telegram_user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
