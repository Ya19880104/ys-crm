-- 兩階段驗證（2FA / TOTP）欄位
-- totp_secret：以 AES-256-GCM 加密後的 Base32 secret（Encryption::encrypt），明文絕不落地
-- totp_enabled：是否已啟用 2FA（登入時據此決定是否進入 challenge 閘道）
-- MySQL DDL 會先於 migrations ledger 自動 commit；ledger 寫入若隨後失敗，
-- 下一輪必須能辨識已落地欄位並只補缺少部分（MySQL 5.7 無 IF NOT EXISTS）。
SET @ys_crm_017_totp_secret_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}users'
       AND COLUMN_NAME = 'totp_secret'
);
SET @ys_crm_017_totp_secret_sql = IF(
    @ys_crm_017_totp_secret_exists = 0,
    'ALTER TABLE `{prefix}users` ADD COLUMN `totp_secret` VARCHAR(255) NULL AFTER `password`',
    'DO 1'
);
PREPARE ys_crm_017_totp_secret_stmt FROM @ys_crm_017_totp_secret_sql;
EXECUTE ys_crm_017_totp_secret_stmt;
DEALLOCATE PREPARE ys_crm_017_totp_secret_stmt;

SET @ys_crm_017_totp_enabled_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}users'
       AND COLUMN_NAME = 'totp_enabled'
);
SET @ys_crm_017_totp_enabled_sql = IF(
    @ys_crm_017_totp_enabled_exists = 0,
    'ALTER TABLE `{prefix}users` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`',
    'DO 1'
);
PREPARE ys_crm_017_totp_enabled_stmt FROM @ys_crm_017_totp_enabled_sql;
EXECUTE ys_crm_017_totp_enabled_stmt;
DEALLOCATE PREPARE ys_crm_017_totp_enabled_stmt;
