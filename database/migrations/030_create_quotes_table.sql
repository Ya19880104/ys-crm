-- 報價單主檔（對應架構設計 §5.5 quotes、§7.8 報價單系統）。
--
-- quote_number：對外報價編號（唯一），規則 Q-YYYY-NNNN（如 Q-2026-0001），
--   由 QuoteService 於交易內依當年最大序號 +1 產生，避免併發重號。
-- customer_id：可 NULL（未指定客戶的報價）；客戶刪除時設 NULL（SET NULL）保留報價紀錄。
-- status：草稿 → 已送出 → 已檢視 → 已簽署 → 已付款 → 已逾期/作廢。狀態機於 Service 定義。
-- visibility：private=僅後台 / public=有連結即可看 / password=需密碼 / customer_only=須客戶登入。
--   public/password 模式以 access_token 組公開 URL（/q/{token}）。private 不可由 token 存取。
-- access_token：公開/密碼 URL 用，隨機 64 hex（bin2hex(random_bytes(32))）不可猜；
--   private 時亦可預先產生但前台拒絕存取。唯一索引避免碰撞。
-- access_password_hash：visibility=password 時的密碼雜湊（password_hash / PASSWORD_DEFAULT）。
-- subtotal/tax_rate/tax/total：金額一律由伺服器端依明細重算（不信前端傳入的總計）。
--   tax_rate 為百分比（如 5.00 表 5%）。currency 預設 TWD。
-- valid_until：報價有效期限（可 NULL）。逾期翻轉（valid_until < 今日 → expired）留待 P4-6 cron，
--   本階段不建 cron；公開頁/列表以視覺標示「已逾期」。
-- payment_enabled：是否啟用線上付款（本階段純存旗標；付款導引留 P4-5，不實作金流）。
-- payment_status：unpaid/partial/paid（本階段不接金流，預設 unpaid，保留欄位供 P4-5）。
-- our_seal_applied：是否已套用我方公司印章（簽署完成時由 Service 設為 1）。
-- related_job_id：關聯工作 ID，保留欄位對應工作看板（§5.3）；暫不加 FK（避免雙向 FK 與刪除順序耦合），
--   由 Service 層維護一致性，待需要時以後續 migration 補。
-- created_by：建立者（admin user id，使用者刪除時設 NULL 以保留報價）。
-- sent_at：首次送出（status → sent）時間。
CREATE TABLE IF NOT EXISTS `{prefix}quotes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quote_number` VARCHAR(32) NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `status` ENUM('draft', 'sent', 'viewed', 'signed', 'paid', 'expired', 'void') NOT NULL DEFAULT 'draft',
    `visibility` ENUM('private', 'public', 'password', 'customer_only') NOT NULL DEFAULT 'private',
    `access_token` VARCHAR(64) NULL,
    `access_password_hash` VARCHAR(255) NULL,
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `tax` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'TWD',
    `valid_until` DATE NULL,
    `terms` TEXT NULL,
    `notes` TEXT NULL,
    `payment_enabled` TINYINT NOT NULL DEFAULT 0,
    `payment_status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
    `our_seal_applied` TINYINT NOT NULL DEFAULT 0,
    `related_job_id` INT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_quote_number` (`quote_number`),
    UNIQUE KEY `uniq_access_token` (`access_token`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_visibility` (`visibility`),
    INDEX `idx_valid_until` (`valid_until`),
    INDEX `idx_related_job` (`related_job_id`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`)  REFERENCES `{prefix}users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
