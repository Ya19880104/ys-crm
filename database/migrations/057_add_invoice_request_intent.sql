-- Durable bridge between payment accounting and asynchronous invoice creation.
--
-- The payment callback commits before any external invoice API work. Without a
-- marker on that same payment transaction, a crash before invoices.create()
-- leaves no row for cron to discover. This timestamp records only payments for
-- which auto issue was enabled at the paid transition; it is intentionally not
-- backfilled, so enabling auto issue later cannot issue historical/manual rows.

SET @ys_crm_057_intent_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}payments'
       AND COLUMN_NAME = 'invoice_requested_at'
);
SET @ys_crm_057_intent_sql = IF(
    @ys_crm_057_intent_exists = 0,
    'ALTER TABLE `{prefix}payments` ADD COLUMN `invoice_requested_at` DATETIME NULL DEFAULT NULL AFTER `paid_at`',
    'DO 1'
);
PREPARE ys_crm_057_intent_stmt FROM @ys_crm_057_intent_sql;
EXECUTE ys_crm_057_intent_stmt;
DEALLOCATE PREPARE ys_crm_057_intent_stmt;

SET @ys_crm_057_index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}payments'
       AND INDEX_NAME = 'idx_invoice_requested_at'
);
SET @ys_crm_057_index_sql = IF(
    @ys_crm_057_index_exists = 0,
    'ALTER TABLE `{prefix}payments` ADD INDEX `idx_invoice_requested_at` (`invoice_requested_at`, `status`)',
    'DO 1'
);
PREPARE ys_crm_057_index_stmt FROM @ys_crm_057_index_sql;
EXECUTE ys_crm_057_index_stmt;
DEALLOCATE PREPARE ys_crm_057_index_stmt;
