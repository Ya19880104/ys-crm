-- 報價單線上簽署存證（對應架構設計 §5.5 quote_signatures、§7.8）。
--
-- 法律軌跡 + 不可竄改快照：簽署當下記錄簽署人、簽名圖、我方印章快照、內容雜湊與來源。
-- quote_id：所屬報價單；報價單刪除時連帶刪除簽署紀錄（CASCADE）。
-- signer_name：簽署人姓名（公開頁必填）。
-- signer_type：guest=訪客（公開連結直接簽）/ customer=已登入客戶（portal，P4-2 之後）。
-- signature_image_path：客戶簽名板輸出的 PNG 路徑（/uploads/quotes/...），沿用既有字串路徑模式。
-- our_seal_path：簽署當下的公司印章路徑快照（讀 SettingService company.company_seal）；
--   即使日後設定變更，此筆仍保留簽署當時所用印章，作為存證。可 NULL（未設定印章時）。
-- document_hash：簽署當下報價內容（編號/標題/明細/金額/條款等）的 sha256（CHAR(64)），
--   作為「不可竄改快照」憑證；事後比對可證明簽署時的內容。
-- signed_at / signed_ip / user_agent：簽署時間與來源（user_agent 截斷至 500 字）。
CREATE TABLE IF NOT EXISTS `{prefix}quote_signatures` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quote_id` INT UNSIGNED NOT NULL,
    `signer_name` VARCHAR(150) NOT NULL,
    `signer_type` ENUM('guest', 'customer') NOT NULL DEFAULT 'guest',
    `signature_image_path` VARCHAR(500) NOT NULL DEFAULT '',
    `our_seal_path` VARCHAR(500) NULL,
    `document_hash` CHAR(64) NOT NULL DEFAULT '',
    `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `signed_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
    INDEX `idx_quote_signed` (`quote_id`, `signed_at`),
    FOREIGN KEY (`quote_id`) REFERENCES `{prefix}quotes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
