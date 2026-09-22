<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;

/**
 * 報價單主檔資料存取層。
 * 提供 CRUD、含篩選（status / customer_id / keyword）的分頁列表、
 * 依 access_token 查詢（公開頁用）、quote_number 當年序號查詢。
 * JOIN 客戶名與建立者名供顯示。全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.5 quotes、§7.8 報價單系統。
 */
class QuoteRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 分頁查詢報價單列表（含客戶名、明細數、簽署數）。
     *
     * @param array{status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}quotes q {$where}",
            $params
        );

        $offset = ($page - 1) * $perPage;

        $listParams = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = $offset;

        $items = $this->db->fetchAll(
            "SELECT q.id, q.quote_number, q.customer_id, q.title, q.status, q.visibility,
                    q.total, q.currency, q.valid_until, q.payment_enabled, q.payment_status,
                    q.our_seal_applied, q.created_at, q.sent_at,
                    c.display_name AS customer_name,
                    COALESCE(sig.cnt, 0) AS signature_count
             FROM {prefix}quotes q
             LEFT JOIN {prefix}customers c ON q.customer_id = c.id
             LEFT JOIN (
                SELECT quote_id, COUNT(*) AS cnt
                FROM {prefix}quote_signatures GROUP BY quote_id
             ) sig ON sig.quote_id = q.id
             {$where}
             ORDER BY q.created_at DESC, q.id DESC
             LIMIT :limit OFFSET :offset",
            $listParams
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 取得指定客戶的報價單（供客戶內頁「報價單」tab）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT q.id, q.quote_number, q.title, q.status, q.visibility,
                    q.total, q.currency, q.valid_until, q.created_at
             FROM {prefix}quotes q
             WHERE q.customer_id = :customer_id
             ORDER BY q.created_at DESC, q.id DESC",
            ['customer_id' => $customerId]
        );
    }

    /**
     * 取得單一報價單（含客戶名、客戶資料、建立者名）。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT q.*,
                    c.display_name AS customer_name,
                    c.type AS customer_type,
                    c.tax_id AS customer_tax_id,
                    c.address AS customer_address,
                    c.phone AS customer_phone,
                    c.email AS customer_email,
                    cb.display_name AS created_by_name
             FROM {prefix}quotes q
             LEFT JOIN {prefix}customers c  ON q.customer_id = c.id
             LEFT JOIN {prefix}users cb     ON q.created_by  = cb.id
             WHERE q.id = :id",
            ['id' => $id]
        );
    }

    /**
     * 依 access_token 取得報價單（公開頁用）。
     * 僅回傳報價本體 + 客戶資料（不含建立者，公開頁不需）。
     */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return $this->db->fetch(
            "SELECT q.*,
                    c.display_name AS customer_name,
                    c.type AS customer_type,
                    c.tax_id AS customer_tax_id,
                    c.address AS customer_address,
                    c.phone AS customer_phone,
                    c.email AS customer_email
             FROM {prefix}quotes q
             LEFT JOIN {prefix}customers c ON q.customer_id = c.id
             WHERE q.access_token = :token",
            ['token' => $token]
        );
    }

    /**
     * 新增報價單。金額欄位由 Service 預先算妥後傳入。
     *
     * @return int 新報價單 ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}quotes
                (quote_number, customer_id, title, status, visibility,
                 access_token, access_password_hash,
                 share_auto_expire, share_duration_days, share_enabled_at, share_expires_at,
                 share_closed_at, share_unlimited_ack_at, share_policy_revision,
                 subtotal, tax_rate, tax, total, currency, valid_until,
                 terms, notes, payment_enabled, payment_status, our_seal_applied,
                 related_job_id, created_by, sent_at, created_at, updated_at)
             VALUES
                (:quote_number, :customer_id, :title, :status, :visibility,
                 :access_token, :access_password_hash,
                 :share_auto_expire, :share_duration_days, :share_enabled_at, :share_expires_at,
                 :share_closed_at, :share_unlimited_ack_at, :share_policy_revision,
                 :subtotal, :tax_rate, :tax, :total, :currency, :valid_until,
                 :terms, :notes, :payment_enabled, :payment_status, :our_seal_applied,
                 :related_job_id, :created_by, :sent_at, NOW(), NOW())",
            [
                'quote_number'           => $data['quote_number'],
                'customer_id'            => $data['customer_id'] ?? null,
                'title'                  => $data['title'],
                'status'                 => $data['status'] ?? 'draft',
                'visibility'             => $data['visibility'] ?? 'private',
                'access_token'           => $data['access_token'] ?? null,
                'access_password_hash'   => $data['access_password_hash'] ?? null,
                'share_auto_expire'      => $data['share_auto_expire'] ?? null,
                'share_duration_days'    => $data['share_duration_days'] ?? null,
                'share_enabled_at'       => $data['share_enabled_at'] ?? null,
                'share_expires_at'       => $data['share_expires_at'] ?? null,
                'share_closed_at'        => $data['share_closed_at'] ?? null,
                'share_unlimited_ack_at' => $data['share_unlimited_ack_at'] ?? null,
                'share_policy_revision'  => (int) ($data['share_policy_revision'] ?? 0),
                'subtotal'             => $data['subtotal'] ?? 0,
                'tax_rate'             => $data['tax_rate'] ?? 0,
                'tax'                  => $data['tax'] ?? 0,
                'total'                => $data['total'] ?? 0,
                'currency'             => $data['currency'] ?? 'TWD',
                'valid_until'          => $data['valid_until'] ?? null,
                'terms'                => ($data['terms'] ?? '') !== '' ? $data['terms'] : null,
                'notes'                => ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                'payment_enabled'      => (int) ($data['payment_enabled'] ?? 0),
                'payment_status'       => $data['payment_status'] ?? 'unpaid',
                'our_seal_applied'     => (int) ($data['our_seal_applied'] ?? 0),
                'related_job_id'       => $data['related_job_id'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
                'sent_at'              => $data['sent_at'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新報價單（白名單欄位）。
     */
    public function update(int $id, array $data): void
    {
        $sets   = [];
        $params = ['id' => $id];

        $allowedFields = [
            'customer_id', 'title', 'status', 'visibility',
            'access_token', 'access_password_hash',
            'subtotal', 'tax_rate', 'tax', 'total', 'currency', 'valid_until',
            'terms', 'notes', 'payment_enabled', 'payment_status', 'our_seal_applied',
            'related_job_id', 'sent_at',
            // P4-6 週期單關聯（由 RecurringService 寫入；缺此白名單會導致 is_recurring 永不持久化）。
            'is_recurring', 'recurring_schedule_id',
            // Q2 匿名分享期限（版本號另由 incrementShareRevision 原子遞增，不經此白名單）。
            'share_auto_expire', 'share_duration_days', 'share_enabled_at', 'share_expires_at',
            'share_closed_at', 'share_unlimited_ack_at',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[]         = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[]    = 'updated_at = NOW()';
        $setClause = implode(', ', $sets);

        $this->db->execute(
            "UPDATE {prefix}quotes SET {$setClause} WHERE id = :id",
            $params
        );
    }

    /**
     * 分享授權版本 +1（以 SQL 原子遞增，不經 PHP 讀改寫，避免兩個並行編輯寫回同一個版本號）。
     */
    public function incrementShareRevision(int $id): void
    {
        $this->db->execute(
            "UPDATE {prefix}quotes SET share_policy_revision = share_policy_revision + 1 WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 於交易內鎖定並讀取分享授權狀態（簽署／發動付款／編輯分享時重讀用）。
     *
     * @return array<string, mixed>|null
     */
    public function findShareStateForUpdate(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT id, visibility, customer_id, access_token, access_password_hash,
                    share_auto_expire, share_duration_days, share_enabled_at, share_expires_at,
                    share_closed_at, share_unlimited_ack_at, share_policy_revision
             FROM {prefix}quotes
             WHERE id = :id
             FOR UPDATE",
            ['id' => $id]
        );
    }

    /**
     * 刪除報價單（明細 / 瀏覽軌跡 / 簽署由 FK CASCADE 連帶刪除）。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}quotes WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 取得指定年份目前最大的流水序號（quote_number 為 Q-YYYY-NNNN）。
     * 以 FOR UPDATE 鎖定當年既有列，配合交易避免併發產生重號。
     * 回傳目前最大序號（無則 0；下一號為其 +1）。
     */
    public function maxSequenceForYearForUpdate(int $year): int
    {
        $prefix = sprintf('Q-%04d-', $year);
        // 取 quote_number 第 8 碼起（NNNN）轉數字的最大值。
        // LIKE 'Q-2026-%' 命中當年所有號；FOR UPDATE 於交易內鎖定避免併發重號。
        $row = $this->db->fetch(
            "SELECT MAX(CAST(SUBSTRING(quote_number, :start) AS UNSIGNED)) AS max_seq
             FROM {prefix}quotes
             WHERE quote_number LIKE :like
             FOR UPDATE",
            [
                'start' => strlen($prefix) + 1,
                'like'  => $prefix . '%',
            ]
        );

        return (int) ($row['max_seq'] ?? 0);
    }

    /**
     * 報價單總數。
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM {prefix}quotes");
    }

    /**
     * 依篩選條件組 WHERE 子句與綁定參數。
     *
     * @param array{status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        // 狀態：僅接受白名單值
        $status = $filters['status'] ?? '';
        if (in_array($status, ['draft', 'sent', 'viewed', 'signed', 'paid', 'expired', 'void'], true)) {
            $conditions[] = 'q.status = :status';
            $params['status'] = $status;
        }

        // 客戶：正整數
        $customerId = (int) ($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $conditions[] = 'q.customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        // 關鍵字：比對報價編號 / 標題（LIKE，特殊字元跳脫）
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $this->escapeLike($keyword) . '%';
            $conditions[] = "(q.quote_number LIKE :kw_num ESCAPE '\\\\'
                              OR q.title LIKE :kw_title ESCAPE '\\\\')";
            $params['kw_num']   = $like;
            $params['kw_title'] = $like;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    /**
     * 跳脫 LIKE 中的萬用字元，避免使用者輸入的 % 或 _ 被當作萬用字元。
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
