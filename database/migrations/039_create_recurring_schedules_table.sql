-- 週期性帳務排程（對應架構設計 §5.6 recurring_schedules、§7.8 週期單、§7.9 付款系統）。
--
-- 一張排程綁定一張「來源報價」，依 interval（日/月/年）週期性產生新報價 + 一筆 pending 付款。
-- Zero Trust / 杜絕重複出帳：
--   - generateDue 於交易內 SELECT ... FOR UPDATE 鎖定排程列。
--   - 冪等：以 last_generated_at + next_run_at 推進判斷同期是否已產生，重跑不重複產生。
--   - 產生付款走 PaymentRepository 冪等 insert（idempotency_key = 排程 id + 期間鍵）。
--
-- quote_id：來源報價（複製其明細產生新報價）。報價刪除時設 NULL（排程失效，cron 會略過無來源者）。
-- customer_id：對象客戶（可 NULL）。客戶刪除時設 NULL。
-- interval_unit / interval_value：週期單位與數值（如 unit=month, value=1 表每月；value=3 + unit=month 表每季）。
-- next_run_at：下一次「應產生」的日期（DATE）。generateDue 找 next_run_at <= today + advance_generate_days。
-- advance_generate_days：提前產生天數（到期前幾天先產生帳單）。預設取設定 recurring_advance_days；
--   排程層此欄位為 override（NULL 表示用全域設定）。
-- auto_charge_after_days：產生後幾天自動扣款（payment_mode=auto_card 時）。NULL 表示用全域設定。
-- payment_mode：
--   auto_card  → 綁卡自動扣款（需 payment_method_id；目前無真實綁卡 → autoCharge 標記待人工 + 提醒）。
--   manual_atm → 純提醒（產生帳單後通知客戶以虛擬/離線 ATM 付款，不自動扣款）。
-- payment_method_id：綁定的卡片 token（{prefix}payment_methods.id；P4-2 portal 卡片管理）。本階段恆 NULL。
--   注意：payment_methods 表於 P4-2 才建，故此處不設 FK（避免遷移順序耦合），由 Service 層維護一致性。
-- is_active：排程是否啟用（停用後 cron 略過）。
-- last_generated_at：最近一次成功產生帳單的日期（冪等鍵；防同期重複產生）。
CREATE TABLE IF NOT EXISTS `{prefix}recurring_schedules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quote_id` INT UNSIGNED NULL,
    `customer_id` INT UNSIGNED NULL,
    `interval_unit` ENUM('day', 'month', 'year') NOT NULL DEFAULT 'month',
    `interval_value` INT UNSIGNED NOT NULL DEFAULT 1,
    `next_run_at` DATE NOT NULL,
    `advance_generate_days` INT UNSIGNED NULL,
    `auto_charge_after_days` INT UNSIGNED NULL,
    `payment_mode` ENUM('auto_card', 'manual_atm') NOT NULL DEFAULT 'manual_atm',
    `payment_method_id` INT UNSIGNED NULL,
    `is_active` TINYINT NOT NULL DEFAULT 1,
    `last_generated_at` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_next_run_at` (`next_run_at`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_quote_id` (`quote_id`),
    INDEX `idx_active_next` (`is_active`, `next_run_at`),
    FOREIGN KEY (`quote_id`)    REFERENCES `{prefix}quotes`(`id`)    ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `{prefix}customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
