-- 退款追蹤：累計已退金額 + 併發保護。
--
-- 【原本的兩個缺陷】（稽核 2026-08-16）
--   1. 併發：refund() 在呼叫外部 API 前只做無鎖讀取，兩個同時進來的請求都會通過
--      前置檢查並各自送出退款 —— 客戶被退兩次，而且兩次都「成功」。
--   2. 部分退款：退 100／原款 1000 會把整筆標成 refunded，剩下的 900 之後
--      再也無法從 CRM 退款；而 quote 仍是 paid，帳面與現實不一致。
--
-- refunded_amount：累計已退金額。可退餘額 = amount - refunded_amount。
--   全額退完才把 status 轉 refunded；部分退款用 partially_refunded 表示
--   「已退一部分、仍可再退」。
--
-- refund_claim_token / refund_claimed_at：退款作業的佔用標記（CAS）。
--   送出外部退款前必須先搶到 claim，搶不到代表另一個請求正在處理。
--
-- 🔴 claim 在「結果不確定」時**刻意不釋放**：逾時或 5xx 代表金流商可能已經退了，
--   此時若讓人重按，就是實質的雙退。必須由人確認實際結果後手動清除，
--   清除方式見 docs/GO-LIVE.md。
SET @ys_crm_052_refunded_amount_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}payments'
       AND COLUMN_NAME = 'refunded_amount'
);
SET @ys_crm_052_refunded_amount_sql = IF(
    @ys_crm_052_refunded_amount_exists = 0,
    'ALTER TABLE `{prefix}payments` ADD COLUMN `refunded_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`',
    'DO 1'
);
PREPARE ys_crm_052_refunded_amount_stmt FROM @ys_crm_052_refunded_amount_sql;
EXECUTE ys_crm_052_refunded_amount_stmt;
DEALLOCATE PREPARE ys_crm_052_refunded_amount_stmt;

SET @ys_crm_052_claim_token_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}payments'
       AND COLUMN_NAME = 'refund_claim_token'
);
SET @ys_crm_052_claim_token_sql = IF(
    @ys_crm_052_claim_token_exists = 0,
    'ALTER TABLE `{prefix}payments` ADD COLUMN `refund_claim_token` VARCHAR(64) NULL DEFAULT NULL AFTER `refunded_amount`',
    'DO 1'
);
PREPARE ys_crm_052_claim_token_stmt FROM @ys_crm_052_claim_token_sql;
EXECUTE ys_crm_052_claim_token_stmt;
DEALLOCATE PREPARE ys_crm_052_claim_token_stmt;

SET @ys_crm_052_claimed_at_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}payments'
       AND COLUMN_NAME = 'refund_claimed_at'
);
SET @ys_crm_052_claimed_at_sql = IF(
    @ys_crm_052_claimed_at_exists = 0,
    'ALTER TABLE `{prefix}payments` ADD COLUMN `refund_claimed_at` DATETIME NULL DEFAULT NULL AFTER `refund_claim_token`',
    'DO 1'
);
PREPARE ys_crm_052_claimed_at_stmt FROM @ys_crm_052_claimed_at_sql;
EXECUTE ys_crm_052_claimed_at_stmt;
DEALLOCATE PREPARE ys_crm_052_claimed_at_stmt;

-- 加入部分退款與退款處理中兩個狀態。
ALTER TABLE `{prefix}payments`
    MODIFY COLUMN `status` ENUM(
        'pending',
        'paid',
        'failed',
        'cancelled',
        'partially_refunded',
        'refunded'
    ) NOT NULL DEFAULT 'pending';
