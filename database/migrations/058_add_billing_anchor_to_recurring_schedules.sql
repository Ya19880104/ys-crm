-- 週期排程的永久出帳日錨點（F07）。
--
-- 【原本的缺陷】advanceDate() 只以「上一次的 next_run_at」推進，且對月底做單步截斷。
-- 於是 1/31 的排程推進成 2/28 之後，31 這個原始意圖就永久遺失：
--   1/31 → 2/28 → 3/28 → 4/28 …（正確應為 1/31 → 2/28 → 3/31 → 4/30 → 5/31）
-- 截斷本身是對的（避免 PHP 把 2/31 溢位成 3/3），錯的是把截斷後的值當成下一次的來源。
--
-- billing_anchor_day：使用者原始指定的出帳日（1–31），推進時不變。
--   NULL = 歷史資料未知 → 程式端退回舊行為（以當次日期為準），不改變既有結果。
--
-- 【backfill 的誠實界線】只能用 DAY(next_run_at) 回填「目前」的日子。
--   已經漂移過的排程無法還原原始日（那個資訊從未被保存），回填後會把漂移後的日子
--   固定下來 —— 這不是修好歷史，是停止繼續漂移。尚未漂移的排程（例如仍停在 31 號）
--   則會取得正確錨點，之後恆定。
--
-- MigrationRunner 的 ledger insert 發生在 MySQL DDL commit 之後；故 postcondition
-- 以 INFORMATION_SCHEMA 個別判定，使用 MySQL 5.7 相容的動態 DDL（同 040/052）。
SET @ys_crm_058_anchor_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = '{prefix}recurring_schedules'
       AND COLUMN_NAME = 'billing_anchor_day'
);
SET @ys_crm_058_anchor_sql = IF(
    @ys_crm_058_anchor_exists = 0,
    'ALTER TABLE `{prefix}recurring_schedules` ADD COLUMN `billing_anchor_day` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `next_run_at`',
    'DO 1'
);
PREPARE ys_crm_058_anchor_stmt FROM @ys_crm_058_anchor_sql;
EXECUTE ys_crm_058_anchor_stmt;
DEALLOCATE PREPARE ys_crm_058_anchor_stmt;

-- 回填：只補未設定者，重跑安全（已設定的錨點不得被目前的 next_run_at 覆寫，
-- 否則第二次執行會把已經正確的錨點改成漂移後的值）。
UPDATE `{prefix}recurring_schedules`
   SET `billing_anchor_day` = DAY(`next_run_at`)
 WHERE `billing_anchor_day` IS NULL
   AND `next_run_at` IS NOT NULL;
