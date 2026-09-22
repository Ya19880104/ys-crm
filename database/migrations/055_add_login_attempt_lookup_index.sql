-- 登入節流的 account rail 先依 scope + username 定位，再以時間窗與 id checkpoint
-- 篩選。既有 idx_scope_ip_created 只服務來源 rail，無法支援這組查詢條件。
--
-- MigrationRunner 會在 DDL 後另寫 migrations bookkeeping。若兩者之間斷線或
-- INSERT 權限失敗，下一輪必須能安全重跑本檔，不能卡在 duplicate index。
-- MySQL 5.7 沒有 ADD INDEX IF NOT EXISTS，因此以 INFORMATION_SCHEMA 判定後
-- 動態執行；MariaDB 10.4 亦支援這組語法。
SET @ys_crm_login_attempt_index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{prefix}login_attempts'
      AND INDEX_NAME = 'idx_scope_username_attempted_id'
);

SET @ys_crm_login_attempt_index_sql = IF(
    @ys_crm_login_attempt_index_exists = 0,
    'ALTER TABLE `{prefix}login_attempts` ADD INDEX `idx_scope_username_attempted_id` (`scope`, `username`, `attempted_at`, `id`)',
    'DO 1'
);

PREPARE ys_crm_login_attempt_index_stmt FROM @ys_crm_login_attempt_index_sql;
EXECUTE ys_crm_login_attempt_index_stmt;
DEALLOCATE PREPARE ys_crm_login_attempt_index_stmt;
