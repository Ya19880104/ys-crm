<?php

declare(strict_types=1);

namespace YangSheep\CRM\Payment;

use YangSheep\CRM\Payment\Providers\PayUniProvider;
use YangSheep\CRM\Payment\Providers\SandboxProvider;
use YangSheep\CRM\Payment\Providers\ShoplineProvider;
use YangSheep\CRM\Setting\SettingService;

/**
 * 金流商註冊表（對應架構設計 §7.9）。
 *
 * 依設定 payment.payment_provider 解析具體 provider 實例。
 * Zero Trust / fail-safe：解析不到（未設定或未知值）一律 fallback 到 sandbox，
 * 確保系統永遠有可用的 provider（sandbox 不接觸真實金錢），不會因設定缺漏而崩潰。
 *
 * 依 key() 解析（payment 列上的 provider 欄位），確保回呼時以「當初發動付款的 provider」
 * 驗章，而非當下系統設定的 provider（避免切換 provider 後既有 pending 付款驗章錯亂）。
 */
final class PaymentProviderRegistry
{
    private SettingService $settings;

    /** @var array<string, PaymentProviderInterface>|null 已建立的 provider 實例快取。 */
    private ?array $instances = null;

    /** @var list<PaymentProviderInterface> 額外註冊的 provider（測試用接縫）。 */
    private array $extraProviders;

    /**
     * @param list<PaymentProviderInterface> $extraProviders
     *        額外註冊的 provider。**production 一律不傳**；存在的唯一目的是讓測試能
     *        注入一個「會退款、且可控制回傳 outcome」的假 provider，以便驗證
     *        PaymentService::refund() 的併發與落盤路徑。
     *        真實的三個 provider 不受影響，同 key 者以額外註冊的為準。
     */
    public function __construct(?SettingService $settings = null, array $extraProviders = [])
    {
        $this->settings       = $settings ?? new SettingService();
        $this->extraProviders = $extraProviders;
    }

    /**
     * 取得目前設定選用的 provider（payment.payment_provider）。
     * 未設定或未知 → sandbox。
     */
    public function resolve(): PaymentProviderInterface
    {
        $key = (string) ($this->settings->get('payment', 'payment_provider') ?? 'sandbox');
        return $this->get($key);
    }

    /**
     * 依 provider key 取得實例（回呼入帳時用 payment.provider 解析）。
     * 未知 key → sandbox（fail-safe）。
     */
    public function get(string $key): PaymentProviderInterface
    {
        $all = $this->all();
        return $all[$key] ?? $all['sandbox'];
    }

    /**
     * 是否為已知 provider key。
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 所有已註冊 provider（key => 實例）。
     *
     * @return array<string, PaymentProviderInterface>
     */
    public function all(): array
    {
        if ($this->instances === null) {
            $providers = [
                new SandboxProvider(),
                new PayUniProvider($this->settings),
                new ShoplineProvider($this->settings),
            ];
            $this->instances = [];
            foreach ([...$providers, ...$this->extraProviders] as $provider) {
                $this->instances[$provider->key()] = $provider;
            }
        }
        return $this->instances;
    }
}
