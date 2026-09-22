-- 報價單公開 URL 瀏覽軌跡（對應架構設計 §5.5 quote_views、§7.8）。
--
-- 公開頁（/q/{token}）每次 GET 成功檢視即記一筆，供後台檢視「瀏覽軌跡」。
-- quote_id：所屬報價單；報價單刪除時連帶刪除軌跡（CASCADE）。
-- viewed_at：檢視時間。ip / user_agent：來源（user_agent 截斷至 500 字防爆量）。
-- customer_user_id：若為已登入客戶（customer_only / portal）檢視則記錄其 id；
--   本階段 portal 未建（P4-2 之後），一律 NULL，保留欄位。暫不加 FK（customer_users 表未建）。
CREATE TABLE IF NOT EXISTS `{prefix}quote_views` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quote_id` INT UNSIGNED NOT NULL,
    `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
    `customer_user_id` INT UNSIGNED NULL,
    INDEX `idx_quote_viewed` (`quote_id`, `viewed_at`),
    FOREIGN KEY (`quote_id`) REFERENCES `{prefix}quotes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
