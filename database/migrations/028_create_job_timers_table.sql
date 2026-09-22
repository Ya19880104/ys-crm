-- 工作計時器分段（對應架構設計 §5.3 job_timers、§7.6「計時器：開始 / 停止 / 繼續」）。
--
-- 計時採「分段」模型：每按一次「開始」或「繼續」即新增一筆 running 段；
-- 按「停止」則將該 running 段設 stopped_at + duration_seconds 並轉 stopped。
-- 同一工作多段 stopped 的 duration_seconds 加總 = 該工作累計工時。
--
-- status 採 running / stopped 兩態（task 規格）：
--   running：計時中（stopped_at 為 NULL，duration_seconds 暫為 0）。
--   stopped：已停止（stopped_at 與 duration_seconds 已定）。
-- 「繼續」不使用獨立 paused 狀態，而是新增一筆 running 段（語意上等同先前段已 stopped）。
--
-- 「同一 job 同時至多一個 running」由 JobService 於交易內以
-- SELECT ... FOR UPDATE 檢查後再 INSERT 保證（DB 層 partial unique index MySQL 不支援，
-- 故以應用層交易 + 此處 idx_job_status 加速查詢達成）。
--
-- job_id：所屬工作；工作刪除時連帶刪除其所有計時段（CASCADE）。
-- admin_user_id：操作者（admin user id，可 NULL，使用者刪除時設 NULL 保留工時）。
-- started_at / stopped_at：分段起訖（伺服器端 NOW()；stopped_at 於停止時寫入）。
-- duration_seconds：該段秒數（停止時 = stopped_at - started_at；running 時為 0）。
-- note：分段備註（可空）。
CREATE TABLE IF NOT EXISTS `{prefix}job_timers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `admin_user_id` INT UNSIGNED NULL,
    `started_at` DATETIME NOT NULL,
    `stopped_at` DATETIME NULL,
    `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('running', 'stopped') NOT NULL DEFAULT 'running',
    `note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_job_status` (`job_id`, `status`),
    INDEX `idx_admin_user` (`admin_user_id`),
    FOREIGN KEY (`job_id`)        REFERENCES `{prefix}jobs`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`admin_user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
