-- 0002 — tables that used to be created at request time, plus the new security tables.

-- Document requests received from other LGU2 systems (receive_document_request.php, document_management.php)
CREATE TABLE IF NOT EXISTS `document_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `measure_title` TEXT NOT NULL,
  `request_reason` TEXT NOT NULL,
  `status` ENUM('Pending','Processing','Approved','Rejected','Completed') DEFAULT 'Pending',
  `request_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `admin_notes` TEXT,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_request_date` (`request_date`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Raw payloads received by send_to_research.php
CREATE TABLE IF NOT EXISTS `research_measure` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `payload_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One-time-use guard for incoming SSO tokens (sso_login.php)
CREATE TABLE IF NOT EXISTS `sso_used_tokens` (
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`token_hash`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Failed login attempts for throttling (index.php)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_identifier_time` (`identifier`, `attempted_at`),
  INDEX `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reset tokens are now stored as SHA-256 hashes; old plain tokens can no longer be used.
DELETE FROM `password_resets` WHERE `used` = 0;
