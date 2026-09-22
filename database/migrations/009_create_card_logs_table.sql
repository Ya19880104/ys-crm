CREATE TABLE IF NOT EXISTS `{prefix}card_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `card_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `field_name` VARCHAR(100) NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_card` (`card_id`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`card_id`) REFERENCES `{prefix}cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
