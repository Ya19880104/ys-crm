-- 付款紀錄（對應架構設計 §5.6 payments、§7.9 付款系統）。
--
-- 本表為金流入帳的單一真相來源。Zero Trust 設計重點：
--   1) 金額一律由伺服器端依報價（quotes.total）重算後寫入；callback 內金額需與本表 amount
--      比對一致才允許入帳（confirmPaid）。amount 型別與 {prefix}quotes.total 一致（DECIMAL(12,2)）。
--   2) 冪等：idempotency_key 唯一鍵 — 同一發動意圖只會建立一筆 pending 付款（防重複建單）。
--   3) 防重放/重複入帳：provider_txn_id 唯一鍵 — 同一筆金流交易序號只能入帳一次。
--   4) confirmPaid 於交易內以 SELECT ... FOR UPDATE 鎖定本列 + 已 paid 短路，杜絕併發重複入帳。
--
-- payment_no：對外付款編號（唯一），規則 PAY-YYYY-NNNN（如 PAY-2026-0001），
--   由 PaymentService 於交易內依當年最大序號 +1 產生（仿 quote_number 的 FOR UPDATE 鎖），避免併發重號。
-- quote_id：來源報價（可 NULL；未來支援非報價來源的付款）。報價刪除時設 NULL 保留付款紀錄。
-- customer_id：付款客戶（可 NULL）。客戶刪除時設 NULL 保留付款紀錄。
-- provider：金流商代碼（sandbox / payuni / shopline）。sandbox 供無真實金鑰時跑通完整 e2e。
-- method：付款方式（credit/atm/cvs… 可 NULL；由 provider 回呼後補寫）。
-- amount：應付金額（伺服器端依 quote.total 重算，不信前端）。currency 預設 TWD。
-- status：pending（已建單待付）→ paid（已入帳）/ failed（失敗）/ cancelled（取消）/ refunded（退款）。
--   狀態機於 PaymentService 定義；pending→paid 為主路徑。
-- provider_txn_id：金流商交易序號（驗章成功後寫入）。唯一索引防重放/重複入帳。
--   允許多筆 NULL（尚未入帳者）— MySQL UNIQUE 容許多個 NULL。
-- idempotency_key：發動付款的冪等鍵（唯一）。同 key 不重複建單。
-- raw_request：建立 checkout 時送往金流商的請求快照（除錯/稽核）。
-- raw_callback：金流商回呼原始內容快照（存證/稽核）。
-- paid_at：入帳時間（confirmPaid 時寫入）。
CREATE TABLE IF NOT EXISTS `{prefix}payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_no` VARCHAR(32) NOT NULL,
    `quote_id` INT UNSIGNED NULL,
    `customer_id` INT UNSIGNED NULL,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'sandbox',
    `method` VARCHAR(32) NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(8) NOT NULL DEFAULT 'TWD',
    `status` ENUM('pending', 'paid', 'failed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `provider_txn_id` VARCHAR(128) NULL,
    `idempotency_key` VARCHAR(64) NOT NULL,
    `raw_request` TEXT NULL,
    `raw_callback` TEXT NULL,
    `paid_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_payment_no` (`payment_no`),
    UNIQUE KEY `uniq_idempotency_key` (`idempotency_key`),
    UNIQUE KEY `uniq_provider_txn_id` (`provider_txn_id`),
    INDEX `idx_quote_id` (`quote_id`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`quote_id`)    REFERENCES `{prefix}quotes`(`id`)    ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
