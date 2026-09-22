-- 排程執行紀錄。
--
-- 【為什麼需要這張表】（2026-09-02）
-- 週期帳單停在 2026-07-05 沒有產生，排查了半天才確認：程式完全正常，
-- 只是**從來沒有任何東西觸發過它**（容器內沒有 crontab，要在主機面板設定）。
-- 當時後台沒有任何地方看得出「上次跑是什麼時候」，只能從資料反推。
--
-- 有了這張表，設定頁可以直接回答三個問題：
--   1. 排程到底有沒有在跑？（最近一次成功是什麼時候）
--   2. 是誰觸發的？（cli / http / admin）
--   3. 跑出什麼結果／為什麼失敗？
--
-- 同時兼作**併發鎖**：status='running' 且未逾時的列存在時，拒絕重複觸發。
-- 排程子命令本身雖然都是冪等的，但同時跑兩份只會互相搶鎖、拖慢彼此。
CREATE TABLE IF NOT EXISTS `{prefix}cron_runs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `command` VARCHAR(32) NOT NULL,

    -- 觸發來源：cli（主機排程）/ http（外部 URL 觸發）/ admin（後台手動）
    `source` ENUM('cli', 'http', 'admin') NOT NULL DEFAULT 'cli',

    -- admin 來源時記錄操作者；其餘為 NULL
    `actor_user_id` INT UNSIGNED NULL,

    `status` ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',

    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `finished_at` DATETIME NULL,
    `duration_ms` INT UNSIGNED NULL,

    -- 執行摘要（各子命令的處理筆數）或失敗訊息。不放敏感內容。
    `output` TEXT NULL,

    INDEX `idx_command_started` (`command`, `started_at`),
    INDEX `idx_status` (`status`),
    INDEX `idx_started` (`started_at`),
    FOREIGN KEY (`actor_user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
