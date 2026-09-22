-- 工作卡片（對應架構設計 §5.3 jobs、§7.6 工作看板）。
--
-- column_id：所屬看板欄位（接洽 / 報價 / 執行 / 完成…）。欄位含卡片時不可刪除（RESTRICT）。
-- customer_id：可 NULL = 非客戶工作（內部任務）；客戶刪除時設 NULL（保留工作紀錄，不連帶刪）。
-- title / description：標題與內容。
-- cover_file_id：封面圖檔案 ID，保留欄位對應未來 files 表（§5.7，尚未建立）；目前一律 NULL。
-- cover_image_path：封面圖實際路徑（/uploads/jobs/...），沿用既有 Media 字串路徑模式（與 card_comments 一致）。
--   待 files 表上線後可將 cover_image_path 遷移為 cover_file_id 參照。
-- priority：優先度（low / medium / high / critical），預設 medium。
-- sort：同欄位內卡片排序（小→大，由上而下）；拖拉移動時更新。
-- assigned_to：負責人（admin user id，可 NULL）；使用者刪除時設 NULL。
-- due_date：到期日（可 NULL）。
-- related_quote_id：關聯報價單 ID，保留欄位對應未來報價單模組（§5.5，尚未建立）；目前一律 NULL，
--   暫不加 FK（報價表未建），待報價模組上線時以後續 migration 補 FK。
-- created_by：建立者（admin user id）；使用者刪除時設 NULL。
-- completed_at：完成時間（移入「完成」類欄位時由 Service 寫入；移出時清空）。可 NULL。
CREATE TABLE IF NOT EXISTS `{prefix}jobs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `column_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `cover_file_id` INT UNSIGNED NULL,
    `cover_image_path` VARCHAR(500) NULL,
    `priority` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    `sort` INT NOT NULL DEFAULT 0,
    `assigned_to` INT UNSIGNED NULL,
    `due_date` DATE NULL,
    `related_quote_id` INT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_column_sort` (`column_id`, `sort`),
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_assigned_to` (`assigned_to`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_due_date` (`due_date`),
    INDEX `idx_related_quote` (`related_quote_id`),
    FOREIGN KEY (`column_id`)   REFERENCES `{prefix}job_columns`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`)   ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to`) REFERENCES `{prefix}users`(`id`)       ON DELETE SET NULL,
    FOREIGN KEY (`created_by`)  REFERENCES `{prefix}users`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
