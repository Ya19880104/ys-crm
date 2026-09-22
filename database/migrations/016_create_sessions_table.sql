-- Zero Trust：DB-backed sessions
-- 支援伺服器端撤銷（revoked）、閒置逾時（last_activity）、絕對逾時（created_at）、
-- 以及 user/IP/UA 綁定中繼欄位（供管理員撤銷某用戶所有 session 的 kill-switch）。
CREATE TABLE IF NOT EXISTS `{prefix}sessions` (
    `id` VARCHAR(128) NOT NULL,
    `user_type` VARCHAR(16) NULL,
    `user_id` INT UNSIGNED NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
    `payload` LONGTEXT NOT NULL,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_activity` INT UNSIGNED NOT NULL DEFAULT 0,
    `revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `revoked_at` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_user` (`user_type`, `user_id`),
    INDEX `idx_last_activity` (`last_activity`),
    INDEX `idx_revoked` (`revoked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
