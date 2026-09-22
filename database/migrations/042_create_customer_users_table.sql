-- 客戶端登入帳號（Portal / 客戶專區；對應架構設計 §5.2 customer_users、§7.5）。
-- 這是系統「第二個對外認證面」，與管理員（{prefix}users）完全隔離。
--
-- 設計要點（Zero Trust）：
-- - customer_id：此帳號所屬客戶（強制 scope 的根據）。客戶刪除時連帶刪除其所有 portal 帳號（CASCADE）。
-- - parent_user_id：子帳號自參照。NULL = 主帳號（owner）；非 NULL = 子帳號（member），
--   其 parent 必為同一 customer_id 下的 owner。owner 刪除時其子帳號一併刪除（CASCADE 自參照）。
-- - role：owner（客戶主帳號，全權）/ member（子帳號，受 permissions_json 限制）。
--   同一 customer_id 下「至多一個 owner」由 Service 層維護（建立 portal 帳號時固定 owner，子帳號一律 member）。
-- - login_email：登入帳號（全表唯一；客戶端以 email 登入）。與管理員 username/email 命名空間獨立。
-- - password_hash：password_hash(PASSWORD_DEFAULT)（bcrypt）。客戶平面密碼，與管理員 argon2id 各自獨立。
--   （管理員端用 argon2id；客戶端用 PASSWORD_DEFAULT，因 portal 為對外、相容性優先，且 rehash 端一致。）
-- - permissions_json：member 的能力 scopes（JSON 陣列，如 ["quotes","payments"]）。
--   owner 忽略此欄位（恆全權）。可操作區塊白名單見 CustomerGuard::ALL_SCOPES。
-- - is_active：停用旗標。停用後即使密碼正確也拒絕登入（owner 可停用 member）。
-- - last_login_at / last_login_ip：稽核用最近登入資訊。
-- - created_by：建立此帳號的管理員（{prefix}users.id），管理員刪除時設 NULL（保留帳號）。
CREATE TABLE IF NOT EXISTS `{prefix}customer_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `parent_user_id` INT UNSIGNED NULL,
    `role` ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    `login_email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL DEFAULT '',
    `permissions_json` JSON NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL,
    `last_login_ip` VARCHAR(45) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_login_email` (`login_email`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_parent_user_id` (`parent_user_id`),
    INDEX `idx_is_active` (`is_active`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_user_id`) REFERENCES `{prefix}customer_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `{prefix}users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
