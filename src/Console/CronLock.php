<?php

declare(strict_types=1);

namespace YangSheep\CRM\Console;

use YangSheep\CRM\Core\Database;

/**
 * 排程的跨 process 互斥鎖。
 *
 * 🔴 【為什麼要抽成一個類別】原本的互斥是「先 SELECT COUNT 看有沒有 running，
 * 再 INSERT 一列」—— 兩道獨立敘述、沒有交易、沒有列鎖，cron_runs 也沒有任何
 * UNIQUE 約束。兩個觸發在同一毫秒抵達時（外部排程逾時重送、管理員連按兩下），
 * 兩邊都會看到 0、都插入、都執行。對 recurring 而言那就是同一期出兩次帳，
 * 而那個方法的回傳訊息卻寫著「避免重複出帳」。
 *
 * 改用 MySQL 的具名鎖：取得與否是原子的，連線中斷時由 DB 自動釋放，
 * 不需要靠「超過 N 分鐘就當作死了」去猜。本專案已用同一套機制保護發票開立
 *（InvoiceRepository::acquireLock），這裡是沿用而非新發明。
 *
 * 【為什麼是一個可注入的物件，而不是 CronRunner 的私有方法】
 * 真正的互斥只有在多連線的 MySQL 上才成立，單一進程的測試環境驗不到它
 *（SQLite 連 GET_LOCK 都沒有）。把它抽出來之後，測試可以注入一個「第二次
 * 取鎖會失敗」的替身，去驗證 CronRunner 在取不到鎖時**確實沒有執行任何工作** ——
 * 那才是這道鎖真正要保證的事，而它是可測的。
 */
class CronLock
{
    private Database $db;
    private bool $legacyHeld = false;
    private bool $v1Held = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * 嘗試取得鎖。
     *
     * @param int $waitSeconds 願意等多久才放棄。
     *
     * 🔴 【為什麼要能等，而不是一律 0】0 秒的語意是「重疊就略過本次」，
     * 對每 5 分鐘跑一次的工作是對的：略過一次，5 分鐘後就補上，
     * 排隊等只會把延遲往後堆疊，而下一個週期又會再觸發一次。
     *
     * 但對一天只跑一次的工作，「略過本次」＝**今天整天不跑**。
     * 呼叫端要能表達這個差別，否則兩者相撞時就是用擲硬幣決定當天的帳要不要出。
     * 誰該等、等多久由 CronRunner 決定（見 LOCK_WAIT_SECONDS）。
     */
    public function acquire(int $waitSeconds = 0): bool
    {
        $waitSeconds = max(0, $waitSeconds);
        $legacyName = $this->legacyName();
        if (strlen($legacyName) > 64) {
            return false;
        }

        // Upgrade bridge: the deployed predecessor only knows this legacy name.
        // Always acquire it first so an old worker and a new worker cannot both run.
        $this->legacyHeld = (int) $this->db->fetchColumn(
            'SELECT GET_LOCK(:name, :wait)',
            ['name' => $legacyName, 'wait' => $waitSeconds]
        ) === 1;
        if (!$this->legacyHeld) {
            return false;
        }

        try {
            // Once legacy is held, no other compatible worker can race us, so the
            // namespace lock is a zero-wait assertion rather than a second wait.
            $this->v1Held = (int) $this->db->fetchColumn(
                'SELECT GET_LOCK(:name, :wait)',
                ['name' => $this->name(), 'wait' => 0]
            ) === 1;
            if (!$this->v1Held) {
                $this->release();
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->release();
            throw $e;
        }
    }

    public function release(): void
    {
        if ($this->v1Held) {
            try {
                $this->db->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $this->name()]);
            } catch (\Throwable) {
                // 連線已斷時 MySQL 會自動釋放，此處失敗無害。
            } finally {
                $this->v1Held = false;
            }
        }

        if ($this->legacyHeld) {
            try {
                $this->db->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $this->legacyName()]);
            } catch (\Throwable) {
                // Same connection-loss guarantee as the v1 lock.
            } finally {
                $this->legacyHeld = false;
            }
        }
    }

    /** GET_LOCK 是 MySQL server-global；委派 Database 統一納入 database/prefix namespace。 */
    private function name(): string
    {
        return $this->db->namedLockName('cron');
    }

    /** Name used by the predecessor release; retained during the rolling bridge. */
    private function legacyName(): string
    {
        return $this->db->getPrefix() . 'cron';
    }
}
