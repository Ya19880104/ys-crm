<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment\PayUni;

use YangSheep\CRM\Core\HttpClient;

/**
 * PayUni（統一金流）API 用戶端。
 *
 * 移植自 ys-cart v2.57.0 的 YSPayuniRequester，去除 WordPress 相依。
 *
 * 【Version 對照表】此表由實測歸納，官方文件未完整載明，改動前務必確認：
 *   1.0  UPP 導轉 / trade-close 退款 / trade-cancel 取消授權 / atm / cvs / credit_bind
 *   1.2  /api/credit（信用卡 Token 扣款）
 *   2.0  /api/trade/query（交易查詢）
 *   3.0  /api/iframe/token_get（免跳轉 SDK，本系統未使用）
 *
 * 【回應為何要分三類而不是布林】
 * 金流最危險的情境不是「明確失敗」，而是「不知道成功與否」（逾時、5xx）。
 * 若把不確定當失敗，退款會被重送、扣款會被重扣；若當成功，帳務直接分裂。
 * 故所有寫入型操作一律回傳 outcome：success / rejected_terminal / indeterminate，
 * 由呼叫端決定：只有 success 才可改寫本地狀態。
 */
final class PayUniClient
{
    /** 連線層失敗或這些 HTTP 狀態 → 結果不確定，不可據以改寫狀態 */
    private const INDETERMINATE_STATUSES = [0, 408, 409, 429, 500, 502, 503, 504];

    private string $merchantId;
    private string $hashKey;
    private string $hashIv;
    private string $environment;
    private HttpClient $http;

    /** @var callable|null fn(array $entry): void 呼叫紀錄（供稽核／除錯） */
    private $logger;

    public function __construct(
        string $merchantId,
        string $hashKey,
        string $hashIv,
        string $environment = 'test',
        ?HttpClient $http = null,
        ?callable $logger = null
    ) {
        $this->merchantId  = trim($merchantId);
        $this->hashKey     = trim($hashKey);
        $this->hashIv      = trim($hashIv);
        $this->environment = $environment === 'prod' ? 'prod' : 'test';
        $this->http        = $http ?? new HttpClient();
        $this->logger      = $logger;
    }

