-- 客戶主檔（CRM 域核心，其他模組皆依賴）
-- 對應架構設計 §5.2 customers 表。
-- type：individual=個人 / company=公司（公司才需統編 tax_id）。
-- assigned_to：我方負責人（admin user id，可 NULL，使用者刪除時設為 NULL）。
-- created_by：建立者（admin user id，使用者刪除時設為 NULL 以保留客戶資料）。
CREATE TABLE IF NOT EXISTS `{prefix}customers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('individual', 'company') NOT NULL DEFAULT 'individual',
    `display_name` VARCHAR(150) NOT NULL,
    `tax_id` VARCHAR(20) NOT NULL DEFAULT '',
    `address` VARCHAR(255) NOT NULL DEFAULT '',
    `phone` VARCHAR(50) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `source` VARCHAR(100) NOT NULL DEFAULT '',
    `status` ENUM('active', 'potential', 'inactive') NOT NULL DEFAULT 'active',
    `assigned_to` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`),
    INDEX `idx_assigned_to` (`assigned_to`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_tax_id` (`tax_id`),
    INDEX `idx_display_name` (`display_name`),
    FOREIGN KEY (`assigned_to`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
