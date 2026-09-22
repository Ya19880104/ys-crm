-- 通知表（對應架構設計 §5.7 notifications、§7.10 統一通知/到期引擎）。
--
-- 本表為「站內通知」的單一真相來源；cron 各掃描器（到期、待簽、待收、週期單）
-- 與系統事件（報價已簽、付款入帳）皆寫入本表。channel 含 email 時，由 NotificationService
-- 同時 enqueue 一筆 {prefix}email_queue（實際寄送走 cron mail 子命令）。
--
-- recipient_type / recipient_id：通知對象。
--   admin  → recipient_id = {prefix}users.id（我方員工；0 表示「全體管理員/系統」廣播）。
--   customer → recipient_id = {prefix}customers.id 或客戶帳號 id（portal 上線後串接）。
--   不設 FK：對象橫跨 admin/customer 兩平面，且允許 0（系統廣播），以 type 欄位區隔語意。
-- type：通知語意分類（如 quote.pending_sign / payment.due / hosting.expiring …），
--   供前台分組顯示與「冪等去重鍵」比對（同 type + related + 期間不重發）。
-- title / body：簡潔繁體中文標題與內文（含金額/編號/到期日）。
-- channel：in_app（僅站內）/ email（站內 + 進寄信佇列）。
-- related_type / related_id：關聯來源（quote / payment / hosting / website / recurring …），
--   供前台點擊跳轉、以及去重鍵組成。不設 FK（跨多表多型關聯，來源刪除時通知保留為歷史）。
-- is_read：是否已讀（站內鈴鐺/列表用）。
-- scheduled_at：預定產生/應顯示時間（cron 產生當下即填；保留供未來延遲通知）。
-- sent_at：email channel 實際寄出時間（由 cron mail 回填；in_app 可為 NULL）。
CREATE TABLE IF NOT EXISTS `{prefix}notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_type` ENUM('admin', 'customer', 'system') NOT NULL DEFAULT 'admin',
    `recipient_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `type` VARCHAR(64) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT NULL,
    `channel` ENUM('in_app', 'email') NOT NULL DEFAULT 'in_app',
    `related_type` VARCHAR(32) NULL,
    `related_id` INT UNSIGNED NULL,
    `is_read` TINYINT NOT NULL DEFAULT 0,
    `scheduled_at` DATETIME NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_recipient` (`recipient_type`, `recipient_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_scheduled_at` (`scheduled_at`),
    INDEX `idx_related` (`related_type`, `related_id`),
    INDEX `idx_dedup` (`type`, `related_type`, `related_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
