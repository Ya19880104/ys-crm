-- 客戶網站資產（對應架構設計 §5.4 customer_websites 表、§7.7 Excel-like 總表）。
-- case_type：build=網站製作 / hosting=代管 / maintenance_hosting=維護+代管 / maintenance=僅維護。
-- status：active=啟用中 / expired=已到期 / terminated=終止。
--   到期翻轉（contract_end 已過 → expired）由 cron 處理（P4-6），本階段不建 cron；
--   列表顯示時以視覺 badge 標示「已逾期」（status 仍 active 但 contract_end < 今日）。
-- contract_start/contract_end：合約起訖（必填）。
-- maintenance_start/maintenance_end：維護期間（僅維護/維護+代管適用，可 NULL）。
-- customer_id：客戶刪除時連帶刪除其網站資產（CASCADE）。
-- created_by：建立者（admin user id，使用者刪除時設為 NULL 以保留資產資料）。
CREATE TABLE IF NOT EXISTS `{prefix}customer_websites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `url` VARCHAR(255) NOT NULL DEFAULT '',
    `case_type` ENUM('build', 'hosting', 'maintenance_hosting', 'maintenance') NOT NULL DEFAULT 'build',
    `contract_start` DATE NULL,
    `contract_end` DATE NULL,
    `maintenance_start` DATE NULL,
    `maintenance_end` DATE NULL,
    `status` ENUM('active', 'expired', 'terminated') NOT NULL DEFAULT 'active',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_contract_end` (`contract_end`),
    INDEX `idx_case_type` (`case_type`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
