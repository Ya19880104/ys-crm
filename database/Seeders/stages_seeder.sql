INSERT IGNORE INTO `{prefix}stages` (`board_id`, `name`, `slug`, `color`, `sort_order`, `is_public`, `is_default`, `allow_drag_in`, `allow_drag_out`) VALUES
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'public-board'), '提案',   'proposal',    '#8B5CF6', 1, 1, 1, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'public-board'), '核准',   'approved',    '#3B82F6', 2, 1, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'public-board'), '開發中', 'in-progress', '#F59E0B', 3, 1, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'public-board'), '測試',   'testing',     '#EF4444', 4, 1, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'public-board'), '已發布', 'released',    '#46CAEB', 5, 1, 0, 1, 0),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'internal-board'), '提案',   'proposal',    '#8B5CF6', 1, 0, 1, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'internal-board'), '核准',   'approved',    '#3B82F6', 2, 0, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'internal-board'), '開發中', 'in-progress', '#F59E0B', 3, 0, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'internal-board'), '測試',   'testing',     '#EF4444', 4, 0, 0, 1, 1),
((SELECT `id` FROM `{prefix}boards` WHERE `slug` = 'internal-board'), '已發布', 'released',    '#46CAEB', 5, 0, 0, 1, 0);
