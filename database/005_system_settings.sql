-- ============================================================
-- Intelis Insights — System Settings Table
-- ============================================================
-- Key-value configuration store for system-wide settings
-- (e.g., per-step LLM model overrides).
-- ============================================================

USE `intelis_insights`;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `key`        VARCHAR(100) PRIMARY KEY,
    `value`      JSON NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='System-wide key-value configuration store';
