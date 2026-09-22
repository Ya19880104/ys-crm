-- 付款系統權限註冊 + 指派（idempotent，可重複執行）。
-- 1) 註冊 2 個權限到 permissions 表（code 唯一，INSERT IGNORE 防重複）。
-- 2) 指派給 super_admin 與 admin 角色（role_permissions，INSERT IGNORE 防重複）。
--
-- 為何需要明確指派：權限檢查（RoleRepository::userHasPermission）純靠
-- role_permissions JOIN，並無 super_admin 特判；super_admin 的「全權限」是
-- 安裝當下 seeder 的快照，不會自動涵蓋日後新增的權限。故新權限必須在此明確指派，
-- 否則連 super_admin 也看不到付款記錄。
-- 與 034_seed_quote_permissions.sql / 029_seed_job_permissions.sql 風格一致。
--
-- payment.view：查看付款記錄（列表 / 詳情 / 原始回呼）。
-- payment.manage：管理付款（手動標記已付款等寫入動作）。

INSERT IGNORE INTO `{prefix}permissions` (`code`, `name`, `group_name`) VALUES
('payment.view',   '查看付款記錄', 'payment'),
('payment.manage', '管理付款',     'payment');

-- 指派給 super_admin（slug = super_admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'super_admin'
  AND p.code IN ('payment.view', 'payment.manage');

-- 指派給 admin（slug = admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'admin'
  AND p.code IN ('payment.view', 'payment.manage');
