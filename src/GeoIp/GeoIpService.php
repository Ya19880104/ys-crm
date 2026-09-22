<?php

declare(strict_types=1);

namespace YangSheep\CRM\GeoIp;

use YangSheep\CRM\Core\Cache;
use YangSheep\CRM\Core\HttpClient;
use YangSheep\CRM\Setting\SettingService;

/**
 * IP → 地理位置。
 *
 * 【預設關閉，而且是刻意的】
 * 查詢地理位置一定要把使用者的 IP 送到第三方服務。那是把自家的存取紀錄
 * 外送給別人，屬於隱私決定而不是技術決定，必須由人明確開啟。
 * 未啟用時本服務回傳「未查詢」，不做任何對外連線。
 *
 * 【三個硬性行為】
 *   1. **fail-soft**：查詢失敗、逾時、格式改變一律回傳「未知」。
 *      地理位置是輔助資訊，絕不可以讓它影響登入紀錄能不能顯示。
 *   2. **私有／保留位址不外送**：127.x、10.x、192.168.x、::1 這類直接判定為
 *      內部位址。送出去既沒有意義，也等於把內網結構透露給第三方。
 *   3. **結果快取**：同一個 IP 在保留期內只查一次。登入紀錄頁一次會列 30 筆，
 *      不快取的話每次翻頁都會打 30 次外部 API。
 */
final class GeoIpService
{
    /** 快取天數：IP 的歸屬變動很慢，30 天足夠且能大幅降低外部查詢量 */
    private const CACHE_DAYS = 30;

    /**
     * 單次請求最多做幾次「真的連出去」的查詢。
     *
     * 🔴 【為什麼要有上限】稽核紀錄一頁 30 筆，每一筆的 IP 都不同、且都沒有快取時，
     * describe() 會連續發 30 次同步 HTTP。單次 timeout 是 4 秒 ——
     * 最壞情況這一頁要載 120 秒，而使用者只會看到瀏覽器一直轉，
     * 完全不知道是「查地理位置」把整頁卡住的。
     *
     * 這種頁面沒有任何一項功能依賴地理位置：它是輔助資訊。
     * 所以超出額度的就顯示「未查詢」，讓頁面先出來 ——
     * 那些 IP 會在下一次瀏覽（或下一頁）補上，因為前面查過的都已進快取。
     *
     * 快取命中不計入額度，所以第二次載入同一頁通常一次外連都不需要。
     */
    private const MAX_LIVE_LOOKUPS_PER_REQUEST = 8;

    /** 本次請求已經做了幾次外連查詢。 */
    private int $liveLookups = 0;

    private SettingService $settings;
    private HttpClient $http;
    private Cache $cache;

    public function __construct(?SettingService $settings = null, ?HttpClient $http = null, ?Cache $cache = null)
    {
        $this->settings = $settings ?? new SettingService();
        $this->http     = $http ?? new HttpClient();
        $this->cache    = $cache ?? new Cache();
    }

    /**
     * 是否已啟用地理位置查詢（系統設定 → 安全性）。
     */
    public function isEnabled(): bool
    {
        return (string) ($this->settings->get('security', 'geoip_enabled') ?? '0') === '1';
    }

    /**
     * 描述一個 IP 的來源。永遠回傳可直接顯示的結構，不丟例外。
     *
     * @return array{kind: string, label: string, detail: string}
     *         kind: private | unknown | disabled | located | deferred
     */
    public function describe(string $ip): array
    {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['kind' => 'unknown', 'label' => '—', 'detail' => ''];
        }

        if ($this->isPrivate($ip)) {
            return ['kind' => 'private', 'label' => '內部位址', 'detail' => '反向代理或內網，非訪客來源'];
        }

        if (!$this->isEnabled()) {
            return ['kind' => 'disabled', 'label' => '未查詢', 'detail' => '地理位置查詢未啟用'];
        }

        // Cache 只存字串，故以 JSON 序列化。解不開就當沒有快取重查一次，
        // 不讓一筆壞掉的快取值變成永久的錯誤顯示。
        $cacheKey = 'geoip:' . $ip;
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['kind'], $decoded['label'], $decoded['detail'])) {
                return $decoded;
            }
        }

        // 快取沒中才要外連 —— 這裡才計入額度，額度用完就先不查。
        if ($this->liveLookups >= self::MAX_LIVE_LOOKUPS_PER_REQUEST) {
            return [
                'kind'   => 'deferred',
                'label'  => '未查詢',
                'detail' => '本頁查詢次數已達上限，稍後重新整理即可補上',
            ];
        }

        $this->liveLookups++;

        $result = $this->lookup($ip);
        $this->cache->set(
            $cacheKey,
            (string) json_encode($result, JSON_UNESCAPED_UNICODE),
            self::CACHE_DAYS * 86400
        );

        return $result;
    }

    /**
     * 實際查詢。任何非預期狀況一律回「未知」，不讓外部服務影響本頁。
     *
     * @return array{kind: string, label: string, detail: string}
     */
    private function lookup(string $ip): array
    {
        $endpoint = trim((string) ($this->settings->get('security', 'geoip_endpoint') ?? ''));
        if ($endpoint === '') {
            return ['kind' => 'unknown', 'label' => '未知', 'detail' => '未設定查詢服務'];
        }

        // 端點以 {ip} 佔位，讓不同服務都能設定而不必改程式。
        $url = str_replace('{ip}', rawurlencode($ip), $endpoint);
        if (!str_starts_with($url, 'https://')) {
            // 不走明文：查詢內容本身就是「誰從哪裡登入」。
            return ['kind' => 'unknown', 'label' => '未知', 'detail' => '查詢端點必須是 https'];
        }

        $res = $this->http->request('GET', $url, ['timeout' => 4]);
        if ((int) ($res['status'] ?? 0) !== 200) {
            return ['kind' => 'unknown', 'label' => '未知', 'detail' => '查詢失敗'];
        }

        $data = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($data)) {
            return ['kind' => 'unknown', 'label' => '未知', 'detail' => '回應格式無法解讀'];
        }

        // 常見服務的欄位名不一致，逐一嘗試；取不到就當未知，不猜。
        $country = $this->firstNonEmpty($data, ['country_name', 'country', 'countryCode', 'country_code']);
        $city    = $this->firstNonEmpty($data, ['city', 'region', 'regionName', 'region_name']);
        $org     = $this->firstNonEmpty($data, ['org', 'isp', 'asn_org', 'connection_org']);

        if ($country === '' && $city === '') {
            return ['kind' => 'unknown', 'label' => '未知', 'detail' => ''];
        }

        $label  = trim($country . ($city !== '' ? ' · ' . $city : ''));
        $detail = $org;

        return ['kind' => 'located', 'label' => $label !== '' ? $label : '未知', 'detail' => $detail];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function firstNonEmpty(array $data, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $data[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return '';
    }

    /**
     * 私有／保留位址（不外送查詢）。
     */
    private function isPrivate(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
