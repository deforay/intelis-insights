-- ============================================================
-- Intelis Insights — Application-Level System Tables
-- ============================================================
-- These tables support the Intelis Insights web application:
-- chat sessions, messages, saved reports, and audit logging.
-- All tables live in the intelis_insights schema.
-- ============================================================

USE `intelis_insights`;

-- ----------------------------------------------------------
-- 1. Chat Sessions
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `chat_sessions` (
    `id` CHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(100),
    `title` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Chat conversation sessions';


-- ----------------------------------------------------------
-- 2. Chat Messages
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` CHAR(36) PRIMARY KEY,
    `session_id` CHAR(36) NOT NULL,
    `role` ENUM('user', 'assistant', 'system') NOT NULL,
    `content` TEXT NOT NULL,
    `plan_json` JSON DEFAULT NULL,
    `query_result_json` JSON DEFAULT NULL,
    `chart_json` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_session` (`session_id`),
    FOREIGN KEY (`session_id`) REFERENCES `chat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Individual messages within chat sessions';


-- ----------------------------------------------------------
-- 3. Saved Reports
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `reports` (
    `id` CHAR(36) PRIMARY KEY,
    `user_id` VARCHAR(100),
    `title` VARCHAR(255) NOT NULL,
    `plan_json` JSON NOT NULL,
    `chart_json` JSON DEFAULT NULL,
    `access_scope` VARCHAR(100) DEFAULT 'private',
    `pinned` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Saved analytical reports and dashboards';


-- ----------------------------------------------------------
-- 4. Audit Log
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100),
    `action` VARCHAR(50) NOT NULL,
    `metric` VARCHAR(100),
    `filters_json` JSON DEFAULT NULL,
    `request_id` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB COMMENT='Application audit trail for queries and actions';