    public function apiBase(): string
    {
        return $this->environment === 'prod'
            ? 'https://api.payuni.com.tw/api/'
            : 'https://sandbox-api.payuni.com.tw/api/';
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * 交易查詢（/api/trade/query，Version 2.0）。
     *
     * 可用 PayUni 交易序號（TradeNo）或商店訂單編號（MerTradeNo）其一查詢。
     *
     * @return array{ok: bool, outcome: string, data: array<string,mixed>, message: string}
     */
    public function queryTrade(string $tradeNo = '', string $merTradeNo = ''): array
    {
        if ($tradeNo === '' && $merTradeNo === '') {
            return $this->fail('查詢需提供 TradeNo 或 MerTradeNo。');
        }

        $data = [];
        if ($tradeNo !== '') {
            $data['TradeNo'] = $tradeNo;
        } else {
            $data['MerTradeNo'] = $merTradeNo;
        }

        return $this->post('trade/query', $data, '2.0', 'query');
    }

    // ───────────────────────── 退款 ─────────────────────────

    /**
     * 信用卡退款（/api/trade/close，CloseType=2）。
     *
     * @param string $tradeNo PayUni 交易序號（原交易）
     * @param int    $amount  退款金額；0 或省略代表全額退款
     * @return array{ok: bool, outcome: string, data: array<string,mixed>, message: string}
     */
    public function refund(string $tradeNo, int $amount = 0): array
    {
        if (trim($tradeNo) === '') {
            return $this->fail('退款需提供原交易序號（TradeNo）。');
        }

        $data = [
            'TradeNo'   => trim($tradeNo),
            'CloseType' => '2', // 1=請款 2=退款 -1=取消請款 -2=取消退款
        ];
        // 不帶 TradeAmt 即為全額退款（PayUni 規格）
        if ($amount > 0) {
            $data['TradeAmt'] = (string) $amount;
        }

        return $this->post('trade/close', $data, '1.0', 'refund');
    }

    /**
     * 請款（CloseType=1）。授權後未自動請款的交易需要此步驟。
     */
    public function capture(string $tradeNo, int $amount = 0): array
    {
        $data = ['TradeNo' => trim($tradeNo), 'CloseType' => '1'];
        if ($amount > 0) {
            $data['TradeAmt'] = (string) $amount;
        }

        return $this->post('trade/close', $data, '1.0', 'capture');
    }

    /**
     * 取消授權（/api/trade/cancel）。用於尚未請款的交易。
     */
    public function cancelAuth(string $tradeNo): array
    {
        return $this->post('trade/cancel', ['TradeNo' => trim($tradeNo)], '1.0', 'cancel');
    }

    // ───────────────────────── Token 扣款 ─────────────────────────

    /**
     * 以綁定信用卡 Token 扣款（/api/credit，Version 1.2）。
     *
     * @param string $token      綁卡取得的 CreditToken
     * @param string $merTradeNo 商店訂單編號（**必須是穩定值**，見 stableMerTradeNo）
     * @param int    $amount     扣款金額
     * @param string $prodDesc   商品說明
     */
    public function chargeToken(string $token, string $merTradeNo, int $amount, string $prodDesc = ''): array
    {
        if (trim($token) === '' || trim($merTradeNo) === '' || $amount < 1) {
            return $this->fail('Token 扣款參數不完整。');
        }

        return $this->post('credit', [
            'MerTradeNo'      => trim($merTradeNo),
            'TradeAmt'        => (string) $amount,
            'ProdDesc'        => mb_substr($prodDesc !== '' ? $prodDesc : $merTradeNo, 0, 50),
            'CreditToken'     => trim($token),
            'UseTokenType'    => '1', // 使用既有 token 扣款
            'CreditTokenType' => '2',
        ], '1.2', 'charge');
    }

    /**
     * 解除綁卡（/api/credit_bind/cancel）。
     */
    public function cancelToken(string $token): array
    {
        return $this->post('credit_bind/cancel', [
            'BindVal'      => trim($token),
            'UseTokenType' => '1',
        ], '1.0', 'cancel');
    }

    // ───────────────────────── 工具 ─────────────────────────

    /**
     * 由穩定要素導出 MerTradeNo（PayUni 上限 20 碼）。
     *
     * 【為何不能用時間或亂數】PayUni 以 MerTradeNo 作為訂單唯一鍵。若每次重試都
     * 產生新的編號，一次逾時重送就會變成兩筆真實扣款——PayUni 端看到的是兩張不同訂單，
     * 無從為我方去重。故編號必須由「這次扣款意圖」的不變要素導出：
     * 同一意圖恆得同值，不同意圖必然不同。
     *
     * @param string $operationKey 例如 "pay_123" 或 "recurring_45_202608"
     */
    public static function stableMerTradeNo(string $operationKey, string $prefix = 'YS'): string
    {
        $digest = substr(hash('sha256', $operationKey), 0, 18);

        return strtoupper($prefix . $digest);
    }

    // ───────────────────────── 內部 ─────────────────────────

    /**
     * 送出請求並解析回應。
     *
     * @param array<string, mixed> $data 業務參數（MerID / Timestamp 由本方法補上）
     * @return array{ok: bool, outcome: string, data: array<string,mixed>, message: string, http_status?: int}
     */
    private function post(string $endpoint, array $data, string $version, string $operation = 'query'): array
    {
        // 【為何 $operation 用參數傳到 parseResponse，而不是存成物件屬性】
        // 存成屬性代表任何一次巢狀／後續呼叫（例如退款後補一次查詢）都會覆寫它，
        // 於是「這個回應屬於哪種操作」會取決於呼叫順序。金流的保守判定不能建立在
        // 這種隱性狀態上 —— 一旦被覆寫，寫入型操作就會被當成讀取型而誤判為明確失敗。
        if ($this->merchantId === '' || $this->hashKey === '' || $this->hashIv === '') {
            return $this->fail('PayUni 金鑰尚未設定。');
        }

        $data['MerID']     = $this->merchantId;
        $data['Timestamp'] = (string) time();

        try {
            $encryptInfo = PayUniCrypto::encrypt($data, $this->hashKey, $this->hashIv);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $body = http_build_query([
            'MerID'       => $this->merchantId,
            'Version'     => $version,
            'EncryptInfo' => $encryptInfo,
            'HashInfo'    => PayUniCrypto::hash($encryptInfo, $this->hashKey, $this->hashIv),
        ]);

        $started  = microtime(true);
        $response = $this->http->request('POST', $this->apiBase() . ltrim($endpoint, '/'), [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent'   => 'payuni', // PayUni 要求帶此 UA
            ],
            'body'    => $body,
            'timeout' => 30,
        ]);

        $httpStatus = (int) $response['status'];
        $result     = $this->parseResponse(
            $httpStatus,
            (string) $response['body'],
            (string) ($response['error'] ?? ''),
            $operation
        );

        $this->log([
            'endpoint'    => $endpoint,
            'version'     => $version,
            'http_status' => $httpStatus,
            'outcome'     => $result['outcome'],
            'message'     => $result['message'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            // 業務參數可含金額與交易序號，但不含金鑰；EncryptInfo 不記錄（含完整明文）
            'request'     => array_diff_key($data, ['MerID' => 1]),
        ]);

        return $result;
    }

    /**
     * 解析 PayUni 回應。
     *
     * 回應可能是 JSON，也可能是 query string；可信的結果一定在 EncryptInfo 內，
     * 需驗章後解密才可採信。
     *
     * @param string $operation 本次操作類型（決定「看不懂的回應」如何歸類），
     *                          刻意用參數而非物件狀態 —— 見 post() 的說明。
     * @return array{ok: bool, outcome: string, data: array<string,mixed>, message: string, http_status: int, signed: bool}
     */
    private function parseResponse(int $httpStatus, string $body, string $error, string $operation): array
    {
        // 連線層失敗或伺服器暫時性錯誤 → 結果不確定，呼叫端必須查詢確認，不可重送。
        if (in_array($httpStatus, self::INDETERMINATE_STATUSES, true)) {
            return [
                'ok'          => false,
                'outcome'     => 'indeterminate',
                'data'        => [],
                'message'     => $error !== ''
                    ? 'PayUni 連線失敗：' . $error
                    : sprintf('PayUni 回應 HTTP %d，結果不確定。', $httpStatus),
                'http_status' => $httpStatus,
                'signed'      => false,
            ];
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $parsed = [];
            parse_str($body, $parsed);
            $payload = $parsed;
        }

        $encryptInfo = (string) ($payload['EncryptInfo'] ?? '');
        $hashInfo    = (string) ($payload['HashInfo'] ?? '');

        // 【「看不懂的回應」怎麼歸類】（複審 2026-08-17 修正）
        //
        // 對讀取型操作（查詢），看不懂就是查不到，重試無害 → 可以是明確失敗。
        // 但對**寫入型操作（退款、扣款、請款、取消）**，「我看不懂回應」不等於
        // 「對方沒做」—— PayUni 可能已經完成退款，只是回應在傳輸中被截斷、
        // 被中間層換成錯誤頁、或格式改版。若歸類為明確拒絕，呼叫端會釋放退款鎖，
        // 操作者看到「退款失敗」而再按一次 → 雙退。
        //
        // 🔴 這裡原本有一個漏洞：只要裸回應裡有**任何** Status 或 Message 欄位，
        // 就當成「可信的拒絕」。但那段內容**完全沒有簽章**——任何能影響回應的東西
        // （代理錯誤頁、WAF、被截斷的 body、甚至攻擊者）都能偽造出一個
        // `Status=...`，於是「未簽章的猜測」被當成了確定的事實。
        //
        // 現在的規則沒有例外：**寫入型操作只認經過驗章的回應**。未簽章的內容一律
        // indeterminate（呼叫端保留鎖、留待人工確認）。這確實會讓「參數打錯」
        // 這類本來就不會動到錢的情況也需要人工解鎖，但那是可接受的代價 ——
        // 我方在密碼學上無法區分它與「已經退款成功但回應毀損」。
        $isWrite = in_array($operation, ['refund', 'capture', 'cancel', 'charge'], true);
        $unreadableOutcome = $isWrite ? 'indeterminate' : 'rejected_terminal';

        // 沒有加密內容 → 這段回應未經驗章，寫入型操作不得據以判定「沒退成」。
        if ($encryptInfo === '') {
            $status  = strtoupper((string) ($payload['Status'] ?? ''));
            $message = (string) ($payload['Message'] ?? '');
            $hasBare = $status !== '' || $message !== '';

            // 讀取型操作：對方有給出 Status/Message → 視為可信的查詢失敗（重試無害）。
            $outcome = (!$isWrite && $hasBare) ? 'rejected_terminal' : $unreadableOutcome;

            $detail = $message !== ''
                ? $message
                : ($status !== '' ? ('PayUni 回應狀態：' . $status) : '');

            return [
                'ok'          => false,
                'outcome'     => $outcome,
                'data'        => is_array($payload) ? $payload : [],
                'message'     => $outcome === 'indeterminate'
                    ? ('PayUni 回應未經簽章，無法確認實際結果'
                        . ($detail !== '' ? '（未驗證內容：' . $detail . '）' : '') . '。')
                    : ($detail !== '' ? $detail : 'PayUni 回應缺少可解讀內容，無法確認實際結果。'),
                'http_status' => $httpStatus,
                'signed'      => false,
            ];
        }

        if (!PayUniCrypto::verifyHash($encryptInfo, $hashInfo, $this->hashKey, $this->hashIv)) {
            return [
                'ok'          => false,
                'outcome'     => $unreadableOutcome,
                'data'        => [],
                'message'     => 'PayUni 回應簽章驗證失敗，無法確認實際結果，已拒絕採信其內容。',
                'http_status' => $httpStatus,
                'signed'      => false,
            ];
        }

        $decoded = PayUniCrypto::decryptToArray($encryptInfo, $this->hashKey, $this->hashIv);
        if ($decoded === null) {
            return [
                'ok'          => false,
                'outcome'     => $unreadableOutcome,
                'data'        => [],
                'message'     => 'PayUni 回應解密失敗，無法確認實際結果。',
                'http_status' => $httpStatus,
                'signed'      => false,
            ];
        }

        // 🔴 Status 只取解密後的內容。原本會 fallback 到 $payload['Status']（外層未簽章欄位），
        // 等於讓未經驗章的值決定成敗判定 —— 驗章通過的意義就被繞過了。
        $status  = strtoupper(trim((string) ($decoded['Status'] ?? '')));
        $message = trim((string) ($decoded['Message'] ?? ''));
        $ok      = $status === 'SUCCESS';

        return [
            'ok'          => $ok,
            'outcome'     => $ok ? 'success' : 'rejected_terminal',
            'data'        => $decoded,
            'message'     => $message !== '' ? $message : ($ok ? '操作成功。' : 'PayUni 回報失敗。'),
            'http_status' => $httpStatus,
            'signed'      => true,
        ];
    }

    /**
     * @return array{ok: bool, outcome: string, data: array<string,mixed>, message: string}
     */
    private function fail(string $message): array
    {
        return ['ok' => false, 'outcome' => 'rejected_terminal', 'data' => [], 'message' => $message];
    }

    /** @param array<string, mixed> $entry */
    private function log(array $entry): void
    {
        if ($this->logger !== null) {
            ($this->logger)($entry);
        }
    }
}
