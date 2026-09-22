-- 通用短期快取（k/v + 到期時間）。取代原 WordPress 移植碼所依賴的 transient。
--
-- 目前用途（外部 API 的自我保護，見 InvoiceService）：
--   1) 出網限流：每分鐘最多 N 次發票 API 呼叫的計數器。
--   2) 熔斷：連續失敗達門檻後寫入暫停標記，期間直接拒絕送出，避免對方系統異常時
--      我方持續重送、把可重試的失敗累積成一堆需人工處理的 failed 發票。
--
-- 為何不用 PHP 檔案快取或 APCu：本系統可能多 process/多機執行，計數與熔斷狀態必須共享；
-- DB 是既有且唯一的共享狀態來源。
--
-- expires_at：到期時間。讀取端一律過濾 expires_at > NOW()，不依賴清理排程即可正確。
--   過期列由 cron 定期清除（純空間回收，非正確性所需）。
CREATE TABLE IF NOT EXISTS `{prefix}cache` (
    `cache_key` VARCHAR(190) NOT NULL,
    `cache_value` TEXT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cache_key`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
