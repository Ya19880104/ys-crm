-- 退款請求冪等帳（F04）。
--
-- 【原本的缺陷】refund_claim_token 是「執行」權杖，每次呼叫重新產生，
-- 只擋得住並行重複，擋不住「回應遺失後再送一次」：
--   1. 管理員送出退 100 → provider 實際退款成功 → finalizeRefund 落盤 → claim 釋放
--   2. HTTP 回應在網路上遺失，管理員沒看到結果
--   3. 管理員再按一次 → 重讀到 status=partially_refunded / refunded=100 / claim=NULL
--      → 一切檢查都合法 → 再退 100。客戶被退 200。
-- CAS 在這個交錯裡完全正確地運作 —— 它本來就不是為了這件事設計的。
--
-- 【本表提供的保證】呼叫端攜帶一個 durable request_id；同一個 id 只會真正
-- 送出一次 provider 退款，之後一律回放既有結果，不再打對方。
--
-- request_id：伺服器在退款表單 render 時發放的一次性識別字。同一張表單重送
--   （F5／上一頁／連點）帶同一個 id；重新載入頁面才會拿到新的 id。
-- status：in_flight → 已登記但尚未取得確定結果。重播時一律回 indeterminate，
--   **不得**自動重試 —— 我們無法區分「還在跑」與「跑到一半死掉」。
--   只有能證明「從未呼叫 provider」的失敗才可標 failed（可重試）。
--
-- 為什麼是獨立資料表而不是 payments 上的欄位：一筆付款可以有多次部分退款，
-- 每一次都需要各自的請求身分，單一欄位存不下。
CREATE TABLE IF NOT EXISTS `{prefix}payment_refund_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` VARCHAR(64) NOT NULL,
    `payment_id` INT UNSIGNED NOT NULL,
    `amount` INT UNSIGNED NOT NULL,
    `status` ENUM('in_flight', 'success', 'failed', 'indeterminate') NOT NULL DEFAULT 'in_flight',
    `outcome` VARCHAR(32) NULL DEFAULT NULL,
    `message` TEXT NULL,
    `admin_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `claim_token` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL DEFAULT NULL,
    -- 唯一鍵是整個機制的核心：重複的 INSERT 必須失敗，而不是產生第二列。
    UNIQUE KEY `uk_refund_request_id` (`request_id`),
    INDEX `idx_refund_request_payment` (`payment_id`),
    INDEX `idx_refund_request_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
