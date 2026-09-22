<?php

declare(strict_types=1);

namespace YangSheep\CRM\Notification;

use YangSheep\CRM\Core\Database;

/**
 * 寄信佇列資料存取層（對應架構設計 §5.7 email_queue、§7.10）。
 *
 * 核心併發安全（Zero Trust §6 — email_queue claim 用狀態轉移避免併發重送）：
 *   claimBatch() 於交易內以 SELECT ... FOR UPDATE 鎖定一批 queued 列，
 *   立即把狀態轉為 sending、寫入 owner token 並 attempts +1，再回傳這批列。兩個 cron 同時跑時，
 *   FOR UPDATE 確保同一列只會被一個交易認領（另一個會等鎖、看到已非 queued 而跳過），
 *   杜絕同一封信被寄兩次。
 *
 * Crash boundary：sending 只代表「尚未呼叫 SMTP」，逾時可安全回收；呼叫 SMTP 前必須先以
 * owner CAS 轉成 indeterminate。此後若 process 中斷，系統寧可要求人工查核，也不會自動重寄
 * 一封可能已被 SMTP 接受的信。
 *
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class EmailQueueRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 將一封信加入佇列。
     *
     * @return int 新佇列項目 ID
     */
    public function enqueue(string $toEmail, string $subject, string $bodyHtml, ?string $scheduledAt = null): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}email_queue
                (to_email, subject, body_html, status, attempts, scheduled_at, created_at)
             VALUES (:to_email, :subject, :body_html, 'queued', 0, :scheduled_at, NOW())",
            [
                'to_email'     => $toEmail,
                'subject'      => $subject,
                'body_html'    => $bodyHtml,
                'scheduled_at' => $scheduledAt,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 認領一批待寄信件（queued → sending，attempts +1），回傳被認領的列。
     *
     * 於單一交易內完成「鎖定 + 狀態轉移」，避免併發重送。只認領：
     *   - status = 'queued'
     *   - attempts < $maxAttempts（未超過重試上限）
     *   - scheduled_at 為 NULL 或 <= NOW()（到了排程時間）
     *
     * @param int $limit       本批最多認領幾封
     * @param int $maxAttempts 重試上限（attempts >= 此值者不再認領）
     * @return array<int, array<string, mixed>> 被認領的信件列（status 已為 sending）
     */
    public function claimBatch(int $limit = 20, int $maxAttempts = 3): array
    {
        $limit = max(1, $limit);
        $maxAttempts = max(1, $maxAttempts);

        return $this->db->transaction(function () use ($limit, $maxAttempts): array {
            // 交易內以 FOR UPDATE 鎖定一批 queued 列（SQLite 測試會移除 FOR UPDATE，單執行緒安全）。
            $rows = $this->db->fetchAll(
                "SELECT id FROM {prefix}email_queue
                 WHERE status = 'queued'
                   AND attempts < :max
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                 ORDER BY id ASC
                 LIMIT :limit
                 FOR UPDATE",
                ['max' => $maxAttempts, 'limit' => $limit]
            );

            if ($rows === []) {
                return [];
            }

            $claimed = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $token = bin2hex(random_bytes(32));

                // 每列使用獨立 owner token。status + attempts 再次進 CAS，避免任何
                // 非預期競態把已轉移或已達上限的列交給本 worker。
                $affected = $this->db->execute(
                    "UPDATE {prefix}email_queue
                     SET status = 'sending', attempts = attempts + 1,
                         claim_token = :token, claimed_at = NOW(), last_error = NULL
                     WHERE id = :id AND status = 'queued' AND attempts < :max",
                    ['token' => $token, 'id' => $id, 'max' => $maxAttempts]
                );
                if ($affected !== 1) {
                    continue;
                }

                $owned = $this->db->fetch(
                    "SELECT id, to_email, subject, body_html, status, attempts,
                            claim_token, claimed_at, scheduled_at, created_at
                     FROM {prefix}email_queue
                     WHERE id = :id AND status = 'sending' AND claim_token = :token",
                    ['id' => $id, 'token' => $token]
                );
                if ($owned !== null) {
                    $claimed[] = $owned;
                }
            }

            return $claimed;
        });
    }

    /**
     * 回收「已認領但尚未開始 SMTP」的 stale claim。
     *
     * 有 owner token 的新版 sending 可在逾時後安全回收。rolling upgrade 時，舊版
     * worker 可能在 migration 之後留下 tokenless sending；它無法證明是在 SMTP 前
     * 或後中斷，因此每輪都保守隔離為 indeterminate，絕不自動重寄。
     */
    public function recoverStaleClaims(int $staleAfterSeconds = 600, int $maxAttempts = 3): int
    {
        $staleAfterSeconds = max(1, $staleAfterSeconds);
        $maxAttempts = max(1, $maxAttempts);

        return $this->db->transaction(function () use ($staleAfterSeconds, $maxAttempts): int {
            // migration 056 只能清理「執行當刻」的 legacy rows；尚未退場的舊 worker
            // 仍可能在 migration 後寫出 tokenless sending。雙版本 cron lock 保證新版
            // maintenance 不會與該 worker 同時寄送；取得鎖後即可保守 quarantine。
            $quarantined = $this->db->execute(
                "UPDATE {prefix}email_queue
                 SET status = 'indeterminate', claimed_at = COALESCE(claimed_at, NOW()),
                     last_error = :error
                 WHERE status = 'sending' AND claim_token IS NULL",
                ['error' => '舊版寄送程序留下無 owner token 的狀態；請人工查核 SMTP 紀錄。']
            );

            $rows = $this->db->fetchAll(
                "SELECT id, attempts, claim_token
                 FROM {prefix}email_queue
                 WHERE status = 'sending'
                   AND claim_token IS NOT NULL
                   AND claimed_at IS NOT NULL
                   AND claimed_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
                 ORDER BY id ASC
                 FOR UPDATE",
                ['age' => $staleAfterSeconds]
            );

            $recovered = $quarantined;
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $token = (string) $row['claim_token'];
                $nextStatus = (int) $row['attempts'] >= $maxAttempts ? 'failed' : 'queued';
                $affected = $this->db->execute(
                    "UPDATE {prefix}email_queue
                     SET status = :status, claim_token = NULL, claimed_at = NULL,
                         last_error = :error
                     WHERE id = :id AND status = 'sending' AND claim_token = :token",
                    [
                        'status' => $nextStatus,
                        'error' => '寄送程序在 SMTP 開始前中斷；已安全回收。',
                        'id' => $id,
                        'token' => $token,
                    ]
                );
                $recovered += $affected === 1 ? 1 : 0;
            }

            return $recovered;
        });
    }

    /**
     * 在任何 SMTP 外部作用前留下 durable boundary（sending → indeterminate）。
     */
    public function markDeliveryStarted(int $id, string $claimToken): bool
    {
        if ($claimToken === '') {
            return false;
        }

        return $this->db->execute(
            "UPDATE {prefix}email_queue
             SET status = 'indeterminate', claimed_at = NOW()
             WHERE id = :id AND status = 'sending' AND claim_token = :token",
            ['id' => $id, 'token' => $claimToken]
        ) === 1;
    }

    /**
     * SMTP 明確接受後，由同一 owner finalize（indeterminate → sent）。
     */
    public function markSent(int $id, string $claimToken): bool
    {
        if ($claimToken === '') {
            return false;
        }

        return $this->db->execute(
            "UPDATE {prefix}email_queue
             SET status = 'sent', sent_at = NOW(), last_error = NULL,
                 claim_token = NULL, claimed_at = NULL
             WHERE id = :id AND status = 'indeterminate' AND claim_token = :token",
            ['id' => $id, 'token' => $claimToken]
        ) === 1;
    }

    /**
     * 標記寄送失敗。
     *
     * attempts 已於 claimBatch 認領時 +1。若已達上限 → 留 failed（不再認領重試）；
     * 否則轉回 queued 等待下一輪 cron 重試。
     *
     * @param int    $id
     * @param string $error       失敗原因
     * @param int    $maxAttempts 重試上限
     */
    public function markFailed(int $id, string $claimToken, string $error, int $maxAttempts = 3): bool
    {
        if ($claimToken === '') {
            return false;
        }
        $maxAttempts = max(1, $maxAttempts);

        return $this->db->transaction(function () use ($id, $claimToken, $error, $maxAttempts): bool {
            $row = $this->db->fetch(
                "SELECT attempts FROM {prefix}email_queue
                 WHERE id = :id AND status = 'indeterminate' AND claim_token = :token
                 FOR UPDATE",
                ['id' => $id, 'token' => $claimToken]
            );
            if ($row === null) {
                return false;
            }

            $nextStatus = (int) $row['attempts'] >= $maxAttempts ? 'failed' : 'queued';
            return $this->db->execute(
                "UPDATE {prefix}email_queue
                 SET status = :status, last_error = :err,
                     claim_token = NULL, claimed_at = NULL
                 WHERE id = :id AND status = 'indeterminate' AND claim_token = :token",
                [
                    'status' => $nextStatus,
                    'err' => mb_substr($error, 0, 1000),
                    'id' => $id,
                    'token' => $claimToken,
                ]
            ) === 1;
        });
    }

    /**
     * 保存無法判定的外部結果；不釋放 token，也不提供自動重試。
     */
    public function markIndeterminate(int $id, string $claimToken, string $error): bool
    {
        if ($claimToken === '') {
            return false;
        }

        return $this->db->execute(
            "UPDATE {prefix}email_queue
             SET last_error = :err
             WHERE id = :id AND status = 'indeterminate' AND claim_token = :token",
            [
                'err' => mb_substr($error, 0, 1000),
                'id' => $id,
                'token' => $claimToken,
            ]
        ) === 1;
    }

    /**
     * 重新排入佇列（後台「重送」失敗信用）：把 failed 的信重置為 queued、attempts 歸零。
     * 僅 failed 可重送（避免把 sending/sent 誤重置）。
     *
     * @return bool 是否確實重置（非 failed 則 false）
     */
    public function requeue(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE {prefix}email_queue
             SET status = 'queued', attempts = 0, last_error = NULL, scheduled_at = NOW(),
                 claim_token = NULL, claimed_at = NULL
             WHERE id = :id AND status = 'failed'",
            ['id' => $id]
        );
        return $affected > 0;
    }

    /**
     * 依 ID 取得單筆。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}email_queue WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 分頁查詢（後台「通知記錄」之寄信佇列分頁）。
     *
     * @param array{status?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['queued', 'sending', 'indeterminate', 'sent', 'failed'], true)) {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}email_queue {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;
        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT id, to_email, subject, status, attempts, last_error, scheduled_at, sent_at, created_at
             FROM {prefix}email_queue
             {$where}
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return ['items' => $items, 'total' => $total];
    }
}
