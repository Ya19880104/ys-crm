INSERT IGNORE INTO `{prefix}roles` (`slug`, `name`, `description`, `level`, `is_system`) VALUES
('super_admin', '超級管理員', '系統最高權限，含安裝、API、使用者管理', 1, 1),
('admin',       '主管',       '核准提案、指派負責人、查看全部看板', 2, 1),
('staff',       '員工',       '建立提案、管理自己負責的卡片', 3, 1),
('public',      '訪客',       '查看公開看板、提交許願表單', 4, 1);
