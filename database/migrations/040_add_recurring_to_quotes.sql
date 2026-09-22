-- 為 quotes 補上週期單關聯欄位（對應架構設計 §5.5 quotes 的 is_recurring / recurring_schedule_id）。
--
-- 030_create_quotes_table.sql 建表時尚未含此二欄；本 migration 以 ALTER 補上。
-- MigrationRunner 的 ledger insert 發生在 MySQL DDL commit 之後；故每個 postcondition
-- 都以 INFORMATION_SCHEMA 個別判定，使用 MySQL 5.7 相容的動態 DDL。
--
-- is_recurring：此報價是否由週期排程「自動產生」的帳單（1=是）。供列表標示「週期單」。
-- recurring_schedule_id：產生此報價的來源排程 id（{prefix}recurring_schedules.id）。
--   排程刪除時設 NULL（保留已產生的報價歷史）。
SET @ys_crm_040_is_recurring_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}quotes'
       AND COLUMN_NAME = 'is_recurring'
);
SET @ys_crm_040_is_recurring_sql = IF(
    @ys_crm_040_is_recurring_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `is_recurring` TINYINT NOT NULL DEFAULT 0 AFTER `payment_status`',
    'DO 1'
);
PREPARE ys_crm_040_is_recurring_stmt FROM @ys_crm_040_is_recurring_sql;
EXECUTE ys_crm_040_is_recurring_stmt;
DEALLOCATE PREPARE ys_crm_040_is_recurring_stmt;

SET @ys_crm_040_schedule_id_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}quotes'
       AND COLUMN_NAME = 'recurring_schedule_id'
);
SET @ys_crm_040_schedule_id_sql = IF(
    @ys_crm_040_schedule_id_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD COLUMN `recurring_schedule_id` INT UNSIGNED NULL AFTER `is_recurring`',
    'DO 1'
);
PREPARE ys_crm_040_schedule_id_stmt FROM @ys_crm_040_schedule_id_sql;
EXECUTE ys_crm_040_schedule_id_stmt;
DEALLOCATE PREPARE ys_crm_040_schedule_id_stmt;

SET @ys_crm_040_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}quotes'
       AND INDEX_NAME = 'idx_recurring_schedule'
);
SET @ys_crm_040_index_sql = IF(
    @ys_crm_040_index_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD INDEX `idx_recurring_schedule` (`recurring_schedule_id`)',
    'DO 1'
);
PREPARE ys_crm_040_index_stmt FROM @ys_crm_040_index_sql;
EXECUTE ys_crm_040_index_stmt;
DEALLOCATE PREPARE ys_crm_040_index_stmt;

-- 外鍵：排程刪除時把已產生報價的 recurring_schedule_id 設 NULL（不連帶刪報價）。
SET @ys_crm_040_fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}quotes'
       AND CONSTRAINT_NAME = 'fk_quotes_recurring_schedule'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ys_crm_040_fk_sql = IF(
    @ys_crm_040_fk_exists = 0,
    'ALTER TABLE `{prefix}quotes` ADD CONSTRAINT `fk_quotes_recurring_schedule` FOREIGN KEY (`recurring_schedule_id`) REFERENCES `{prefix}recurring_schedules`(`id`) ON DELETE SET NULL',
    'DO 1'
);
PREPARE ys_crm_040_fk_stmt FROM @ys_crm_040_fk_sql;
EXECUTE ys_crm_040_fk_stmt;
DEALLOCATE PREPARE ys_crm_040_fk_stmt;
