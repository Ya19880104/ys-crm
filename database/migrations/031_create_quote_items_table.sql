-- 報價單明細（對應架構設計 §5.5 quote_items）。
--
-- quote_id：所屬報價單；報價單刪除時連帶刪除明細（CASCADE）。
-- name：項目名稱（必填）。description：項目說明（可空）。
-- qty：數量（DECIMAL，支援小數量如時數 1.5）。unit：單位（式 / 小時 / 個…，可空）。
-- unit_price：單價（DECIMAL(12,2)）。
-- amount：小計 = qty × unit_price，由 Service 伺服器端計算後寫入（不信前端）。
-- sort：明細排序（小→大，由上而下）。
CREATE TABLE IF NOT EXISTS `{prefix}quote_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quote_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `qty` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    `unit` VARCHAR(32) NOT NULL DEFAULT '',
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `sort` INT NOT NULL DEFAULT 0,
    INDEX `idx_quote_sort` (`quote_id`, `sort`),
    FOREIGN KEY (`quote_id`) REFERENCES `{prefix}quotes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
