-- 報價匿名分享期限（Q2：獨立於 valid_until 的分享到期契約；見 docs/plans/2026-09-14-crm-public-quote-sharing-plan.md）。
--
-- 只規範 public／password 兩種「知道網址即可存取」的匿名分享；customer_only 靠客戶登入、
-- private 本就拒絕，兩者不套用。valid_until（報價有效期限）仍是業務語意，兩者互不取代。
--
-- 時間欄位一律為 UTC epoch 秒（BIGINT）：到期判斷是固定時間點（now >= share_expires_at 即拒絕），
-- 不受 app／DB session 時區影響；管理畫面再轉成網站時區顯示。
--   share_auto_expire      1=自動關閉 / 0=無期限（須明確確認）/ NULL=未初始化（不得當成永久可看）
--   share_duration_days    1–365
--   share_enabled_at       本次匿名分享的起算點（第一次儲存生效的伺服器時間；瀏覽、重寄、編輯都不重算）
--   share_expires_at       固定到期點；無期限時為 NULL
--   share_closed_at        手動關閉或切離匿名分享的時間；重新公開須明確確認並換新 token
--   share_unlimited_ack_at 無期限的風險確認時間
--   share_policy_revision  分享授權版本：可見性／期限／token／密碼任一變動即 +1，
--                          簽署與發動付款在交易內比對，避免與關閉／編輯交錯時繞過
--
-- DDL 可能在寫入 migration ledger 前就已 commit，因此每個欄位各自以 INFORMATION_SCHEMA 守衛，可安全重跑。

SET @ys_crm_061_auto_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_auto_expire'
);
SET @ys_crm_061_auto_sql = IF(@ys_crm_061_auto_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_auto_expire` TINYINT NULL AFTER `access_password_hash`', 'DO 1');
PREPARE ys_crm_061_auto_stmt FROM @ys_crm_061_auto_sql;
EXECUTE ys_crm_061_auto_stmt;
DEALLOCATE PREPARE ys_crm_061_auto_stmt;

SET @ys_crm_061_days_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_duration_days'
);
SET @ys_crm_061_days_sql = IF(@ys_crm_061_days_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_duration_days` SMALLINT UNSIGNED NULL AFTER `share_auto_expire`', 'DO 1');
PREPARE ys_crm_061_days_stmt FROM @ys_crm_061_days_sql;
EXECUTE ys_crm_061_days_stmt;
DEALLOCATE PREPARE ys_crm_061_days_stmt;

SET @ys_crm_061_enabled_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_enabled_at'
);
SET @ys_crm_061_enabled_sql = IF(@ys_crm_061_enabled_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_enabled_at` BIGINT UNSIGNED NULL AFTER `share_duration_days`', 'DO 1');
PREPARE ys_crm_061_enabled_stmt FROM @ys_crm_061_enabled_sql;
EXECUTE ys_crm_061_enabled_stmt;
DEALLOCATE PREPARE ys_crm_061_enabled_stmt;

SET @ys_crm_061_expires_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_expires_at'
);
SET @ys_crm_061_expires_sql = IF(@ys_crm_061_expires_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_expires_at` BIGINT UNSIGNED NULL AFTER `share_enabled_at`', 'DO 1');
PREPARE ys_crm_061_expires_stmt FROM @ys_crm_061_expires_sql;
EXECUTE ys_crm_061_expires_stmt;
DEALLOCATE PREPARE ys_crm_061_expires_stmt;

SET @ys_crm_061_closed_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_closed_at'
);
SET @ys_crm_061_closed_sql = IF(@ys_crm_061_closed_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_closed_at` BIGINT UNSIGNED NULL AFTER `share_expires_at`', 'DO 1');
PREPARE ys_crm_061_closed_stmt FROM @ys_crm_061_closed_sql;
EXECUTE ys_crm_061_closed_stmt;
DEALLOCATE PREPARE ys_crm_061_closed_stmt;

SET @ys_crm_061_ack_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_unlimited_ack_at'
);
SET @ys_crm_061_ack_sql = IF(@ys_crm_061_ack_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_unlimited_ack_at` BIGINT UNSIGNED NULL AFTER `share_closed_at`', 'DO 1');
PREPARE ys_crm_061_ack_stmt FROM @ys_crm_061_ack_sql;
EXECUTE ys_crm_061_ack_stmt;
DEALLOCATE PREPARE ys_crm_061_ack_stmt;

SET @ys_crm_061_rev_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}quotes' AND COLUMN_NAME = 'share_policy_revision'
);
SET @ys_crm_061_rev_sql = IF(@ys_crm_061_rev_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `share_policy_revision` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `share_unlimited_ack_at`', 'DO 1');
PREPARE ys_crm_061_rev_stmt FROM @ys_crm_061_rev_sql;
EXECUTE ys_crm_061_rev_stmt;
DEALLOCATE PREPARE ys_crm_061_rev_stmt;

-- 既有匿名分享的過渡期限（Q§6）：
--   · 不依舊 created_at／sent_at 倒算——否則升級當下就把多年舊單直接關掉。
--   · 也不讓 NULL 自動變成無期限。
--   · 以本次 migration 的執行時間為固定起點＋30 天。UNIX_TIMESTAMP() 在同一個陳述句內是常數，
--     所有受影響列拿到同一個起點。
--   · 只處理「仍是匿名分享、尚未初始化、未關閉」的列：重跑時已初始化的列不動，
--     不會重設起點、也不會再加 30 天；private／customer_only 不會因此被重新公開。
UPDATE `{prefix}quotes`
   SET `share_auto_expire`     = 1,
       `share_duration_days`   = 30,
       `share_enabled_at`      = UNIX_TIMESTAMP(),
       `share_expires_at`      = UNIX_TIMESTAMP() + 30 * 86400,
       `share_policy_revision` = `share_policy_revision` + 1
 WHERE `visibility` IN ('public', 'password')
   AND `share_enabled_at` IS NULL
   AND `share_closed_at` IS NULL;
