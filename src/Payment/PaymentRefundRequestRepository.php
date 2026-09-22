<?php
declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Core\Database;

/**
 * 退款請求冪等帳（F04）。
 *
 * 【與 refund_claim_token 的分工】
 *   - claim token＝「現在有沒有人在跑」，每次呼叫重新產生，擋並行。
 *   - request id  ＝「這是不是同一個請求」，由呼叫端攜帶且跨請求持久，擋重播。
 * 兩者都需要：少了 claim，兩個並行請求會各退一次；少了 request id，
 * 回應遺失後的第二次點擊會變成一筆全新的合法退款。
 */
class PaymentRefundRequestRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 登記一次退款請求。
     *
     * @return array|null null＝本次成功搶到（可繼續送出）；
     *                    非 null＝這個 request_id 先前已登記過，回傳既有列供呼叫端回放。
     */
    public function begin(string $requestId, int $paymentId, int $amount, int $adminUserId): ?array
    {
        try {
            $this->db->execute(
                "INSERT INTO {prefix}payment_refund_requests
                    (request_id, payment_id, amount, status, admin_user_id, created_at)
                 VALUES (:rid, :pid, :amount, 'in_flight', :uid, NOW())",
                [
                    'rid'    => $requestId,
                    'pid'    => $paymentId,
                    'amount' => $amount,
                    'uid'    => $adminUserId > 0 ? $adminUserId : null,
                ]
            );
            return null;
        } catch (\Throwable $e) {
            // 唯一鍵衝突＝重播。但不能只憑「有例外」就假設是衝突：
            // 連線中斷／欄位型別錯誤也會走到這裡，那時必須讓錯誤浮出來，
            // 而不是被誤判成「已處理過」而靜默放行。
            $existing = $this->find($requestId);
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }
    }

    public function find(string $requestId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM {prefix}payment_refund_requests WHERE request_id = :rid',
            ['rid' => $requestId]
        );
        return $row ?: null;
    }

    /**
     * 標記最終結果。
     *
     * 🔴 只有能證明「provider 從未被呼叫」的失敗才可標 failed（該 id 之後可重試）。
     * 任何我方拿不到確定結果的情況一律 indeterminate —— 重播時只回放、不重送。
     */
    public function complete(
        string $requestId,
        string $status,
        string $outcome,
        string $message,
        ?string $claimToken = null
    ): void {
        if (!in_array($status, ['success', 'failed', 'indeterminate'], true)) {
            throw new \InvalidArgumentException('未知的退款請求狀態：' . $status);
        }

        $this->db->execute(
            "UPDATE {prefix}payment_refund_requests
                SET status = :status, outcome = :outcome, message = :message,
                    claim_token = :token, completed_at = NOW()
              WHERE request_id = :rid",
            [
                'status'  => $status,
                'outcome' => $outcome,
                'message' => mb_substr($message, 0, 2000),
                'token'   => $claimToken,
                'rid'     => $requestId,
            ]
        );
    }
}
