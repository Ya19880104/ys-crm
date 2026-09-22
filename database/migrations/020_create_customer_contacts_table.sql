-- 客戶聯絡人（一個客戶多筆；個人客戶自動建立 1 筆 primary 聯絡人）
-- 對應架構設計 §5.2 customer_contacts 表。
-- 含台灣常用通訊渠道：line_id、fb_url（Facebook）、threads_url（脆）。
-- customer_id：客戶刪除時連帶刪除其所有聯絡人（CASCADE）。
CREATE TABLE IF NOT EXISTS `{prefix}customer_contacts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(100) NOT NULL DEFAULT '',
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `phone` VARCHAR(50) NOT NULL DEFAULT '',
    `mobile` VARCHAR(50) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `line_id` VARCHAR(100) NOT NULL DEFAULT '',
    `fb_url` VARCHAR(255) NOT NULL DEFAULT '',
    `threads_url` VARCHAR(255) NOT NULL DEFAULT '',
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_is_primary` (`customer_id`, `is_primary`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
