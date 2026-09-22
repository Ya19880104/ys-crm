-- 客戶資產（網站 / 主機）權限註冊 + 指派（idempotent，可重複執行）。
-- 1) 註冊 8 個權限到 permissions 表（code 唯一，INSERT IGNORE 防重複）。
-- 2) 指派給 super_admin 與 admin 角色（role_permissions，INSERT IGNORE 防重複）。
--
-- 為何需要明確指派：權限檢查（RoleRepository::userHasPermission）純靠
-- role_permissions JOIN，並無 super_admin 特判；super_admin 的「全權限」是
-- 安裝當下 seeder 的快照，不會自動涵蓋日後新增的權限。故新權限必須在此明確指派，
-- 否則連 super_admin 也看不到網站 / 主機資產。
-- 與 021_seed_customer_permissions.sql 風格一致。

INSERT IGNORE INTO `{prefix}permissions` (`code`, `name`, `group_name`) VALUES
('websites.view',   '查看客戶網站', 'asset'),
('websites.create', '建立客戶網站', 'asset'),
('websites.edit',   '編輯客戶網站', 'asset'),
('websites.delete', '刪除客戶網站', 'asset'),
('hosting.view',    '查看客戶主機', 'asset'),
('hosting.create',  '建立客戶主機', 'asset'),
('hosting.edit',    '編輯客戶主機', 'asset'),
('hosting.delete',  '刪除客戶主機', 'asset');

-- 指派給 super_admin（slug = super_admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'super_admin'
  AND p.code IN (
        'websites.view', 'websites.create', 'websites.edit', 'websites.delete',
        'hosting.view',  'hosting.create',  'hosting.edit',  'hosting.delete'
      );

-- 指派給 admin（slug = admin）
INSERT IGNORE INTO `{prefix}role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `{prefix}roles` r
CROSS JOIN `{prefix}permissions` p
WHERE r.slug = 'admin'
  AND p.code IN (
        'websites.view', 'websites.create', 'websites.edit', 'websites.delete',
        'hosting.view',  'hosting.create',  'hosting.edit',  'hosting.delete'
      );
