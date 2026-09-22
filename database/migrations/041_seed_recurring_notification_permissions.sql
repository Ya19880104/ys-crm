-- 週期帳務 + 通知記錄權限註冊 + 指派（idempotent，可重複執行）。
-- 1) 註冊 3 個權限到 permissions 表（code 唯一，INSERT IGNORE 防重複）。
-- 2) 指派給 super_admin 與 admin 角色（role_permissions，INSERT IGNORE 防重複）。
--
-- 為何需要明確指派：權限檢查（RoleRepository::userHasPermission）純靠 role_permissions JOIN，
-- 並無 super_admin 特判；super_admin 的「全權限」是安裝當下 seeder 的快照，不會自動涵蓋日後
-- 新增的權限。故新權限必須在此明確指派，否則連 super_admin 也看不到週期/通知功能。
-- 與 036_seed_payment_permissions.sql / 034_seed_quote_permissions.sql 風格一致。
--
-- notification.view：查看通知記錄與寄信佇列（含重送失敗信）。
-- recurring.view：   查看週期帳務排程列表/詳情。
-- recurring.manage： 管理週期排程（建立/編輯/啟停/立即產生帳單）。

INSERT IGNORE INTO `{prefix}permissions` (`code`, `name`, `group_name`) VALUES
('notification.view', '查看通知記錄', 'notification'),
('recurring.view',    '查看週期帳務', 'recurring'),
('recurring.manage',  '管理週期帳務', 'recurring');

-- 指派給 super_admin（slug = super_admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'super_admin'
  AND p.code IN ('notification.view', 'recurring.view', 'recurring.manage');

-- 指派給 admin（slug = admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'admin'
  AND p.code IN ('notification.view', 'recurring.view', 'recurring.manage');
