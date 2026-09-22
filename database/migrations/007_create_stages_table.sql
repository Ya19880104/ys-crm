CREATE TABLE IF NOT EXISTS `{prefix}stages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `board_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) NOT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6B7280',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_public` TINYINT(1) NOT NULL DEFAULT 0,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_drag_in` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_drag_out` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL,
    UNIQUE KEY `uk_board_slug` (`board_id`, `slug`),
    INDEX `idx_board_sort` (`board_id`, `sort_order`),
    FOREIGN KEY (`board_id`) REFERENCES `{prefix}boards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
