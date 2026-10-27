-- 0001_init.sql — baseline schema of the Research Section database.
-- Consolidated from the old config/*.sql dumps (newest version of each table).
-- Safe on existing databases (IF NOT EXISTS). To only record it as applied:
--   php tools/migrate.php --baseline

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(50) NOT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `avatar_url` varchar(255) DEFAULT 'assets/img/default-avatar.png',
  `user_type` varchar(50) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `used` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `action_description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `m1_measurecreation` (
  `m1_MC_ID` int(11) NOT NULL AUTO_INCREMENT,
  `m1_MC_code` varchar(120) NOT NULL,
  `id` int(11) DEFAULT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `district` varchar(255) DEFAULT NULL,
  `date_created` date NOT NULL DEFAULT curdate(),
  `measure_type` varchar(120) DEFAULT NULL,
  `measure_title` text DEFAULT NULL,
  `measure_content` longtext DEFAULT NULL,
  `introducers` text DEFAULT NULL,
  `measure_status` varchar(100) DEFAULT NULL,
  `checking_remarks_pending` varchar(255) DEFAULT NULL,
  `checking_remarks_approved` varchar(255) DEFAULT NULL,
  `checking_notes` varchar(255) DEFAULT NULL,
  `docket_no` varchar(255) DEFAULT NULL,
  `sp_no` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `measure_file` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`m1_MC_ID`),
  UNIQUE KEY `m1_MC_Code` (`m1_MC_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `m1_measurecreation_copy` (
  `copy_id` int(30) NOT NULL AUTO_INCREMENT,
  `m1_MC_ID` int(30) DEFAULT NULL,
  `m1_MC_code` varchar(50) DEFAULT NULL,
  `date_created` date DEFAULT NULL,
  `measure_type` varchar(30) DEFAULT NULL,
  `measure_title` text DEFAULT NULL,
  `measure_content` longtext DEFAULT NULL,
  `introducers` text DEFAULT NULL,
  `measure_status` varchar(25) DEFAULT NULL,
  `checking_remarks_pending` varchar(255) DEFAULT NULL,
  `checking_remarks_approved` varchar(255) DEFAULT NULL,
  `checking_notes` text DEFAULT NULL,
  `checked_by` varchar(255) DEFAULT NULL,
  `datetime_submitted` datetime DEFAULT NULL,
  `received_datetime` datetime DEFAULT current_timestamp(),
  `fullname` varchar(255) DEFAULT NULL,
  `district` varchar(255) DEFAULT NULL,
  `committee` varchar(255) DEFAULT NULL,
  `year_cc` varchar(255) DEFAULT NULL,
  `measure_file` varchar(500) DEFAULT NULL,
  `docket_no` varchar(255) DEFAULT NULL,
  `editing_status` varchar(255) DEFAULT NULL,
  `MFL_Name` varchar(255) DEFAULT NULL,
  `MFL_Feedback` text DEFAULT NULL,
  `sp_no` varbinary(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`copy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `m9_similaritychecking` (
  `m9_SC_ID` int(30) unsigned NOT NULL AUTO_INCREMENT,
  `m9_SC_Code` varchar(50) DEFAULT NULL,
  `m1_MC_ID` int(30) DEFAULT NULL,
  `m1_MC_code` varchar(50) DEFAULT NULL,
  `date_created` date DEFAULT NULL,
  `measure_type` varchar(30) DEFAULT NULL,
  `measure_title` text DEFAULT NULL,
  `measure_content` longtext DEFAULT NULL,
  `introducers` text DEFAULT NULL,
  `measure_status` varchar(155) DEFAULT NULL,
  `checking_remarks_pending` text DEFAULT NULL,
  `checking_remarks_approved` text DEFAULT NULL,
  `checking_notes` text DEFAULT NULL,
  `checked_by` varchar(255) DEFAULT NULL,
  `datetime_submitted` datetime DEFAULT NULL,
  `docket_no` varchar(255) DEFAULT NULL,
  `sp_no` varchar(255) DEFAULT NULL,
  `year_cc` varchar(255) DEFAULT NULL,
  `measure_file` varchar(500) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'pending',
  `last_synced_at` datetime DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  PRIMARY KEY (`m9_SC_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `m6_measuredocketing_fromresearch` (
  `m6_MD_ID` int(11) NOT NULL AUTO_INCREMENT,
  `m6_MD_Code` varchar(30) DEFAULT NULL,
  `m9_SC_ID` int(11) DEFAULT NULL,
  `m9_SC_Code` varchar(30) DEFAULT NULL,
  `m1_MC_ID` int(11) DEFAULT NULL,
  `m1_MC_code` varchar(50) DEFAULT NULL,
  `date_created` date DEFAULT NULL,
  `measure_type` varchar(30) DEFAULT NULL,
  `measure_title` varchar(255) DEFAULT NULL,
  `measure_content` longtext DEFAULT NULL,
  `introducers` varchar(255) DEFAULT NULL,
  `measure_status` varchar(25) DEFAULT NULL,
  `checking_remarks` varchar(30) DEFAULT NULL,
  `checking_notes` varchar(255) DEFAULT NULL,
  `checked_by` varchar(255) DEFAULT NULL,
  `datetime_submitted` datetime DEFAULT NULL,
  `docket_no` varchar(255) DEFAULT NULL,
  `MFL_Name` varchar(255) DEFAULT NULL,
  `MFL_Feedback` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`m6_MD_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `m9_measure_reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `m9_SC_ID` int(11) NOT NULL,
  `review_stage` enum('1st_review','2nd_review','final_review') NOT NULL,
  `review_notes` text DEFAULT NULL,
  `reviewed_by` varchar(255) NOT NULL,
  `reviewed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`review_id`),
  KEY `idx_measure_id` (`m9_SC_ID`),
  KEY `idx_review_stage` (`review_stage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `research_inbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payload_json` longtext DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `stored_filename` varchar(255) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `remote_addr` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
