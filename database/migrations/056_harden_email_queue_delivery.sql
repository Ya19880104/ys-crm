-- Email delivery crash lifecycle。
--
-- sending 僅代表「已認領、尚未開始 SMTP」，可在逾時後安全回收；一旦開始任何
-- SMTP 外部作用即先轉 indeterminate。若 SMTP 已接受而本機 finalize 中斷，後續
-- worker 不會盲目重寄，需由 operator 查核收件端/SMTP log 後處理。
--
-- MigrationRunner 的 DDL 與 ledger insert 不是同一 transaction。所有 ADD 均以
-- MySQL 5.7 INFORMATION_SCHEMA + PREPARE guard，讓 DDL 落地後的重跑保持安全。

-- 先讓 status 可保存保守轉換結果；MODIFY COLUMN 本身可重複套用。
ALTER TABLE `{prefix}email_queue`
    MODIFY COLUMN `status` ENUM('queued', 'sending', 'indeterminate', 'sent', 'failed') NOT NULL DEFAULT 'queued';

SET @ys_crm_056_claim_token_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}email_queue'
       AND COLUMN_NAME = 'claim_token'
);
SET @ys_crm_056_claim_token_sql = IF(
    @ys_crm_056_claim_token_exists = 0,
    'ALTER TABLE `{prefix}email_queue` ADD COLUMN `claim_token` VARCHAR(64) NULL DEFAULT NULL AFTER `attempts`',
    'DO 1'
);
PREPARE ys_crm_056_claim_token_stmt FROM @ys_crm_056_claim_token_sql;
EXECUTE ys_crm_056_claim_token_stmt;
DEALLOCATE PREPARE ys_crm_056_claim_token_stmt;

SET @ys_crm_056_claimed_at_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}email_queue'
       AND COLUMN_NAME = 'claimed_at'
);
SET @ys_crm_056_claimed_at_sql = IF(
    @ys_crm_056_claimed_at_exists = 0,
    'ALTER TABLE `{prefix}email_queue` ADD COLUMN `claimed_at` DATETIME NULL DEFAULT NULL AFTER `claim_token`',
    'DO 1'
);
PREPARE ys_crm_056_claimed_at_stmt FROM @ys_crm_056_claimed_at_sql;
EXECUTE ys_crm_056_claimed_at_stmt;
DEALLOCATE PREPARE ys_crm_056_claimed_at_stmt;

-- 舊版 sending 無法知道 crash 發生在 SMTP 前或後；一律保守標成人工查核，絕不盲重寄。
UPDATE `{prefix}email_queue`
   SET `status` = 'indeterminate',
       `last_error` = COALESCE(`last_error`, '升級前寄送狀態不明，請查核 SMTP 紀錄。')
 WHERE `status` = 'sending'
   AND `claim_token` IS NULL;

SET @ys_crm_056_claim_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}email_queue'
       AND INDEX_NAME = 'idx_email_claim_recovery'
);
SET @ys_crm_056_claim_index_sql = IF(
    @ys_crm_056_claim_index_exists = 0,
    'ALTER TABLE `{prefix}email_queue` ADD INDEX `idx_email_claim_recovery` (`status`, `claimed_at`)',
    'DO 1'
);
PREPARE ys_crm_056_claim_index_stmt FROM @ys_crm_056_claim_index_sql;
EXECUTE ys_crm_056_claim_index_stmt;
DEALLOCATE PREPARE ys_crm_056_claim_index_stmt;
