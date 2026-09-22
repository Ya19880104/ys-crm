-- 客戶主機資產（對應架構設計 §5.4 customer_hosting 表、§7.7 Excel-like 總表）。
-- type：shared=虛擬主機 / vps=VPS。
-- status：active=啟用中 / expired=已到期 / terminated=終止。
--   到期翻轉（end_date 已過 → expired）由 cron 處理（P4-6），本階段不建 cron；
--   列表顯示時以視覺 badge 標示「已逾期」（status 仍 active 但 end_date < 今日）。
-- start_date/end_date：租用起訖（必填）。
-- related_website_id：可選關聯到本客戶的某個網站資產；該網站刪除時設為 NULL（不連帶刪主機）。
--   注意：related_website_id 與 customer_id 的「同屬一客戶」一致性由 Service 層強制（DB 無法跨欄位約束）。
-- customer_id：客戶刪除時連帶刪除其主機資產（CASCADE）。
-- created_by：建立者（admin user id，使用者刪除時設為 NULL 以保留資產資料）。
CREATE TABLE IF NOT EXISTS `{prefix}customer_hosting` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `type` ENUM('shared', 'vps') NOT NULL DEFAULT 'shared',
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
    `account_email` VARCHAR(255) NOT NULL DEFAULT '',
    `spec` TEXT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `status` ENUM('active', 'expired', 'terminated') NOT NULL DEFAULT 'active',
    `related_website_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_end_date` (`end_date`),
    INDEX `idx_type` (`type`),
    INDEX `idx_related_website_id` (`related_website_id`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`related_website_id`) REFERENCES `{prefix}customer_websites`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
