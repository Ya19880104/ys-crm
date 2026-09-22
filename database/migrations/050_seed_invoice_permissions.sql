-- 電子發票權限註冊 + 指派（idempotent，可重複執行）。
--
-- 權限切分理由：
--   invoice.view    檢視發票清單與明細（含 API log）——一般業務即可。
--   invoice.issue   手動開立 / 重試 —— 會產生真實發票（國稅局留存），限管理層級。
--   invoice.cancel  作廢 / 重開 —— 影響已開立的法定憑證，風險最高。
--   invoice.settings 修改 PayNow 憑證與開立設定 —— 等同金鑰管理。
--
-- 與 034_seed_quote_permissions.sql / 045_seed_customer_user_permissions.sql 風格一致：
-- 新權限必須明確指派給 super_admin，因為權限檢查純靠 role_permissions JOIN，
-- 並無 super_admin 特判（其「全權限」只是安裝當下的快照）。

INSERT IGNORE INTO `{prefix}permissions` (`code`, `name`, `group_name`) VALUES
('invoice.view',     '檢視電子發票',   'invoice'),
('invoice.issue',    '開立電子發票',   'invoice'),
('invoice.cancel',   '作廢電子發票',   'invoice'),
('invoice.settings', '設定電子發票',   'invoice');

-- super_admin：全部 4 個權限
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'super_admin'
  AND p.code IN ('invoice.view', 'invoice.issue', 'invoice.cancel', 'invoice.settings');

-- admin：全部 4 個權限
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'admin'
  AND p.code IN ('invoice.view', 'invoice.issue', 'invoice.cancel', 'invoice.settings');
