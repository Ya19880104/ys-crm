-- 客戶常用發票資料。移植自 ys-enhance-hosting 的 ys_hosting_einvoice_profiles。
--
-- 用途：客戶（或後台代客）預先登錄發票抬頭/統編/載具，開立時免重填。
-- 開立時的資料解析順序（InvoiceService::resolveSnapshot）：
--   報價單上的發票資料 → 客戶預設 profile（is_default=1）→ 客戶主檔推定（名稱/Email/統編）
-- 「無 default 時取第一筆」是危險做法（會拿到非預期抬頭），故一律要求 is_default=1 才算數。
--
-- profile_type：b2c（個人）/ b2b（公司，需 8 碼統編）/ donate（捐贈，需愛心碼）
-- carrier_type：舊版代碼（3J0002 手機條碼 / 1K0001 悠遊卡 / CQ0001 自然人憑證），
--   送出前由 InvoicePayloadBuilder 映射為 REST v1 強型別 enum；空值代表無載具偏好。
-- buyer_identifier：統一編號（8 碼，寫入與讀取端一律 preg_replace('/\D+/') 正規化）。
CREATE TABLE IF NOT EXISTS `{prefix}invoice_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(100) NOT NULL DEFAULT '',
    `profile_type` ENUM('b2c', 'b2b', 'donate') NOT NULL DEFAULT 'b2c',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `buyer_name` VARCHAR(100) NOT NULL DEFAULT '',
    `buyer_email` VARCHAR(190) NOT NULL DEFAULT '',
    `buyer_phone` VARCHAR(32) NOT NULL DEFAULT '',
    `buyer_address` VARCHAR(255) NOT NULL DEFAULT '',
    `buyer_identifier` VARCHAR(16) NOT NULL DEFAULT '',
    `carrier_type` VARCHAR(16) NOT NULL DEFAULT '',
    `carrier_id_1` VARCHAR(64) NOT NULL DEFAULT '',
    `carrier_id_2` VARCHAR(64) NOT NULL DEFAULT '',
    `love_code` VARCHAR(16) NOT NULL DEFAULT '',
    `country` VARCHAR(8) NOT NULL DEFAULT 'TW',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_is_default` (`customer_id`, `is_default`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
