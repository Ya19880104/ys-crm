-- 為登入嘗試表（{prefix}login_attempts）增加 scope 欄位，區分認證平面。
--
-- 動機（Zero Trust / 雙平面隔離）：
-- 客戶 portal 登入節流必須與管理員登入節流「完全隔離」——否則客戶端的失敗嘗試會污染
-- 管理員 IP 的鎖定計數（反之亦然），造成跨平面阻斷或繞過。加入 scope 後：
--   - 管理員（LoginAttemptService）以 scope='admin' 計數。
--   - 客戶（CustomerLoginAttemptService）以 scope='customer' + email + ip 計數。
-- 既有資料無此欄位 → 預設 'admin'，與既有 LoginAttemptService 行為相容（其查詢不帶 scope，
-- 仍可運作；新查詢加 scope 條件後即隔離）。
--
SET @ys_crm_044_scope_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}login_attempts'
       AND COLUMN_NAME = 'scope'
);
SET @ys_crm_044_scope_sql = IF(
    @ys_crm_044_scope_exists = 0,
    'ALTER TABLE `{prefix}login_attempts` ADD COLUMN `scope` VARCHAR(16) NOT NULL DEFAULT ''admin'' AFTER `id`',
    'DO 1'
);
PREPARE ys_crm_044_scope_stmt FROM @ys_crm_044_scope_sql;
EXECUTE ys_crm_044_scope_stmt;
DEALLOCATE PREPARE ys_crm_044_scope_stmt;

SET @ys_crm_044_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}login_attempts'
       AND INDEX_NAME = 'idx_scope_ip_created'
);
SET @ys_crm_044_index_sql = IF(
    @ys_crm_044_index_exists = 0,
    'ALTER TABLE `{prefix}login_attempts` ADD INDEX `idx_scope_ip_created` (`scope`, `ip_address`, `attempted_at`)',
    'DO 1'
);
PREPARE ys_crm_044_index_stmt FROM @ys_crm_044_index_sql;
EXECUTE ys_crm_044_index_stmt;
DEALLOCATE PREPARE ys_crm_044_index_stmt;
