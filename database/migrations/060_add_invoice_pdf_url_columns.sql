-- 官方發票列印 URL 快取欄位（SOAP Get_InvoiceURL_I 結果）。
-- 加密存放；24 小時到期後重新從 PayNow 取得。
-- DDL can commit before the migration ledger is written. Each column recovers independently.
SET @ys_crm_060_url_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}invoices' AND COLUMN_NAME = 'pdf_url'
);
SET @ys_crm_060_url_sql = IF(@ys_crm_060_url_exists = 0,
    'ALTER TABLE `{prefix}invoices` ADD COLUMN `pdf_url` TEXT NULL AFTER `provider_response`', 'DO 1');
PREPARE ys_crm_060_url_stmt FROM @ys_crm_060_url_sql;
EXECUTE ys_crm_060_url_stmt;
DEALLOCATE PREPARE ys_crm_060_url_stmt;

SET @ys_crm_060_expiry_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{prefix}invoices' AND COLUMN_NAME = 'pdf_url_expires_at'
);
SET @ys_crm_060_expiry_sql = IF(@ys_crm_060_expiry_exists = 0,
    'ALTER TABLE `{prefix}invoices` ADD COLUMN `pdf_url_expires_at` DATETIME NULL AFTER `pdf_url`', 'DO 1');
PREPARE ys_crm_060_expiry_stmt FROM @ys_crm_060_expiry_sql;
EXECUTE ys_crm_060_expiry_stmt;
DEALLOCATE PREPARE ys_crm_060_expiry_stmt;
