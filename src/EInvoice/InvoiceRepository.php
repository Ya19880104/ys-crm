<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\Database;

/**
 * 電子發票紀錄存取層。
 *
 * 包含防重複開立所需的兩個關鍵機制：
 *   1) claimForIssue()：條件式 UPDATE（CAS），只有 pending/failed/scheduled 能被搶到；
 *      abandoned（系統已放棄）預設搶不到，唯有人工介入可明確放行。
 *   2) acquireLock() / releaseLock()：MySQL GET_LOCK，跨 process 互斥。
 */
class InvoiceRepository
{
    /**
     * 自動重試的次數上限。
     *
     * 【為什麼一定要有上限】開立失敗分兩種：暫時性的（對方系統忙、網路斷）與
     * **永久性的**（統編不存在、載具號碼格式錯、字軌用罄、憑證失效）。
     * 退避策略最長只到 6 小時 —— 對永久性失敗而言，那不是「等一下再試」，
     * 而是「每天固定重送四次，直到有人發現為止」。代價有三：
     *   1. 白白吃掉出網限流額度，排擠真正該送的發票
     *   2. 每次失敗都寫一筆 API log，把有用的紀錄淹掉
     *   3. **最糟的是沒有人會發現** —— 系統看起來一直在努力，實際上永遠不會成功
     *
     * 達到上限後轉為「failed 且 next_retry_at 為 NULL」= 終局狀態，等人工處理。
     * 這個狀態在後台會被標成「需人工處理」，而不是混在一般失敗裡。
     */
    public const MAX_RETRIES = 5;

    /**
     * 「系統已放棄自動處理，等人工介入」的狀態。
     *
     * 🔴 它必須是一個狀態，不能只是「failed 且沒排重試」這種靠查詢條件表達的概念：
     * claimForIssue() 的 CAS 是以 status 為條件的，一個不在 status 裡的概念
     * 守不住那道閘門 —— 金流商重送付款通知就能把已放棄的發票重新送出。
     */
    public const STATUS_ABANDONED = 'abandoned';

    private Database $db;

    /** @var array<int, array{legacy: string, v1: string}> Locks held by payment id. */
    private array $heldPaymentLocks = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ───────────────────────── 查詢 ─────────────────────────

