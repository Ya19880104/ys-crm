<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Setting\SettingService;

/**
 * 電子發票設定讀取層（群組 einvoice）。
 *
 * JWT token 以 SettingService 的 encrypted 旗標存放（AES-256-GCM at-rest）。
 *
 * 【環境切換必須清 token】測試環境與正式環境的 JWT 不通用，若切換環境卻沿用舊 token，
 * 會得到難以理解的 401/403。故 environment 變更時由 SettingController 主動清空 token，
 * 強迫重新填寫——寧可多一次輸入，也不要讓人以為「設定看起來是對的」卻永遠打不通。
 */
class InvoiceSettings
{
    public const GROUP = 'einvoice';

    private SettingService $settings;

    public function __construct(?SettingService $settings = null)
    {
        $this->settings = $settings ?? new SettingService();
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->settings->getGroup(self::GROUP);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->settings->get(self::GROUP, $key);
        return $value === null || $value === '' ? $default : $value;
    }

    /** 發票模組總開關。關閉時完全不建立發票列、不呼叫 API。 */
    public function enabled(): bool
    {
        return $this->get('einvoice_enabled', '0') === '1';
    }

    /** 付款成功後是否自動開立（關閉則只建列，等後台手動開）。 */
    public function autoIssueEnabled(): bool
    {
        return $this->enabled() && $this->get('einvoice_auto_issue', '0') === '1';
    }

    public function environment(): string
    {
        return $this->get('einvoice_environment', 'test') === 'production' ? 'production' : 'test';
    }

    public function token(): string
    {
        return $this->get('einvoice_token');
    }

    public function sellerTaxId(): string
    {
        return preg_replace('/\D/', '', $this->get('einvoice_seller_tax_id'));
    }

    public function baseUrl(): string
    {
        $custom = $this->get('einvoice_base_url');
        return $custom !== '' ? $custom : PayNowRestClient::defaultBaseUrl($this->environment());
    }

    /** 稅別預設值：1=應稅 2=零稅率 3=免稅 */
    public function defaultTaxType(): string
    {
        return $this->get('einvoice_default_tax_type', '1');
    }

    public function zeroTaxRateReason(): string
    {
        return $this->get('einvoice_zero_tax_rate_reason', 'None');
    }

    /** 每分鐘允許的 API 呼叫上限（出網限流）。 */
    public function rateLimitPerMinute(): int
    {
        return max(1, (int) $this->get('einvoice_rate_limit', '50'));
    }

    /** 連續失敗幾次後熔斷。 */
    public function circuitFailureThreshold(): int
    {
        return max(1, (int) $this->get('einvoice_circuit_threshold', '5'));
    }

    /** 熔斷後暫停幾分鐘。 */
    public function circuitPauseMinutes(): int
    {
        return max(1, (int) $this->get('einvoice_circuit_pause_minutes', '10'));
    }

    /**
     * API log 保留天數。
     *
     * log 裡有完整的 request/response（JWT 已遮蔽），是診斷 422 的唯一依據，
     * 但也是最會膨脹的一張表。預設 90 天：夠涵蓋「上個月的發票出了什麼問題」，
     * 又不會無限累積。最低 7 天 —— 再短就失去診斷價值。
     */
    public function logRetentionDays(): int
    {
        return max(7, (int) $this->get('einvoice_log_retention_days', '90'));
    }

    /**
     * 設定是否已足以呼叫 API。
     */
    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * 供 InvoicePayloadBuilder 使用的設定切片。
     *
     * @return array<string,string>
     */
    public function payloadSettings(): array
    {
        return [
            'default_tax_type'     => $this->defaultTaxType(),
            'zero_tax_rate_reason' => $this->zeroTaxRateReason(),
        ];
    }
}
