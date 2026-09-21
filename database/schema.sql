-- ====================================================================
-- WhatsApp Bot Control Panel - Complete Database Schema
-- Compatible with MySQL 8.0+ / MariaDB 10.4+
-- ====================================================================

CREATE DATABASE IF NOT EXISTS `whatsapp_control_panel` 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `whatsapp_control_panel`;

-- --------------------------------------------------------------------
-- 1. Table: users (Administrators & Operators)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'operator') NOT NULL DEFAULT 'operator',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 2. Table: workers (Windows Python Workers)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `worker_uuid` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `status` ENUM('online', 'offline', 'disabled') NOT NULL DEFAULT 'offline',
  `whatsapp_status` ENUM('disconnected', 'qr_ready', 'authenticated', 'connected') NOT NULL DEFAULT 'disconnected',
  `current_job_id` INT UNSIGNED NULL DEFAULT NULL,
  `last_heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
  `os_info` VARCHAR(100) NULL DEFAULT NULL,
  `browser_info` VARCHAR(100) NULL DEFAULT NULL,
  `python_version` VARCHAR(50) NULL DEFAULT NULL,
  `worker_version` VARCHAR(50) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_workers_status_heartbeat` (`status`, `last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 3. Table: contacts (Consent-Based Messaging Recipients)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contacts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) NOT NULL UNIQUE,
  `company` VARCHAR(100) NULL DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_contacts_status` (`status`),
  INDEX `idx_contacts_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 4. Table: contact_groups
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_groups` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 5. Table: contact_group_members
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_group_members` (
  `group_id` INT UNSIGNED NOT NULL,
  `contact_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`, `contact_id`),
  CONSTRAINT `fk_cgm_group` FOREIGN KEY (`group_id`) REFERENCES `contact_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cgm_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 6. Table: message_templates
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(100) NOT NULL,
  `content` TEXT NOT NULL,
  `variables` VARCHAR(255) NULL DEFAULT '{{name}},{{phone}},{{company}}',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 7. Table: media_files (Attachments with Authenticated Access)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media_files` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL UNIQUE,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `uploaded_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_media_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 8. Table: campaigns
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `template_id` INT UNSIGNED NULL DEFAULT NULL,
  `media_id` INT UNSIGNED NULL DEFAULT NULL,
  `custom_message` TEXT NULL DEFAULT NULL,
  `status` ENUM('draft', 'queued', 'running', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
  `total_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
  `pending_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
  `processing_jobs` INT UNSIGNED NOT NULL DEFAULT 0,
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_campaigns_status` (`status`),
  INDEX `idx_campaigns_scheduled` (`scheduled_at`),
  CONSTRAINT `fk_camp_template` FOREIGN KEY (`template_id`) REFERENCES `message_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_camp_media` FOREIGN KEY (`media_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_camp_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 9. Table: message_jobs (Core Asynchronous Queue)
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_jobs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT UNSIGNED NULL DEFAULT NULL,
  `contact_id` INT UNSIGNED NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `rendered_message` TEXT NOT NULL,
  `media_id` INT UNSIGNED NULL DEFAULT NULL,
  `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `worker_id` INT UNSIGNED NULL DEFAULT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `claimed_at` TIMESTAMP NULL DEFAULT NULL,
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_jobs_claimable` (`status`, `scheduled_at`, `id`),
  INDEX `idx_jobs_worker` (`worker_id`, `status`),
  INDEX `idx_jobs_campaign` (`campaign_id`, `status`),
  CONSTRAINT `fk_jobs_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jobs_media` FOREIGN KEY (`media_id`) REFERENCES `media_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 10. Table: message_logs
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `job_id` INT UNSIGNED NOT NULL,
  `campaign_id` INT UNSIGNED NULL DEFAULT NULL,
  `worker_id` INT UNSIGNED NULL DEFAULT NULL,
  `recipient_phone` VARCHAR(30) NOT NULL,
  `status` ENUM('claimed', 'sent', 'failed', 'retrying') NOT NULL,
  `error_message` TEXT NULL DEFAULT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_msg_logs_job` (`job_id`),
  INDEX `idx_msg_logs_campaign` (`campaign_id`),
  INDEX `idx_msg_logs_status` (`status`),
  INDEX `idx_msg_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 11. Table: audit_logs
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `worker_id` INT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------
-- 12. Table: system_settings
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(64) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- INITIAL ACCOUNTS & SYSTEM SETTINGS
-- ====================================================================

-- 1. Default Administrator and Operator Accounts
-- Passwords:
-- admin    : Admin@123456
-- operator : Operator@123456
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `status`)
VALUES 
('admin', '$2y$10$GiS2C/VuiCwE2Ftwk/ndZuw7Q4e.K3ORkS4q68neEA27rtqGSh94O', 'System Administrator', 'admin', 'active'),
('operator', '$2y$10$lY1LuC//PMVVRZWN9Zr7..Xz3lCwsqdcLX1U9BOJDOEgsjarPPnGi', 'Standard Operator', 'operator', 'active')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- 2. System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`)
VALUES
('min_delay_seconds', '5', 'Minimum random delay between sent messages (seconds)'),
('max_delay_seconds', '15', 'Maximum random delay between sent messages (seconds)'),
('max_messages_per_batch', '20', 'Max messages sent before taking a batch pause'),
('max_messages_per_hour', '100', 'Safety ceiling for total messages per hour across workers'),
('max_messages_per_day', '500', 'Safety ceiling for total messages per day across workers'),
('pause_between_batches_seconds', '60', 'Pause duration after reaching batch limit (seconds)'),
('max_retry_attempts', '3', 'Maximum delivery attempts before permanent failure'),
('worker_heartbeat_timeout_seconds', '60', 'Time without heartbeat before worker is marked offline'),
('stale_job_timeout_seconds', '300', 'Threshold to re-queue processing jobs abandoned by dead workers'),
('default_country_code', '60', 'Default international calling code for local numbers (e.g. 60 for Malaysia)'),
('app_timezone', 'Asia/Kuala_Lumpur', 'User interface display timezone')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 3. Initial Sample Message Template
INSERT INTO `message_templates` (`title`, `content`, `variables`)
VALUES
('Welcome Notification', 'Hello {{name}},\nThank you for reaching out to {{company}}. Your inquiry has been received and our team will follow up with you shortly.', '{{name}},{{phone}},{{company}}'),
('Appointment Reminder', 'Hi {{name}},\nThis is a friendly reminder regarding your upcoming schedule with {{company}}. Please reply to confirm.', '{{name}},{{phone}},{{company}}')
ON DUPLICATE KEY UPDATE `variables` = VALUES(`variables`);
