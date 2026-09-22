<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Core\Database;

/**
 * 付款紀錄資料存取層（對應架構設計 §5.6 payments、§7.9）。
 *
 * 提供：冪等 insert（先查 idempotency_key）、各種 find、update、含篩選的分頁列表、
 * payment_no 當年序號查詢（FOR UPDATE 鎖）、confirmPaid 用的 FOR UPDATE 鎖列查詢。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 */
class PaymentRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ───────────────────────── 退款併發保護 ─────────────────────────
    //
    // 🔴 【本區塊的 SQL 為何不能包 COALESCE()】
    // PDO 的 `$stmt->execute($params)` 會把所有參數當字串綁定（PARAM_STR）。
    // 在 SQLite，`refunded_amount = :r` 會套用欄位的 NUMERIC affinity，把 '0'
    // 轉成數值後比較 —— 相等。但 `COALESCE(refunded_amount, 0) = :r` 的左側是
    // **運算式，沒有 affinity**，於是變成 INTEGER 0 對 TEXT '0'，SQLite 規定
    // 整數恆小於字串 → 永遠不相等，CAS 永遠搶不到。
    // MySQL 不會這樣（它會做數值轉換），所以這種錯誤只在測試環境現形，
    // 或者更糟：只在正式環境「看起來正常」而測試綠燈。
    // refunded_amount 是 NOT NULL DEFAULT 0.00，本來就不需要 COALESCE。
    // CAST 也不可用：SQLite 寫 `AS INTEGER`、MySQL 要寫 `AS SIGNED`，無法共用。

    /**
     * 以 CAS 搶下「這筆付款的退款作業」，**並把呼叫端據以計算的餘額一起鎖進條件**。
     *
     * 【為何只檢查 token IS NULL 不夠】（複審 2026-08-17）
     * 「沒有人正在退款」不等於「餘額還是我讀到的那個值」。實際會發生的交錯：
     *
     *   A 讀到 可退 1000，打算退 800
     *   B 完整跑完一次退款 300（B 成功後把 token 放回 NULL）
     *   A 這時才來搶 claim —— token 是 NULL，搶得到
     *   A 送出 800，但實際只剩 700 可退  → 超退 100
     *
     * check-then-act 的空窗不是靠「有沒有人在跑」關掉的，而是靠「我看到的狀態
     * 還在不在」關掉的。故 claim 條件必須包含呼叫端做前置檢查時的 status 與
     * refunded_amount 快照：兩者只要有任一被動過，這個 claim 就搶不到，
     * 呼叫端會被要求重讀後再試。
     *
     * 【why rowCount 可靠】每次傳入的 token 都是新的隨機值，值必定改變，
     * 因此「符合條件」⇔「rowCount === 1」。若日後改用固定值，這個 CAS 就會失效。
     *
     * @param string $expectedStatus   呼叫端做前置檢查時看到的 status
     * @param int    $expectedRefunded 呼叫端據以算出可退餘額的 refunded_amount
     */
    public function claimForRefund(int $id, string $token, string $expectedStatus, int $expectedRefunded): bool
    {
        return $this->db->execute(
            "UPDATE {prefix}payments
             SET refund_claim_token = :token, refund_claimed_at = NOW()
             WHERE id = :id
               AND refund_claim_token IS NULL
               AND status = :expected_status
               AND refunded_amount = :expected_refunded",
            [
                'id'                => $id,
                'token'             => $token,
                'expected_status'   => $expectedStatus,
                'expected_refunded' => $expectedRefunded,
            ]
        ) === 1;
    }

    /**
     * 退款成功後落盤：**必須驗證 claim 仍在自己手上**，且餘額仍是搶 claim 時的值。
     *
     * 【為何不能用一般的 update(WHERE id)】（複審 2026-08-17）
     * 送出外部退款到拿到回應之間可能過了數十秒。若這段期間有人（例如依 GO-LIVE §7
     * 手動清鎖的維運人員）把 claim 清掉，另一個請求就能合法接手並寫入新的
     * refunded_amount。此時遲到的原持有者若用 `WHERE id` 無條件寫入，會拿
     * **自己搶 claim 當時的舊基準**去覆寫別人剛寫好的較新金額 —— 錢退了兩次、
     * 帳面卻只記得一次，而且記的是比較小的那個數字。
     *
     * 條件式 UPDATE 讓這種情況變成「寫不進去」而非「悄悄覆寫」。回傳 false 時
     * 呼叫端必須視為 indeterminate（錢已經動了但沒能記帳），交由人工對帳。
     *
     * @param string $expectedStatus   搶 claim 當時的付款狀態（樂觀鎖基準）
     * @param int    $expectedRefunded 搶 claim 當時的 refunded_amount（樂觀鎖基準）
     * @param int    $newRefunded      本次退款後的累計已退金額
     * @param bool   $retainClaim      Keep ownership until the service confirms commit.
     */
    public function finalizeRefund(
        int $id,
        string $token,
        string $expectedStatus,
        int $expectedRefunded,
        int $newRefunded,
        string $newStatus,
        bool $retainClaim = false
    ): bool {
        $claimAssignments = $retainClaim ? '' : 'refund_claim_token = NULL, refund_claimed_at = NULL,';
        return $this->db->execute(
            "UPDATE {prefix}payments
             SET refunded_amount    = :new_refunded,
                 status             = :new_status,
                 {$claimAssignments}
                 updated_at         = NOW()
             WHERE id = :id
               AND refund_claim_token = :token
               AND status = :expected_status
               AND refunded_amount = :expected_refunded",
            [
                'id'                => $id,
                'token'             => $token,
                'expected_status'   => $expectedStatus,
                'expected_refunded' => $expectedRefunded,
                'new_refunded'      => $newRefunded,
                'new_status'        => $newStatus,
            ]
        ) === 1;
    }

    /**
     * 釋放退款 claim。
     *
     * 帶 token 比對，避免誤放掉別人持有的 claim（例如另一個請求後來才搶到）。
     * Only after provider rejection, or confirmed local commit of provider success.
     * Uncertain provider/persistence outcomes must retain the claim.
     */
    public function releaseRefundClaim(int $id, string $token): bool
    {
        return $this->db->execute(
            "UPDATE {prefix}payments
             SET refund_claim_token = NULL, refund_claimed_at = NULL
             WHERE id = :id AND refund_claim_token = :token",
            ['id' => $id, 'token' => $token]
        ) === 1;
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * 分頁查詢付款紀錄（含報價編號、客戶名）。
     *
     * @param array{provider?: string, status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}payments p {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;

        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT p.id, p.payment_no, p.quote_id, p.customer_id, p.provider, p.method,
                    p.amount, p.currency, p.status, p.provider_txn_id, p.paid_at, p.created_at,
                    q.quote_number AS quote_number,
                    c.display_name AS customer_name
             FROM {prefix}payments p
             LEFT JOIN {prefix}quotes q  ON p.quote_id = q.id
             LEFT JOIN {prefix}customers c ON p.customer_id = c.id
             {$where}
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 依 ID 取得付款（含報價編號、客戶名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT p.*,
                    q.quote_number AS quote_number,
                    q.title        AS quote_title,
                    c.display_name AS customer_name
             FROM {prefix}payments p
             LEFT JOIN {prefix}quotes q    ON p.quote_id = q.id
             LEFT JOIN {prefix}customers c ON p.customer_id = c.id
             WHERE p.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 依 payment_no 取得付款（公開付款流程 / 回呼用）。
     */
    public function findByNo(string $paymentNo): ?array
    {
        if ($paymentNo === '') {
            return null;
        }
        return $this->db->fetch(
            "SELECT * FROM {prefix}payments WHERE payment_no = :no",
            ['no' => $paymentNo]
        );
    }

    /**
     * 依 idempotency_key 取得付款（冪等檢查用）。
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        return $this->db->fetch(
            "SELECT * FROM {prefix}payments WHERE idempotency_key = :k",
            ['k' => $key]
        );
    }

    /**
     * 依 provider_txn_id 取得付款（防重放檢查用）。
     */
    public function findByProviderTxn(string $txnId): ?array
    {
        if ($txnId === '') {
            return null;
        }
        return $this->db->fetch(
            "SELECT * FROM {prefix}payments WHERE provider_txn_id = :t",
            ['t' => $txnId]
        );
    }

    /**
     * 取得某報價單的付款紀錄（最新在前）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {prefix}payments WHERE quote_id = :qid ORDER BY created_at DESC, id DESC",
            ['qid' => $quoteId]
        );
    }

    /**
     * 取得某客戶的付款紀錄（客戶 Portal 用，強制 customer_id scope；含報價編號）。
     * Zero Trust：直接以 customer_id 過濾，不依賴前端傳入任何條件。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT p.id, p.payment_no, p.quote_id, p.customer_id, p.provider, p.method,
                    p.amount, p.currency, p.status, p.paid_at, p.created_at,
                    q.quote_number AS quote_number, q.title AS quote_title
             FROM {prefix}payments p
             LEFT JOIN {prefix}quotes q ON p.quote_id = q.id
             WHERE p.customer_id = :customer_id
             ORDER BY p.created_at DESC, p.id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取得某報價單最新一筆付款（公開頁/後台顯示用）。
     */
    public function findLatestByQuote(int $quoteId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}payments WHERE quote_id = :qid ORDER BY created_at DESC, id DESC LIMIT 1",
            ['qid' => $quoteId]
        );
    }

    /**
     * 在交易內以 FOR UPDATE 鎖定並取得付款列（confirmPaid 用，杜絕併發重複入帳）。
     * 須於交易內呼叫。
     */
    public function findByNoForUpdate(string $paymentNo): ?array
    {
        if ($paymentNo === '') {
            return null;
        }
        return $this->db->fetch(
            "SELECT * FROM {prefix}payments WHERE payment_no = :no FOR UPDATE",
            ['no' => $paymentNo]
        );
    }

    // ───────────────────────── 寫入 ─────────────────────────

    /**
     * 冪等新增付款：若 idempotency_key 已存在，直接回傳既有列（不重複建單）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> 既有或新建的付款列
     */
    public function insertIdempotent(array $data): array
    {
        $key = (string) ($data['idempotency_key'] ?? '');

        // 先查冪等鍵（防重複建單）。
        $existing = $this->findByIdempotencyKey($key);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->execute(
            "INSERT INTO {prefix}payments
                (payment_no, quote_id, customer_id, provider, method, amount, currency,
                 status, provider_txn_id, idempotency_key, raw_request, raw_callback, paid_at,
                 created_at, updated_at)
             VALUES
                (:payment_no, :quote_id, :customer_id, :provider, :method, :amount, :currency,
                 :status, :provider_txn_id, :idempotency_key, :raw_request, :raw_callback, :paid_at,
                 NOW(), NOW())",
            [
                'payment_no'      => $data['payment_no'],
                'quote_id'        => $data['quote_id'] ?? null,
                'customer_id'     => $data['customer_id'] ?? null,
                'provider'        => $data['provider'] ?? 'sandbox',
                'method'          => $data['method'] ?? null,
                'amount'          => $data['amount'] ?? 0,
                'currency'        => $data['currency'] ?? 'TWD',
                'status'          => $data['status'] ?? 'pending',
                'provider_txn_id' => ($data['provider_txn_id'] ?? '') !== '' ? $data['provider_txn_id'] : null,
                'idempotency_key' => $key,
                'raw_request'     => $data['raw_request'] ?? null,
                'raw_callback'    => $data['raw_callback'] ?? null,
                'paid_at'         => $data['paid_at'] ?? null,
            ]
        );

        $id = (int) $this->db->lastInsertId();
        // 回傳完整列（含 DB 預設值）。
        return $this->findByNo((string) $data['payment_no']) ?? ['id' => $id] + $data;
    }

    /**
     * 更新付款（白名單欄位）。
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        // 新增欄位務必同步加入白名單，否則寫入會靜默失敗（無錯誤、值進不去）。
        $allowedFields = [
            'quote_id', 'customer_id', 'provider', 'method', 'amount', 'currency',
            'status', 'provider_txn_id', 'raw_request', 'raw_callback', 'paid_at',
            'refunded_amount', 'refund_claim_token', 'refund_claimed_at',
            'invoice_requested_at',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($data[$field] === PaymentTimestamp::DatabaseNow) {
                    if (!in_array($field, ['paid_at', 'invoice_requested_at'], true)) {
                        throw new \InvalidArgumentException('DB 時鐘僅可用於付款時間欄位。');
                    }
                    $sets[] = "{$field} = NOW()";
                } else {
                    $sets[]         = "{$field} = :{$field}";
                    $params[$field] = $data[$field];
                }
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[]    = 'updated_at = NOW()';
        $setClause = implode(', ', $sets);

        $this->db->execute(
            "UPDATE {prefix}payments SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 取得指定年份目前最大的 payment_no 流水序號（PAY-YYYY-NNNN）。
     * 以 FOR UPDATE 鎖定當年既有列，配合交易避免併發產生重號。
     * 回傳目前最大序號（無則 0；下一號為其 +1）。
     */
    public function maxSequenceForYearForUpdate(int $year): int
    {
        $prefix = sprintf('PAY-%04d-', $year);
        $row = $this->db->fetch(
            "SELECT MAX(CAST(SUBSTRING(payment_no, :start) AS UNSIGNED)) AS max_seq
             FROM {prefix}payments
             WHERE payment_no LIKE :like
             FOR UPDATE",
            [
                'start' => strlen($prefix) + 1,
                'like'  => $prefix . '%',
            ]
        );

        return (int) ($row['max_seq'] ?? 0);
    }

    // ───────────────────────── 篩選 ─────────────────────────

    /**
     * 依篩選條件組 WHERE 子句與綁定參數。
     *
     * @param array{provider?: string, status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // provider：白名單
        $provider = (string) ($filters['provider'] ?? '');
        if (in_array($provider, ['sandbox', 'payuni', 'shopline'], true)) {
            $conditions[] = 'p.provider = :provider';
            $params['provider'] = $provider;
        }

        // status：白名單
        $status = (string) ($filters['status'] ?? '');
        // 白名單取自 PaymentService::STATUSES（唯一來源），避免新增狀態時漏改這裡
        // 造成「該狀態的付款在列表裡永遠篩不出來」。
        if (in_array($status, PaymentService::STATUSES, true)) {
            $conditions[] = 'p.status = :status';
            $params['status'] = $status;
        }

        // 客戶：正整數
        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $conditions[] = 'p.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        // 關鍵字：付款編號 / 金流交易序號（LIKE，跳脫萬用字元）
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $this->escapeLike($keyword) . '%';
            $conditions[] = "(p.payment_no LIKE :kw_no ESCAPE '\\\\'
                              OR p.provider_txn_id LIKE :kw_txn ESCAPE '\\\\')";
            $params['kw_no']  = $like;
            $params['kw_txn'] = $like;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    /**
     * 跳脫 LIKE 中的萬用字元。
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
