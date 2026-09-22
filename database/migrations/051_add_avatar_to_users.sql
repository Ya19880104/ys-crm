-- 使用者頭像。
--
-- 存的是相對於 web root 的路徑（例如 /uploads/avatars/20260816_ab12cd34.png），
-- 不是實體檔案路徑 —— 這樣搬站或改 docroot 時不需要改資料。
--
-- 留空表示未上傳，介面會顯示預設圖案（不另存預設圖路徑，避免日後換預設圖時
-- 要回頭更新每一筆資料）。
--
-- 上傳檔案的安全處理與其他上傳點一致：MIME 白名單 + getimagesize() 真實圖片驗證
-- + 隨機檔名（見 UserService::handleAvatarUpload）。
SET @ys_crm_051_avatar_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}users'
       AND COLUMN_NAME = 'avatar_path'
);
SET @ys_crm_051_avatar_sql = IF(
    @ys_crm_051_avatar_exists = 0,
    'ALTER TABLE `{prefix}users` ADD COLUMN `avatar_path` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `display_name`',
    'DO 1'
);
PREPARE ys_crm_051_avatar_stmt FROM @ys_crm_051_avatar_sql;
EXECUTE ys_crm_051_avatar_stmt;
DEALLOCATE PREPARE ys_crm_051_avatar_stmt;
