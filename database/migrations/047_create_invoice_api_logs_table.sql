-- 電子發票 API 往來紀錄。移植自 ys-enhance-hosting 的 ys_hosting_einvoice_api_logs。
--
-- 【為何值得一張專屬表】發票開立失敗的診斷幾乎完全靠這張表：PayNow 的 422 回應會在
-- result 物件逐欄列出錯誤原因（例如「Invalid BuyerPhone」「could not be converted to CarrierType」），
-- 那是唯一能知道「哪個欄位不合格」的來源。沒有這張表，欄位規則只能用猜的。
--
-- 安全：request_payload / response_payload 寫入前，PayNowRestClient::scrubToken() 已把 JWT
-- 置換成 ***REDACTED***。切勿在其他地方直接寫入未遮蔽的內容。
--
-- correlation_id：同一次業務操作（含開立前查詢 + 開立 + timeout 補查）共用一組 UUID，
--   讓多次 API 呼叫可以串成一條時間軸。
-- operation：issue / query / cancel / connection_test
-- http_status：0 表示連線層失敗（DNS/timeout/TLS），非 HTTP 回應。
-- duration_ms：耗時，用於觀察 PayNow 端效能與判斷 timeout 傾向。
CREATE TABLE IF NOT EXISTS `{prefix}invoice_api_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT UNSIGNED NULL,
    `payment_id` INT UNSIGNED NULL,
    `correlation_id` VARCHAR(64) NOT NULL DEFAULT '',
    `operation` VARCHAR(32) NOT NULL DEFAULT '',
    `environment` VARCHAR(16) NOT NULL DEFAULT 'test',
    `http_method` VARCHAR(8) NOT NULL DEFAULT 'POST',
    `endpoint` VARCHAR(255) NOT NULL DEFAULT '',
    `http_status` INT NOT NULL DEFAULT 0,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `request_id` VARCHAR(64) NOT NULL DEFAULT '',
    `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `request_payload` LONGTEXT NULL,
    `response_payload` LONGTEXT NULL,
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_invoice_id` (`invoice_id`),
    INDEX `idx_payment_id` (`payment_id`),
    INDEX `idx_correlation_id` (`correlation_id`),
    INDEX `idx_operation` (`operation`),
    INDEX `idx_success` (`success`),
    INDEX `idx_request_id` (`request_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
