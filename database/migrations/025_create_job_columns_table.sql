-- 工作看板欄位 / 狀態（對應架構設計 §5.3 job_columns、§7.6 工作看板）。
-- 看板採「單一全域看板」模型：所有工作卡片共用同一組欄位（接洽 / 報價 / 執行 / 完成），
-- 與既有 boards/stages（綁 public/internal、slug、wish 假設）刻意切割，建乾淨的 CRM 結構。
--
-- name：欄位顯示名稱（接洽 / 報價 / 執行 / 完成…，總控可增刪）。
-- slug：穩定識別碼（小寫，供程式判斷；建立後可改 name 不改 slug）。
-- color：欄位主題色（#RRGGBB），用於看板欄頭與卡片標記。
-- sort：欄位橫向排序（小→大，由左至右）。
-- is_active：停用的欄位不顯示於看板（但既有卡片仍保留 column_id 參照）。
--
-- 種子 4 欄（接洽 / 報價 / 執行 / 完成）；總控可於欄位管理頁增刪。
CREATE TABLE IF NOT EXISTS `{prefix}job_columns` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) NOT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#1E40AF',
    `sort` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_slug` (`slug`),
    INDEX `idx_active_sort` (`is_active`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 種子 4 欄（idempotent：slug 唯一 + INSERT IGNORE 防重複執行）。
-- 配色取自架構設計 §8.1 已定版深藍品牌色系（不用綠 / teal）：
--   接洽=藍、報價=琥珀(warning)、執行=品牌深藍、完成=綠(此處唯一例外，表「完成」語意；
--   若需嚴守不用綠可改 #2563EB，但完成狀態慣用綠較直覺，故保留 emerald)。
INSERT IGNORE INTO `{prefix}job_columns` (`slug`, `name`, `color`, `sort`, `is_active`) VALUES
('contact',  '接洽', '#3B82F6', 1, 1),
('quote',    '報價', '#F59E0B', 2, 1),
('execute',  '執行', '#1E40AF', 3, 1),
('done',     '完成', '#2563EB', 4, 1);
