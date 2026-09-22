<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\Database;

/**
 * 電子發票 API 往來紀錄存取層。
 *
 * record() 為 static：PayNowRestClient 在每次 API 呼叫後都要寫 log，
 * 但 client 本身不該持有 repository 依賴（它可能在測試中以假 logger 注入）。
 *
 * 【寫入失敗不得中斷業務】log 是輔助存證，若寫入失敗（例如表尚未建立、DB 短暫異常），
 * 絕不能讓一張本來可以成功開立的發票因此失敗。故 record() 吞掉所有例外。
 */
class InvoiceApiLogRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 寫入一筆 API log（供 client 呼叫）。
     *
     * @param array<string,mixed> $entry
     */
    public static function record(array $entry): void
    {
        try {
            Database::getInstance()->execute(
                "INSERT INTO {prefix}invoice_api_logs
                    (invoice_id, payment_id, correlation_id, operation, environment,
                     http_method, endpoint, http_status, success, request_id, duration_ms,
                     request_payload, response_payload, error_message)
                 VALUES
                    (:invoice_id, :payment_id, :correlation_id, :operation, :environment,
                     :http_method, :endpoint, :http_status, :success, :request_id, :duration_ms,
                     :request_payload, :response_payload, :error_message)",
                [
                    'invoice_id'       => (int) ($entry['invoice_id'] ?? 0) ?: null,
                    'payment_id'       => (int) ($entry['payment_id'] ?? 0) ?: null,
                    'correlation_id'   => (string) ($entry['correlation_id'] ?? ''),
                    'operation'        => (string) ($entry['operation'] ?? ''),
                    'environment'      => (string) ($entry['environment'] ?? 'test'),
                    'http_method'      => (string) ($entry['http_method'] ?? 'POST'),
                    'endpoint'         => mb_substr((string) ($entry['endpoint'] ?? ''), 0, 255),
                    'http_status'      => (int) ($entry['http_status'] ?? 0),
                    'success'          => (int) ($entry['success'] ?? 0),
                    'request_id'       => mb_substr((string) ($entry['request_id'] ?? ''), 0, 64),
                    'duration_ms'      => (int) ($entry['duration_ms'] ?? 0),
                    'request_payload'  => (string) ($entry['request_payload'] ?? ''),
                    'response_payload' => (string) ($entry['response_payload'] ?? ''),
                    'error_message'    => (string) ($entry['error_message'] ?? ''),
                ]
            );
        } catch (\Throwable) {
            // 見類別註解：log 寫入失敗絕不影響發票開立本身。
        }
    }

    /**
     * 分頁查詢 log。
     *
     * @param array{invoice_id?: int, payment_id?: int, operation?: string, success?: string, correlation_id?: string} $filters
     * @return array{items: array<int,array<string,mixed>>, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 30, array $filters = []): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($filters['invoice_id'])) {
            $conditions[]         = 'invoice_id = :invoice_id';
            $params['invoice_id'] = (int) $filters['invoice_id'];
        }
        if (!empty($filters['payment_id'])) {
            $conditions[]         = 'payment_id = :payment_id';
            $params['payment_id'] = (int) $filters['payment_id'];
        }
        if (!empty($filters['operation'])) {
            $conditions[]        = 'operation = :operation';
            $params['operation'] = (string) $filters['operation'];
        }
        if (isset($filters['success']) && $filters['success'] !== '') {
            $conditions[]      = 'success = :success';
            $params['success'] = (int) $filters['success'];
        }
        if (!empty($filters['correlation_id'])) {
            $conditions[]             = 'correlation_id = :correlation_id';
            $params['correlation_id'] = (string) $filters['correlation_id'];
        }

        $conditions = array_merge($conditions, self::dateRangeConditions('created_at', $filters, $params));

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}invoice_api_logs {$where}",
            $params
        );

        $listParams           = $params;
        $listParams['limit']  = $perPage;
        $listParams['offset'] = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT * FROM {prefix}invoice_api_logs {$where}
             ORDER BY id DESC
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

    /** log 保留天數的絕對下限。低於此值一律當作此值。 */
    public const MIN_RETENTION_DAYS = 7;

    /**
     * 清除保留期限外的舊 log（由 cron 呼叫）。
     *
     * 🔴 【為何下限是 7 而不是 1】刪除不可逆，而觸發它的是一個整數參數 ——
     * 設定值為空時 ，原本的  會把它變成
     * 「刪除一天以前的全部紀錄」，也就是**一個沒填的欄位可以清掉整段診斷歷史**。
     *
     * 呼叫端（InvoiceSettings::logRetentionDays）已經有 7 天下限，但那是「今天剛好
     * 只有一個呼叫端」的巧合。真正執行刪除的這一層必須自己守住底線 ——
     * 沒有任何正當用途需要「只留一天的 API log」。
     */
    public function purgeOlderThan(int $days = 90): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}invoice_api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => max(self::MIN_RETENTION_DAYS, $days)]
        );
    }
}
