-- 電子發票新增 `abandoned` 狀態：把「系統已放棄、等人工處理」變成一個真正的狀態。
--
-- 🔴 【為什麼需要這個狀態】原本「已放棄」只存在於某些查詢的 WHERE 條件裡
-- （failed + next_retry_at IS NULL），而不是資料本身的一部分。後果有兩個：
--
--   1. **上限可被繞過**：claimForIssue() 的 CAS 條件是
--      `status IN ('pending','failed','scheduled')`，完全不看 retry_count。
--      金流商重送付款通知（常態行為）就會把已放棄的發票重新撿起來送出 ——
--      每一次重送都再打一次 PayNow，上限形同不存在。
--      `issued` / `cancelled` 之所以是真終態，正因為它們在 ENUM 裡；
--      「已放棄」不在，所以守不住其他終態守得住的地方。
--
--   2. **同一個概念有四份定義**：findNeedsAttention() 的 SQL、
--      needsAttentionCount() 的 SQL、isExhausted() 的 PHP（規則還不同，
--      多要求 retry_count >= MAX）、以及後台 view 裡的第四份 inline 複本。
--
-- 【回填】舊資料必須一併處理，否則會變成兩邊都看不到的孤兒：
-- 上限是後來才加的，所以既有站台必然存在 `retry_count >= 5 AND next_retry_at
-- IS NOT NULL` 的列 —— 它們被新的重試查詢濾掉（不會再重試），又不符合
-- 「needs attention」的 next_retry_at IS NULL（後台也看不到）。

ALTER TABLE `{prefix}invoices`
    MODIFY COLUMN `status`
    ENUM('pending', 'scheduled', 'issuing', 'issued', 'failed', 'cancelled', 'abandoned')
    NOT NULL DEFAULT 'pending';

-- 回填兩類：
--   (a) 已標記為不再重試（終局拒絕）—— next_retry_at IS NULL
--   (b) 重試次數已達上限，但因為上限是後來才加的而仍留著 next_retry_at
UPDATE `{prefix}invoices`
   SET `status` = 'abandoned',
       `next_retry_at` = NULL
 WHERE `status` = 'failed'
   AND (`next_retry_at` IS NULL OR `retry_count` >= 5);
