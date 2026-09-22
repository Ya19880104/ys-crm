-- TOTP 重放保護（RFC 6238 單次使用）
-- totp_last_step：最近一次「成功驗證」所命中的絕對時間步（floor(unix_time / 30)）。
--   每次成功驗證後，僅接受 step > totp_last_step 的碼並更新此值，
--   使同一個 30 秒時間步的 TOTP 碼無法在被攔截後重放。NULL 表示尚未使用過任何碼。
SET @ys_crm_018_totp_last_step_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}users'
       AND COLUMN_NAME = 'totp_last_step'
);
SET @ys_crm_018_totp_last_step_sql = IF(
    @ys_crm_018_totp_last_step_exists = 0,
    'ALTER TABLE `{prefix}users` ADD COLUMN `totp_last_step` BIGINT NULL AFTER `totp_enabled`',
    'DO 1'
);
PREPARE ys_crm_018_totp_last_step_stmt FROM @ys_crm_018_totp_last_step_sql;
EXECUTE ys_crm_018_totp_last_step_stmt;
DEALLOCATE PREPARE ys_crm_018_totp_last_step_stmt;
