-- 電子發票紀錄（PayNow REST v1）。移植自 ys-enhance-hosting 的 ys_hosting_einvoices，
-- 對接對象由「訂閱訂單」改為本系統的 {prefix}payments。
--
-- 【本表為發票開立的單一真相來源】設計重點：
--   1) 防重複開立三層：
--      a. MySQL GET_LOCK（跨 process 互斥，InvoiceService 取得後才動作）
--      b. claim_token + 條件式 UPDATE（CAS）：只有 status IN (pending/failed/scheduled) 能被 claim，
--         affected rows === 1 才算搶到，杜絕兩個 worker 同時開同一張。
--      c. 送出前依 order_no 向 PayNow 查詢（reconcile-first）：查詢失敗一律不送出（fail-closed）。
--   2) invoice_number 只在「確定開立成功」時寫入。若出現「有號碼但 status 非 issued」，
--      唯一合法場景是作廢後重開（見 cancelled_at）。此不變量是 reconcile 判斷的依據，不可打破。
--   3) payload_snapshot 為開立當下的加密快照（買受人/品項/金額/稅額）。發票明細不得
--      事後從可被修改的報價重建——稽核時必須能還原「當時真正送出的內容」。
--
-- status：
--   pending   已建列待開立
--   scheduled 排定於未來開立
--   issuing   已被 claim，正在送出（逾時未完成者由 cron 回收）
--   issued    開立成功（invoice_number 必有值）
--   failed    開立失敗（可重試，next_retry_at 控制退避）
--   cancelled 已作廢
--
-- issue_type：auto（付款成功自動開立）/ manual（後台手動）/ retry（重試）/ reissue（作廢後重開）
-- claim_token/claimed_at：CAS 佔用標記。claimed_at 過舊即視為殭屍 claim，由 cron 釋放回 failed。
-- next_retry_at：指數退避的下次重試時間（NULL = 不自動重試）。
-- provider_response：最後一次 API 原始回應（JSON，存證用）。
CREATE TABLE IF NOT EXISTS `{prefix}invoices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NULL,
    `quote_id` INT UNSIGNED NULL,
    `customer_id` INT UNSIGNED NULL,
    `status` ENUM('pending', 'scheduled', 'issuing', 'issued', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `issue_type` VARCHAR(16) NOT NULL DEFAULT 'auto',
    `order_no` VARCHAR(64) NOT NULL,
    `invoice_number` VARCHAR(32) NULL,
    `invoice_date` VARCHAR(32) NULL,
    `random_code` VARCHAR(16) NULL,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_retry_at` DATETIME NULL,
    `scheduled_issue_at` DATETIME NULL,
    `claim_token` VARCHAR(64) NULL,
    `claimed_at` DATETIME NULL,
    `issued_at` DATETIME NULL,
    `cancelled_at` DATETIME NULL,
    `payload_snapshot` TEXT NULL,
    `provider_response` LONGTEXT NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_payment_id` (`payment_id`),
    INDEX `idx_quote_id` (`quote_id`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_invoice_number` (`invoice_number`),
    INDEX `idx_next_retry_at` (`next_retry_at`),
    INDEX `idx_order_no` (`order_no`),
    FOREIGN KEY (`payment_id`)  REFERENCES `{prefix}payments`(`id`)  ON DELETE SET NULL,
    FOREIGN KEY (`quote_id`)    REFERENCES `{prefix}quotes`(`id`)    ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
