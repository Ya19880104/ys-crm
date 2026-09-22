<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Encryption;

/**
 * 客戶儲存卡片領域服務。
 *
 * 🔴 PCI / Zero Trust：本服務「不接收、不儲存」完整卡號（PAN）或 CVV。
 *    僅接收顯示用欄位（brand / last4 / exp_month / exp_year / label）+ provider 名稱，
 *    並把 provider 託管 token 以 AES-256-GCM 加密後存於 token_ref。
 *
 * 真實 tokenization 留接點：
 *    現階段 provider vault（PayUni/Shopline tokenize）尚未整合，addCard() 以佔位 token
 *    （PLACEHOLDER-...）的密文存入，僅供流程串接與顯示。待金流 vault 上線後，將前端
 *    （provider iframe / 託管欄位）回傳的真實 token 傳入 $providerToken 即可，無需改 schema。
 *    RecurringService::autoChargeDue 之 chargeWithToken 屆時以 decryptToken() 取回真實 token。
 *
 * 預設卡規則：同客戶至多一張預設；設新預設前清掉舊預設（單一交易內）。
 */
class CustomerPaymentMethodService
{
    private CustomerPaymentMethodRepository $repo;
    private Database $db;
    private ?Encryption $crypto;

    /** 允許的卡別品牌白名單（顯示用，deny-by-default）。 */
    private const ALLOWED_BRANDS = ['VISA', 'Mastercard', 'JCB', 'AMEX', 'UnionPay', 'Unknown'];

    public function __construct(?Encryption $crypto = null)
    {
        $this->repo = new CustomerPaymentMethodRepository();
        $this->db   = Database::getInstance();
        // 加密器延遲建立（測試環境 APP_KEY 已於 bootstrap 設定）。
        $this->crypto = $crypto;
    }

