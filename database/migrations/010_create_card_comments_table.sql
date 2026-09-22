CREATE TABLE IF NOT EXISTS `{prefix}card_comments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `content` TEXT NOT NULL,
    `image_path` VARCHAR(500) DEFAULT NULL COMMENT '附件路徑（圖片或影片）',
    `media_type` ENUM('image','video') DEFAULT NULL COMMENT '附件類型',
    `noted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '備註日期（可手動修改）',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_card` (`card_id`),
    INDEX `idx_noted_at` (`noted_at`),
    FOREIGN KEY (`card_id`) REFERENCES `{prefix}cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
