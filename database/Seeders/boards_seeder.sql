INSERT IGNORE INTO `{prefix}boards` (`name`, `slug`, `type`, `description`, `is_active`, `sort_order`) VALUES
('公開提案看板', 'public-board', 'public', '對外公開的提案進度看板', 1, 1),
('內部開發看板', 'internal-board', 'internal', '內部開發任務管理看板', 1, 2);