    private function crypto(): Encryption
    {
        return $this->crypto ??= new Encryption();
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * 列出某客戶的有效卡片（不含 token）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForCustomer(int $customerId): array
    {
        return $this->repo->findActiveByCustomer($customerId);
    }

    /**
     * 某客戶有效卡片數。
     */
    public function countForCustomer(int $customerId): int
    {
        return $this->repo->countActiveByCustomer($customerId);
    }

    /**
     * 取某客戶預設卡（供 recurring 引用；含 token_ref 密文）。
     */
    public function getDefaultForCustomer(int $customerId): ?array
    {
        return $this->repo->findDefaultByCustomer($customerId);
    }

    // ───────────────────────── 新增 / 刪除 / 設預設 ─────────────────────────

    /**
     * 新增一張卡片（綁 customer_id）。
     *
     * @param int                  $customerId     所屬客戶（取自 session，非前端）
     * @param int                  $customerUserId 建立者 portal 帳號 id
     * @param array{
     *     provider?: string, brand?: string, last4?: string,
     *     exp_month?: int|string|null, exp_year?: int|string|null, label?: string,
     *     is_default?: bool, provider_token?: string|null
     * } $input
     * @return int 新卡片 id
     * @throws \RuntimeException 欄位不合法
     */
    public function addCard(int $customerId, int $customerUserId, array $input): int
    {
        $brand = (string) ($input['brand'] ?? 'Unknown');
        if (!in_array($brand, self::ALLOWED_BRANDS, true)) {
            $brand = 'Unknown';
        }

        // last4：僅接受 4 位數字（這不是完整卡號，是顯示用末四碼）。
        $last4 = preg_replace('/\D/', '', (string) ($input['last4'] ?? ''));
        if (strlen($last4) !== 4) {
            throw new \RuntimeException('卡號末四碼需為 4 位數字。');
        }

        $expMonth = $this->normalizeMonth($input['exp_month'] ?? null);
        $expYear  = $this->normalizeYear($input['exp_year'] ?? null);

        $provider = (string) ($input['provider'] ?? 'sandbox');
        $label    = mb_substr(trim((string) ($input['label'] ?? '')), 0, 100);

        // 真實 token 留接點：未提供則產生佔位 token，再加密儲存（永不明碼）。
        $providerToken = (string) ($input['provider_token'] ?? '');
        if ($providerToken === '') {
            $providerToken = 'PLACEHOLDER-' . bin2hex(random_bytes(16));
        }
        $tokenRef = $this->crypto()->encrypt($providerToken);

        $makeDefault = !empty($input['is_default']);
        // 若客戶目前沒有任何有效卡，新卡自動設為預設（避免無預設卡）。
        if (!$makeDefault && $this->repo->countActiveByCustomer($customerId) === 0) {
            $makeDefault = true;
        }

        return $this->db->transaction(function () use (
            $customerId, $customerUserId, $provider, $brand, $last4, $expMonth, $expYear, $label, $tokenRef, $makeDefault
        ): int {
            if ($makeDefault) {
                $this->repo->clearDefaultForCustomer($customerId);
            }
            return $this->repo->insert([
                'customer_id'      => $customerId,
                'customer_user_id' => $customerUserId,
                'provider'         => $provider,
                'brand'            => $brand,
                'last4'            => $last4,
                'exp_month'        => $expMonth,
                'exp_year'         => $expYear,
                'token_ref'        => $tokenRef,
                'label'            => $label,
                'is_default'       => $makeDefault ? 1 : 0,
            ]);
        });
    }

    /**
     * 設某卡為預設（綁 customer_id）。
     *
     * @throws \RuntimeException 卡片不存在/不屬此客戶/已停用
     */
    public function setDefault(int $cardId, int $customerId): void
    {
        $card = $this->repo->findByIdForCustomer($cardId, $customerId);
        if ($card === null || (int) ($card['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('找不到該卡片。');
        }
        $this->db->transaction(function () use ($cardId, $customerId): void {
            $this->repo->clearDefaultForCustomer($customerId);
            $this->repo->setDefaultForCustomer($cardId, $customerId);
        });
    }

    /**
     * 刪除（軟刪除）某卡（綁 customer_id）。
     * 若刪的是預設卡，刪後將剩餘最新一張有效卡設為預設（若有）。
     *
     * @throws \RuntimeException 卡片不存在/不屬此客戶
     */
    public function deleteCard(int $cardId, int $customerId): void
    {
        $card = $this->repo->findByIdForCustomer($cardId, $customerId);
        if ($card === null) {
            throw new \RuntimeException('找不到該卡片。');
        }
        if ((int) ($card['is_active'] ?? 0) !== 1) {
            return; // 已刪除（冪等）。
        }

        $wasDefault = (int) ($card['is_default'] ?? 0) === 1;

        $this->db->transaction(function () use ($cardId, $customerId, $wasDefault): void {
            $this->repo->softDeleteForCustomer($cardId, $customerId);

            if ($wasDefault) {
                // 找剩餘最新有效卡設為預設。
                $remaining = $this->repo->findActiveByCustomer($customerId);
                if ($remaining !== []) {
                    $this->repo->setDefaultForCustomer((int) $remaining[0]['id'], $customerId);
                }
            }
        });
    }

    /**
     * 解出某卡的 provider token（供 recurring 自動扣款接點；綁 customer_id）。
     * 回 null 表示卡片不存在/已停用/token 損壞。
     */
    public function decryptToken(int $cardId, int $customerId): ?string
    {
        $card = $this->repo->findByIdForCustomer($cardId, $customerId);
        if ($card === null || (int) ($card['is_active'] ?? 0) !== 1) {
            return null;
        }
        $ref = (string) ($card['token_ref'] ?? '');
        if ($ref === '') {
            return null;
        }
        try {
            return $this->crypto()->decrypt($ref);
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────────── 內部 ─────────────────────────

    private function normalizeMonth(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $m = (int) $value;
        return ($m >= 1 && $m <= 12) ? $m : null;
    }

    private function normalizeYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $y = (int) $value;
        $current = (int) date('Y');
        // 接受兩位數年（25 → 2025）或四位數年；範圍 current ~ current+20。
        if ($y < 100) {
            $y += 2000;
        }
        return ($y >= $current && $y <= $current + 20) ? $y : null;
    }
}