    public function findById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM {prefix}invoices WHERE id = :id", ['id' => $id]);
    }

    /**
     * 取得某筆付款「目前有效」的發票列（最新一筆，不含已作廢）。
     */
    public function findActiveByPayment(int $paymentId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}invoices
             WHERE payment_id = :pid AND status <> 'cancelled'
             ORDER BY id DESC LIMIT 1",
            ['pid' => $paymentId]
        );
    }

    public function findLatestByPayment(int $paymentId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}invoices WHERE payment_id = :pid ORDER BY id DESC LIMIT 1",
            ['pid' => $paymentId]
        );
    }

    /**
     * Paid payments whose durable auto-issue intent committed but either no
     * invoice row was created or the process died after creating a lone pending
     * row and before claiming it. Cancelled history does not block an existing
     * pending attempt, but cancelled history alone never authorizes a new one.
     * Other progressed rows have their own retry/reconciliation lifecycle.
     *
     * @return list<int>
     */
    public function getMissingIntentPaymentIds(int $limit = 20, ?int $paymentId = null): array
    {
        $paymentFilter = $paymentId === null ? '' : 'AND p.id = :payment_id';
        $params = ['limit' => max(1, min(100, $limit))];
        if ($paymentId !== null) {
            $params['payment_id'] = $paymentId;
        }
        $rows = $this->db->fetchAll(
            "SELECT p.id
             FROM {prefix}payments p
             WHERE p.status = 'paid'
               AND p.invoice_requested_at IS NOT NULL
               {$paymentFilter}
               AND (
                   NOT EXISTS (
                       SELECT 1 FROM {prefix}invoices i WHERE i.payment_id = p.id
                   )
                   OR (
                       EXISTS (
                           SELECT 1 FROM {prefix}invoices pending_i
                           WHERE pending_i.payment_id = p.id
                             AND pending_i.status = 'pending'
                             AND (pending_i.claim_token IS NULL OR pending_i.claim_token = '')
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM {prefix}invoices progressed_i
                           WHERE progressed_i.payment_id = p.id
                             AND progressed_i.status NOT IN ('pending', 'cancelled')
                       )
                   )
               )
             ORDER BY p.invoice_requested_at ASC, p.id ASC
             LIMIT :limit",
            $params
        );

        return array_values(array_map(
            static fn(array $row): int => (int) $row['id'],
            $rows
        ));
    }

    /**
     * 取得某筆付款所有「已作廢」的發票號碼。
     *
     * 用途有二，缺一不可：
     *   1) 判斷本次是否為「作廢後重開」→ 決定送 PayNow 的 order_no 要不要加 -R{id} 尾碼。
     *   2) 開立前 reconcile 查詢時排除這些號碼 → 避免把剛作廢的發票誤認成有效發票而沿用。
     *
     * @return array<int,string>
     */
    public function getCancelledNumbersByPayment(int $paymentId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT invoice_number FROM {prefix}invoices
             WHERE payment_id = :pid AND status = 'cancelled' AND invoice_number IS NOT NULL AND invoice_number <> ''",
            ['pid' => $paymentId]
        );

        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) $row['invoice_number']),
            $rows
        )));
    }

    /**
     * @param array{status?: string, keyword?: string, customer_id?: int} $filters
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['status'])) {
            $conditions[]       = 'i.status = :status';
            $params['status']   = (string) $filters['status'];
        }
        if (!empty($filters['customer_id'])) {
            $conditions[]          = 'i.customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['keyword'])) {
            $conditions[]      = '(i.invoice_number LIKE :kw OR i.order_no LIKE :kw OR c.display_name LIKE :kw)';
            $params['kw']      = '%' . $filters['keyword'] . '%';
        }

        // 依「建立時間」而非「開立時間」篩選：未開立與開立失敗的發票沒有 issued_at，
        // 若用開立時間當條件，那些最需要被找到的發票會整批消失。
        $conditions = array_merge($conditions, self::dateRangeConditions('i.created_at', $filters, $params));

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}invoices i
             LEFT JOIN {prefix}customers c ON i.customer_id = c.id
             {$where}",
            $params
        );

        $listParams           = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT i.*, c.display_name AS customer_name, p.payment_no
             FROM {prefix}invoices i
             LEFT JOIN {prefix}customers c ON i.customer_id = c.id
             LEFT JOIN {prefix}payments  p ON i.payment_id  = p.id
             {$where}
             ORDER BY i.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return ['items' => $items, 'total' => $total];
    }

/**
     * 把使用者輸入的日期區間轉成 SQL 條件。
     *
     * 🔴 【結束日必須含當天整天】欄位是 DATETIME，而使用者輸入的是日期。
     * 若直接寫 `created_at <= '2026-09-02'`，比較時會補成 `2026-09-02 00:00:00`，
     * 於是**選了 9/2 卻看不到 9/2 建立的任何一筆** —— 而畫面上看起來完全合理，
     * 只會讓人以為「那天沒有資料」。因此結束日一律補到 23:59:59。
     *
     * 格式不合的輸入直接忽略（而不是嘗試修正）：日期篩選錯了會讓人以為
     * 某段期間沒有發票，那比「篩選沒生效」更危險。
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $params  依參考傳入，會被加上繫結值
     * @return list<string> 要 AND 起來的條件
     */
    private static function dateRangeConditions(string $column, array $filters, array &$params): array
    {
        $conditions = [];
        $valid      = static fn(string $v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
            && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));

        $from = trim((string) ($filters['date_from'] ?? ''));
        if ($from !== '' && $valid($from)) {
            $conditions[]        = "{$column} >= :date_from";
            $params['date_from'] = $from . ' 00:00:00';
        }

        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($to !== '' && $valid($to)) {
            $conditions[]      = "{$column} <= :date_to";
            $params['date_to'] = $to . ' 23:59:59';
        }

        return $conditions;
    }

    /**
     * 「需人工處理」的發票：已失敗，而且系統不會再自己重試。
     *
     * 【定義為何不是「重試次數用完」】那只是其中一種。另一種是
     * `markFailed(..., scheduleRetry: false)` —— 對方明確回了終局拒絕（例如統編不存在），
     * 這種一開始就不排重試，次數可能還是 1。
     *
     * 兩者對使用者而言是同一件事：**這張發票不會再自己動了，等人**。
     * 用「有沒有排下次重試」當判準，才不會漏掉第二種。
     * （若改用 retry_count >= MAX 當條件，終局拒絕的那批會完全看不到 ——
     * 而那批恰恰是最需要人看的。）
     *
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function findNeedsAttention(int $page = 1, int $perPage = 20): array
    {
        $where = "WHERE i.status = '" . self::STATUS_ABANDONED . "'";

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}invoices i {$where}"
        );

        $items = $this->db->fetchAll(
            "SELECT i.*, c.display_name AS customer_name, p.payment_no
             FROM {prefix}invoices i
             LEFT JOIN {prefix}customers c ON i.customer_id = c.id
             LEFT JOIN {prefix}payments  p ON i.payment_id  = p.id
             {$where}
             ORDER BY i.updated_at DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );

        return ['items' => $items, 'total' => $total];
    }

    /** 需人工處理的張數（列表頁的紅點）。 */
    public function needsAttentionCount(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}invoices WHERE status = '" . self::STATUS_ABANDONED . "'"
        );
    }

    /**
     * 各狀態張數（列表頁的篩選標籤）。
     *
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS n FROM {prefix}invoices GROUP BY status"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * 取得到期可重試的失敗發票 ID（cron 用）。
     *
     * @return array<int,int>
     */
    public function getDueRetryIds(int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM {prefix}invoices
             WHERE status = 'failed'
               AND next_retry_at IS NOT NULL
               AND next_retry_at <= NOW()
               AND retry_count < :max
             ORDER BY next_retry_at ASC
             LIMIT :limit",
            ['max' => self::MAX_RETRIES, 'limit' => max(1, $limit)]
        );

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * 取得卡在 issuing 過久的殭屍 claim（cron 回收用），連同掃描當下的 epoch。
     *
     * 成因：worker 在 claim 之後、寫回結果之前被中斷（部署重啟、fatal、機器掛掉）。
     * 這些列若不回收會永遠停在 issuing，既不會重試也不會被人注意到。
     *
     * id 本身不足以安全回收：掃描後到 UPDATE 前，另一個 worker 可能已取得 fresh claim。
     * caller 必須把這裡讀到的 token + claimed_at 原樣交給 releaseStaleClaim() 做 CAS。
     *
     * @return list<array{id: int, claim_token: string, claimed_at: string}>
     */
    public function getStaleIssuingClaims(int $ageSeconds = 1800, int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, claim_token, claimed_at FROM {prefix}invoices
             WHERE status = 'issuing'
               AND claim_token IS NOT NULL
               AND claim_token <> ''
               AND claimed_at IS NOT NULL
               AND claimed_at < DATE_SUB(NOW(), INTERVAL :age SECOND)
             ORDER BY claimed_at ASC
             LIMIT :limit",
            ['age' => max(60, $ageSeconds), 'limit' => max(1, $limit)]
        );

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'claim_token' => (string) $r['claim_token'],
            'claimed_at' => (string) $r['claimed_at'],
        ], $rows);
    }

    /** @return array<int,int> Backward-compatible display/test projection. */
    public function getStaleIssuingIds(int $ageSeconds = 1800, int $limit = 20): array
    {
        return array_map(
            static fn(array $claim): int => $claim['id'],
            $this->getStaleIssuingClaims($ageSeconds, $limit)
        );
    }

    // ───────────────────────── 寫入 ─────────────────────────

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}invoices
                (payment_id, quote_id, customer_id, status, issue_type, order_no,
                 total_amount, tax_amount, scheduled_issue_at)
             VALUES
                (:payment_id, :quote_id, :customer_id, :status, :issue_type, :order_no,
                 :total_amount, :tax_amount, :scheduled_issue_at)",
            [
                'payment_id'         => $data['payment_id'] ?? null,
                'quote_id'           => $data['quote_id'] ?? null,
                'customer_id'        => $data['customer_id'] ?? null,
                'status'             => (string) ($data['status'] ?? 'pending'),
                'issue_type'         => (string) ($data['issue_type'] ?? 'auto'),
                'order_no'           => (string) ($data['order_no'] ?? ''),
                'total_amount'       => (float) ($data['total_amount'] ?? 0),
                'tax_amount'         => (float) ($data['tax_amount'] ?? 0),
                'scheduled_issue_at' => $data['scheduled_issue_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 白名單式更新。
     *
     * 【務必同步維護】新增欄位後若忘了加進白名單，該欄位會永遠寫不進去而且完全靜默
     * ——這正是移植來源專案踩過的坑（is_recurring 漏白名單導致週期標記永不持久化）。
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'status', 'issue_type', 'order_no', 'invoice_number', 'invoice_date', 'random_code',
            'total_amount', 'tax_amount', 'retry_count', 'next_retry_at', 'scheduled_issue_at',
            'claim_token', 'claimed_at', 'issued_at', 'cancelled_at',
            'payload_snapshot', 'provider_response', 'last_error',
            'pdf_url', 'pdf_url_expires_at',
        ];

        $sets   = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[]        = "`{$key}` = :{$key}";
            $params[$key]  = $value;
        }

        if ($sets === []) {
            return false;
        }

        $changed = $this->db->execute(
            "UPDATE {prefix}invoices SET " . implode(', ', $sets) . " WHERE id = :id",
            $params
        );
        // MySQL reports changed rows: an unchanged existing row is success,
        // while a removed/nonexistent target must not look like a cache write.
        return $changed > 0 || $this->findById($id) !== null;
    }

    /**
     * 以 CAS 佔用這張發票，準備送出。
     *
     * 只有 pending / failed / scheduled 能被搶到；issuing（別人正在處理）與
     * issued / cancelled（終態）一律搶不到。
     *
     * 【why rowCount 可靠】MySQL 的 UPDATE rowCount 預設回報「實際變更的列數」而非
     * 「符合條件的列數」。本語句每次都寫入全新的 claim_token 與 claimed_at，值必定改變，
     * 因此「符合條件」⇔「rowCount === 1」。**若日後移除 claim_token 的隨機性，
     * 這個 CAS 就會失效**（同值 UPDATE 會回 0 列，被誤判成搶不到）。
     */
    /**
     * 以 CAS 取得開立權（同一列不會被兩個 worker 同時送出）。
     *
     * 🔴 【abandoned 預設取不到】那是「系統已放棄」的終態。自動路徑
     *（付款成功、金流商重送通知、cron 重試）一律不得把它撿回來 ——
     * 金流商重送是常態行為，若 abandoned 仍可被 claim，重試上限等於不存在。
     *
     * 人工介入是唯一的例外：後台「重試開立」按鈕就是為了這個存在，
     * 所以由呼叫端明確傳 $allowAbandoned = true，而不是預設放行。
     */
    public function claimForIssue(int $id, string $token, bool $allowAbandoned = false): bool
    {
        $statuses = $allowAbandoned
            ? "'pending', 'failed', 'scheduled', '" . self::STATUS_ABANDONED . "'"
            : "'pending', 'failed', 'scheduled'";

        $affected = $this->db->execute(
            "UPDATE {prefix}invoices
             SET status = 'issuing', claim_token = :token, claimed_at = NOW()
             WHERE id = :id AND status IN ({$statuses})",
            ['id' => $id, 'token' => $token]
        );

        return $affected === 1;
    }

    /**
     * Provider 已確認開立後，以 claim owner CAS 完成本地落盤。
     *
     * 外部開立無法 rollback；因此這裡不能沿用只綁 id 且把零列視為成功的 update()。
     * claim epoch 或 status 只要在 provider 往返期間改變，舊 worker 就必須得到 false，
     * 由 service 回報 indeterminate 並在下次嘗試先 reconcile，不能覆寫較新的狀態。
     */
    public function markIssued(
        int $id,
        string $claimToken,
        string $invoiceNumber,
        string $invoiceDate,
        string $randomCode,
        string $response
    ): bool {
        $affected = $this->db->execute(
            "UPDATE {prefix}invoices
                SET status = 'issued',
                    invoice_number = :invoice_number,
                    invoice_date = :invoice_date,
                    random_code = :random_code,
                    issued_at = NOW(),
                    provider_response = :provider_response,
                    last_error = NULL,
                    claim_token = NULL,
                    claimed_at = NULL,
                    next_retry_at = NULL,
                    updated_at = NOW()
              WHERE id = :id
                AND status = 'issuing'
                AND claim_token = :claim_token",
            [
                'id' => $id,
                'claim_token' => $claimToken,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'random_code' => $randomCode,
                'provider_response' => $response,
            ]
        );

        return $affected === 1;
    }

    /**
     * 標記開立失敗並排定退避重試。
     *
     * 退避策略：5 分 → 15 分 → 45 分 → 2 小時 → 6 小時（上限），避免對方系統異常時猛敲。
     */
    public function markFailed(
        int $id,
        string $claimToken,
        string $error,
        string $response = '',
        bool $scheduleRetry = true
    ): bool {
        $current = $this->findById($id);
        if ($current === null
            || (string) ($current['status'] ?? '') !== 'issuing'
            || $claimToken === ''
            || !hash_equals((string) ($current['claim_token'] ?? ''), $claimToken)) {
            return false;
        }

        $retries = (int) ($current['retry_count'] ?? 0) + 1;

        $backoffMinutes = [5, 15, 45, 120, 360];
        $delay          = $backoffMinutes[min($retries - 1, count($backoffMinutes) - 1)];

        // 🔴 到達上限（或呼叫端明確表示不重試）就轉為 abandoned —— 一個真正的狀態，
        // 而不是只存在於某些查詢 WHERE 裡的隱含概念。這才擋得住 claimForIssue()：
        // 金流商重送付款通知時，abandoned 不在它的 CAS 條件內，不會被重新撿起來。
        $willRetry = $scheduleRetry && $retries < self::MAX_RETRIES;

        // 🔴 【next_retry_at 用 DB 的時鐘算，不用 PHP 的】
        // 比較端是 getDueRetryIds() 的 `next_retry_at <= NOW()` —— 那是 DB 時鐘。
        // 用 PHP 的 date() 產生時刻，兩邊時區不同就整整差那麼多小時：實測（在
        // Database::syncTimeZone() 之前）排定 22:26 重試的發票，DB 的 NOW() 是 14:22，
        // 要等 8 小時才撈得到，而且沒有任何錯誤訊息。
        //
        // 連線層已經把時區對齊，但那是環境設定，可能因託管限制而設不起來；
        // 「時刻由哪個時鐘產生」則是程式自己決定的事。兩邊用同一個時鐘，
        // 就不必依賴環境設定成功。
        $nextRetryExpr = $willRetry ? "DATE_ADD(NOW(), INTERVAL {$delay} MINUTE)" : 'NULL';

        return $this->db->execute(
            "UPDATE {prefix}invoices
                SET status = :status,
                    retry_count = :retries,
                    next_retry_at = {$nextRetryExpr},
                    last_error = :err,
                    provider_response = :resp,
                    claim_token = NULL,
                    claimed_at = NULL,
                    updated_at = NOW()
              WHERE id = :id
                AND status = 'issuing'
                AND claim_token = :claim_token",
            [
                'status'  => $willRetry ? 'failed' : self::STATUS_ABANDONED,
                'retries' => $retries,
                'err'     => mb_substr($error, 0, 1000),
                'resp'    => $response,
                'id'      => $id,
                'claim_token' => $claimToken,
            ]
        ) === 1;
    }

    /**
     * 尚未配置 provider 是可恢復的前置條件，不是一次 provider attempt。
     *
     * 保留既有 retry_count，只排下一次設定檢查；否則每五分鐘 maintenance 會在
     * 幾小時內把「尚未填 Token」誤耗成 abandoned，之後補 Token 也撿不回來。
     * caller 必須先持有 payment named lock，避免 find/create race。
     */
    public function markConfigurationBlocked(int $id, string $reason, int $retryAfterMinutes = 5): void
    {
        $delay = max(1, min(1440, $retryAfterMinutes));
        $this->db->execute(
            "UPDATE {prefix}invoices
                SET status = 'failed',
                    next_retry_at = DATE_ADD(NOW(), INTERVAL {$delay} MINUTE),
                    last_error = :err,
                    claimed_at = NULL,
                    updated_at = NOW()
              WHERE id = :id
                AND status IN ('pending', 'failed')
                AND claim_token IS NULL",
            ['id' => $id, 'err' => mb_substr($reason, 0, 1000)]
        );
    }

    /**
     * Reserve the irreversible cancellation call while preserving the issued state.
     * The durable token blocks a second request even if the worker dies after PayNow
     * accepted the cancellation but before the local finalize.
     */
    public function claimForCancellation(int $id, string $invoiceNumber, string $claimToken): bool
    {
        if ($invoiceNumber === '' || $claimToken === '') {
            return false;
        }

        return $this->db->execute(
            "UPDATE {prefix}invoices
                SET claim_token = :claim_token,
                    claimed_at = NOW(),
                    last_error = :message,
                    updated_at = NOW()
              WHERE id = :id
                AND status = 'issued'
                AND invoice_number = :invoice_number
                AND claim_token IS NULL",
            [
                'id' => $id,
                'invoice_number' => $invoiceNumber,
                'claim_token' => $claimToken,
                'message' => '發票作廢結果待確認。',
            ]
        ) === 1;
    }

    /** Finalize only the owner and exact issued invoice that initiated cancellation. */
    public function finalizeCancellation(
        int $id,
        string $invoiceNumber,
        string $claimToken,
        string $response = ''
    ): bool {
        return $this->db->execute(
            "UPDATE {prefix}invoices
                SET status = 'cancelled',
                    cancelled_at = NOW(),
                    provider_response = :provider_response,
                    last_error = NULL,
                    claim_token = NULL,
                    claimed_at = NULL,
                    next_retry_at = NULL,
                    updated_at = NOW()
              WHERE id = :id
                AND status = 'issued'
                AND invoice_number = :invoice_number
                AND claim_token = :claim_token",
            [
                'id' => $id,
                'invoice_number' => $invoiceNumber,
                'claim_token' => $claimToken,
                'provider_response' => $response,
            ]
        ) === 1;
    }

    /** Release a claim only after the provider has definitively rejected the call. */
    public function releaseCancellationClaim(
        int $id,
        string $invoiceNumber,
        string $claimToken,
        string $error
    ): bool {
        return $this->db->execute(
            "UPDATE {prefix}invoices
                SET claim_token = NULL,
                    claimed_at = NULL,
                    last_error = :error,
                    updated_at = NOW()
              WHERE id = :id
                AND status = 'issued'
                AND invoice_number = :invoice_number
                AND claim_token = :claim_token",
            [
                'id' => $id,
                'invoice_number' => $invoiceNumber,
                'claim_token' => $claimToken,
                'error' => mb_substr($error, 0, 1000),
            ]
        ) === 1;
    }

    /**
     * 釋放殭屍 claim：issuing → failed，交回重試流程。
     *
     * 🔴 【這裡一定要累加 retry_count】原本的實作刻意「保留 retry_count」，
     * 看起來很合理 —— 這次根本沒送出去，不該算一次失敗。但那會造成**無限循環**：
     * 若失敗原因就是「每次一送出就讓 worker 掛掉」（例如某筆資料觸發 fatal），
     * 流程會是 claim → 掛掉 → 回收（次數不變）→ 再 claim → 再掛掉…
     * 重試上限永遠不會到達，因為計數器永遠不動。
     *
     * 判準：上限要防的是「無止境地重試」，而不是「精確統計失敗原因」。
     * 任何一種「取用了卻沒有結論」的循環都必須算進去，否則上限就形同虛設。
     *
     * 【為何在 PHP 算而不寫進 SQL】把 `retry_count = retry_count + 1` 與
     * `next_retry_at = CASE WHEN retry_count + 1 >= :max ...` 寫在同一個 UPDATE 裡，
     * 在 MySQL 與 SQLite 會得到**不同結果**：MySQL 的賦值由左至右，後面的運算式
     * 讀到的是已更新的值；SQLite 則一律讀舊值。也就是同一段 SQL 在測試環境
     * （SQLite）與正式環境（MySQL）行為不一致 —— 這種差異只會在正式機現形。
     * 先讀後算最笨，但沒有方言差異。
     */
    public function releaseStaleClaim(int $id, string $claimToken, string $claimedAt): bool
    {
        $current = $this->findById($id);
        if ($current === null
            || (string) ($current['status'] ?? '') !== 'issuing'
            || $claimToken === ''
            || $claimedAt === ''
            || !hash_equals((string) ($current['claim_token'] ?? ''), $claimToken)
            || (string) ($current['claimed_at'] ?? '') !== $claimedAt) {
            return false;
        }

        $retries   = (int) ($current['retry_count'] ?? 0) + 1;
        $willRetry = $retries < self::MAX_RETRIES;

        // 🔴 【next_retry_at 必須用 DB 的 NOW()，不能用 PHP 的 date()】
        // 比較端是 getDueRetryIds() 的 `next_retry_at <= NOW()`（DB 時鐘）。
        // PHP 端固定 Asia/Taipei，而 Database 沒有任何 SET time_zone，
        // MySQL 用容器預設（常見 UTC）—— 時區差 8 小時時，被釋放的殭屍發票
        // 要等 8 小時才會被撿到，而 Kernel::invoice() 的順序保證
        //（先釋放才會被接下來的重試掃描撿到）就此失效，畫面上也看不出異常。
        //
        // 用 SQL 端的常值而非參數，是為了避開 MySQL 與 SQLite 對「同一個 UPDATE 裡
        // 後面的運算式讀到的是新值還是舊值」的差異 —— 這裡沒有欄位互相參照，
        // 所以直接內嵌常值最安全。
        $nextRetryExpr = $willRetry ? 'NOW()' : 'NULL';

        // CAS：status、token、claimed_at 必須仍與 stale scan 的 epoch 完全相同。
        return $this->db->execute(
            "UPDATE {prefix}invoices
             SET status = :next_status,
                  claim_token = NULL,
                  claimed_at = NULL,
                  last_error = :err,
                  retry_count = :retries,
                  next_retry_at = {$nextRetryExpr},
                  updated_at = NOW()
              WHERE id = :id
                AND status = 'issuing'
                AND claim_token = :claim_token
                AND claimed_at = :claimed_at",
            [
                'id'           => $id,
                'claim_token'  => $claimToken,
                'claimed_at'   => $claimedAt,
                'next_status'  => $willRetry ? 'failed' : self::STATUS_ABANDONED,
                'retries' => $retries,
                'err'     => $willRetry
                    ? '開立程序逾時未回報結果，已釋放並排入重試。'
                    : '開立程序逾時未回報結果，且已達重試上限，停止自動重試。',
            ]
        ) === 1;
    }

    /**
     * 這筆發票是否已放棄自動重試（需要人工介入）。
     *
     * @param array<string,mixed> $invoice
     */
    public static function isExhausted(array $invoice): bool
    {
        return (string) ($invoice['status'] ?? '') === self::STATUS_ABANDONED;
    }

    /** 是否因為「重試次數用完」而放棄（相對於對方明確的終局拒絕）—— 只影響顯示的說明文字。 */
    public static function isRetryExhausted(array $invoice): bool
    {
        return self::isExhausted($invoice)
            && (int) ($invoice['retry_count'] ?? 0) >= self::MAX_RETRIES;
    }

    // ───────────────────── 跨 process 鎖 ─────────────────────

    /**
     * 取得 MySQL 具名鎖（跨 process/機器互斥）。
     *
     * 為何需要它：CAS 只能保證「同一列不被兩個 worker 同時 claim」，但無法阻止
     * 兩個 worker 對「同一筆付款」各自建立一張新發票列（兩張都是 pending，各自 claim 成功）。
     * 用付款 ID 當鎖鍵，才能把「建列 + claim + 送出」整段圈成臨界區。
     */
    public function acquireLock(int $paymentId, int $timeoutSeconds = 5): bool
    {
        $legacyName = $this->legacyLockName($paymentId);
        if (strlen($legacyName) > 64) {
            return false;
        }

        $legacyHeld = (int) $this->db->fetchColumn(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => $legacyName, 'timeout' => max(0, $timeoutSeconds)]
        ) === 1;
        if (!$legacyHeld) {
            return false;
        }

        $v1Name = $this->lockName($paymentId);
        try {
            $v1Held = (int) $this->db->fetchColumn(
                'SELECT GET_LOCK(:name, :timeout)',
                ['name' => $v1Name, 'timeout' => 0]
            ) === 1;
            if (!$v1Held) {
                $this->releaseNamedLock($legacyName);
                return false;
            }

            $this->heldPaymentLocks[$paymentId] = ['legacy' => $legacyName, 'v1' => $v1Name];
            return true;
        } catch (\Throwable $e) {
            $this->releaseNamedLock($legacyName);
            throw $e;
        }
    }

    public function releaseLock(int $paymentId): void
    {
        $held = $this->heldPaymentLocks[$paymentId] ?? null;
        if ($held === null) {
            return;
        }

        // Reverse acquisition order.
        $this->releaseNamedLock($held['v1']);
        $this->releaseNamedLock($held['legacy']);
        unset($this->heldPaymentLocks[$paymentId]);
    }

    private function lockName(int $paymentId): string
    {
        return $this->db->namedLockName('invoice-payment:' . $paymentId);
    }

    private function legacyLockName(int $paymentId): string
    {
        return $this->db->getPrefix() . 'invoice_payment_' . $paymentId;
    }

    private function releaseNamedLock(string $name): void
    {
        try {
            $this->db->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
        } catch (\Throwable) {
            // 連線已斷時 MySQL 會自動釋放鎖，此處失敗無害。
        }
    }
}
