-- 工作追加內容（對應架構設計 §5.3 job_entries、§7.6「持續追加內容、附時間、可附圖」）。
--
-- 每筆 entry 為一則工作進度紀錄，依時間累積；類似既有 card_comments。
-- job_id：所屬工作；工作刪除時連帶刪除其所有 entries（CASCADE）。
-- admin_user_id：撰寫者（admin user id，可 NULL，使用者刪除時設 NULL 保留內容）。
-- content：文字內容（必填）。
-- file_id：附圖檔案 ID，保留欄位對應未來 files 表（§5.7，尚未建立）；目前一律 NULL。
-- image_path：附圖實際路徑（/uploads/jobs/...），沿用既有 Media 字串路徑模式。
-- media_type：附件類型（目前僅 image；保留 video 以對齊 card_comments 結構）。
-- created_at：建立時間（即「附時間」；以伺服器端 NOW() 為準，不接受前端傳入）。
CREATE TABLE IF NOT EXISTS `{prefix}job_entries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `admin_user_id` INT UNSIGNED NULL,
    `content` TEXT NOT NULL,
    `file_id` INT UNSIGNED NULL,
    `image_path` VARCHAR(500) NULL,
    `media_type` ENUM('image', 'video') NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_job` (`job_id`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`job_id`)        REFERENCES `{prefix}jobs`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`admin_user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
