-- 客戶 Portal 帳號管理權限註冊 + 指派（idempotent，可重複執行）。
-- 這是「管理員端」管理客戶 portal 帳號的權限（非客戶端權限——客戶端權限以 customer_users.role
-- 與 permissions_json 在應用層強制，不走管理員 RBAC）。
--
-- 1) 註冊 2 個權限到 permissions 表（code 唯一，INSERT IGNORE 防重複）。
--    - customer_user.view：檢視某客戶的 portal 帳號清單。
--    - customer_user.manage：建立 / 停用 / 重設密碼 / 改權限客戶 portal 帳號。
-- 2) 指派給 super_admin 與 admin 角色（role_permissions，INSERT IGNORE 防重複）。
--
-- 為何需要明確指派：權限檢查（RoleRepository::userHasPermission）純靠 role_permissions JOIN，
-- 並無 super_admin 特判；super_admin 的「全權限」是安裝當下 seeder 的快照，不會自動涵蓋日後
-- 新增的權限。故新權限必須在此明確指派，否則連 super_admin 也無法管理客戶 portal 帳號。
-- 與 021_seed_customer_permissions.sql / 034_seed_quote_permissions.sql 風格一致。

INSERT IGNORE INTO `{prefix}permissions` (`code`, `name`, `group_name`) VALUES
('customer_user.view',   '檢視客戶登入帳號', 'customer'),
('customer_user.manage', '管理客戶登入帳號', 'customer');

-- 指派給 super_admin（slug = super_admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'super_admin'
  AND p.code IN ('customer_user.view', 'customer_user.manage');

-- 指派給 admin（slug = admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'admin'
  AND p.code IN ('customer_user.view', 'customer_user.manage');
