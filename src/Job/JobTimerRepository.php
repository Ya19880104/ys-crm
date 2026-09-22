<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Database;

/**
 * 工作計時器分段資料存取層（job_timers）。
 * 提供分段查詢、新增 running 段、停止段、累計工時、running 鎖定查詢。
 * 「同一 job 至多一個 running」由 JobService 在交易內以 findRunningForUpdate() 鎖定後保證。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.3 job_timers、§7.6「計時器：開始 / 停止 / 繼續」。
 */
class JobTimerRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得指定工作的所有計時段（新→舊），含操作者名。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByJobId(int $jobId): array
    {
        return $this->db->fetchAll(
            "SELECT t.id, t.job_id, t.admin_user_id, t.started_at, t.stopped_at,
                    t.duration_seconds, t.status, t.note, t.created_at,
                    u.display_name AS operator_name
             FROM {prefix}job_timers t
             LEFT JOIN {prefix}users u ON t.admin_user_id = u.id
             WHERE t.job_id = :job_id
             ORDER BY t.started_at DESC, t.id DESC",
            ['job_id' => $jobId]
        );
    }

    /**
     * 取得指定工作目前的 running 段（不鎖定；供顯示）。
     */
    public function findRunning(int $jobId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}job_timers
             WHERE job_id = :job_id AND status = 'running'
             ORDER BY id DESC LIMIT 1",
            ['job_id' => $jobId]
        );
    }

    /**
     * 在交易內鎖定並取得 running 段（SELECT ... FOR UPDATE）。
     * 必須於 Database::beginTransaction() 之後呼叫，以保證
     * 「檢查是否已有 running → 再決定 INSERT / UPDATE」的原子性，
     * 避免並行請求對同一 job 產生兩個 running 段。
     */
    public function findRunningForUpdate(int $jobId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}job_timers
             WHERE job_id = :job_id AND status = 'running'
             ORDER BY id DESC LIMIT 1
             FOR UPDATE",
            ['job_id' => $jobId]
        );
    }

    /**
     * 新增一筆 running 計時段（開始 / 繼續）。started_at = NOW()。
     *
     * @return int 新計時段 ID
     */
    public function startSegment(int $jobId, ?int $adminUserId, ?string $note = null): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}job_timers
                (job_id, admin_user_id, started_at, stopped_at, duration_seconds, status, note, created_at)
             VALUES
                (:job_id, :admin_user_id, NOW(), NULL, 0, 'running', :note, NOW())",
            [
                'job_id'        => $jobId,
                'admin_user_id' => $adminUserId,
                'note'          => ($note ?? '') !== '' ? $note : null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 停止指定的 running 計時段：
     * stopped_at = NOW()，duration_seconds = NOW() 與 started_at 之差（伺服器端計算，
     * 不接受前端傳入時間，避免竄改工時）。僅當該段仍為 running 時才更新（防重複停止）。
     *
     * @return int 影響筆數（1=成功停止，0=該段非 running）
     */
    public function stopSegment(int $timerId): int
    {
        return $this->db->execute(
            "UPDATE {prefix}job_timers
             SET stopped_at = NOW(),
                 duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW())),
                 status = 'stopped'
             WHERE id = :id AND status = 'running'",
            ['id' => $timerId]
        );
    }

    /**
     * 累計工時（秒）：所有 stopped 段的 duration 加總
     * + 任何 running 段「至今」的即時秒數（讓詳情頁顯示包含進行中時間）。
     */
    public function totalSeconds(int $jobId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(
                        CASE WHEN status = 'running'
                             THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                             ELSE duration_seconds END
                    ), 0)
             FROM {prefix}job_timers WHERE job_id = :job_id",
            ['job_id' => $jobId]
        );
    }

    /**
     * 指定工作是否有 running 段。
     */
    public function hasRunning(int $jobId): bool
    {
        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}job_timers WHERE job_id = :job_id AND status = 'running'",
            ['job_id' => $jobId]
        );
        return $count > 0;
    }
}
