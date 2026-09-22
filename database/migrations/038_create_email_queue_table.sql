-- 寄信佇列（對應架構設計 §5.7 email_queue、§7.10）。
--
-- 解耦「產生通知」與「實際寄信」：NotificationService 於需要 email 時 enqueue 一筆，
-- 真正寄送由 cron mail 子命令批次處理（claimBatch → Mailer 寄送 → markSent / markFailed）。
-- 無 SMTP 設定時 Mailer 不丟致命錯，信件留在 queued 狀態，可於後台「通知記錄」檢視/重送。
--
-- to_email：收件者信箱。
-- subject / body_html：信件主旨與 HTML 內文（由 NotificationService 以繁中模板組成）。
-- status：
--   queued  → 待寄（初始）。
--   sending → cron 已認領但尚未呼叫 SMTP；stale claim 可安全回收。
--   indeterminate → SMTP delivery 已開始，結果未知時不得自動重送，需人工查核。
--   sent    → 已成功寄出（sent_at 寫入）。
--   failed  → 寄送失敗（last_error 記錄原因）；attempts 達上限後不再重試。
-- attempts：已嘗試寄送次數（每次認領 +1）；cron 以此判斷是否超過重試上限。
-- last_error：最近一次失敗原因（SMTP 錯誤訊息 / 未設定 SMTP 等）。
-- scheduled_at：預定寄送時間（即時通知填當下；保留供未來排程寄送）。cron 只認領 scheduled_at <= NOW()。
-- sent_at：實際寄出時間。
CREATE TABLE IF NOT EXISTS `{prefix}email_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `to_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_html` MEDIUMTEXT NULL,
    `status` ENUM('queued', 'sending', 'indeterminate', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `claim_token` VARCHAR(64) NULL DEFAULT NULL,
    `claimed_at` DATETIME NULL DEFAULT NULL,
    `last_error` TEXT NULL,
    `scheduled_at` DATETIME NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_scheduled_at` (`scheduled_at`),
    INDEX `idx_status_scheduled` (`status`, `scheduled_at`)
    ,INDEX `idx_email_claim_recovery` (`status`, `claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
