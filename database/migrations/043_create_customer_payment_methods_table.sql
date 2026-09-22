-- 客戶儲存的付款方式（卡片 Token；對應架構設計 §5.2 payment_methods、§6 金流安全、§7.9）。
--
-- 🔴 絕不儲存完整卡號（PAN）或 CVV/CVC。僅存 provider 回傳的代碼化 Token 與顯示用的
--    品牌（brand）+ 末四碼（last4）。符合 PCI-DSS 與 §6「永不存 PAN/CVV、僅存 provider token」。
--
-- 設計要點：
-- - customer_id：所屬客戶（強制 scope）。客戶刪除時連帶刪除（CASCADE）。
-- - customer_user_id：實際新增此卡片的 portal 帳號（owner 或 member）。建立者帳號刪除時設 NULL
--   （卡片仍屬於 customer，不應因建立者離開而消失）。
-- - provider：金流商（payuni / shopline / sandbox）。對應 PaymentProviderRegistry 的 key。
-- - brand / last4 / exp_month / exp_year：顯示用，非機密（last4 非完整卡號）。
-- - token_ref：provider 託管 Token 的「加密」儲存（AES-256-GCM，Encryption 類）。
--   ★ 真實 tokenization 留接點：本階段先存佔位 token（PLACEHOLDER-...）的密文，
--     待金流 vault（PayUni/Shopline tokenize API）整合後改存真實 token 密文，
--     供 RecurringService::autoChargeDue 之 chargeWithToken 引用。
-- - is_default：是否為預設卡（同 customer 至多一張預設，由 Service 維護）。
-- - is_active：軟刪除旗標（刪卡 → is_active=0，保留稽核軌跡；不真正 DELETE 以免破壞付款關聯）。
CREATE TABLE IF NOT EXISTS `{prefix}customer_payment_methods` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `customer_user_id` INT UNSIGNED NULL,
    `provider` VARCHAR(32) NOT NULL DEFAULT 'sandbox',
    `brand` VARCHAR(32) NOT NULL DEFAULT '',
    `last4` VARCHAR(4) NOT NULL DEFAULT '',
    `exp_month` TINYINT UNSIGNED NULL,
    `exp_year` SMALLINT UNSIGNED NULL,
    `token_ref` TEXT NULL,
    `label` VARCHAR(100) NOT NULL DEFAULT '',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_customer_user_id` (`customer_user_id`),
    INDEX `idx_default` (`customer_id`, `is_default`, `is_active`),
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`customer_user_id`) REFERENCES `{prefix}customer_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
