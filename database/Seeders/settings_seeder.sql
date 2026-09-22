INSERT IGNORE INTO `{prefix}settings` (`setting_group`, `setting_key`, `setting_value`, `is_encrypted`) VALUES
('site', 'site_name',           'YS CRM',          0),
('site', 'site_url',            '',                0),
('site', 'site_timezone',       'Asia/Taipei',     0),
('site', 'site_locale',         'zh_TW',           0),
('security', 'login_max_attempts',      '5',   0),
('security', 'login_lockout_minutes',   '15',  0),
('security', 'session_lifetime_minutes','120',  0),
('security', 'csrf_token_lifetime',     '3600', 0),
('turnstile', 'site_key',    '', 0),
('turnstile', 'secret_key',  '', 1),
('notification', 'admin_email',      '', 0),
('notification', 'notify_on_wish',   '1', 0);
