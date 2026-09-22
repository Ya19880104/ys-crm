<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Customer\CustomerRepository;
use YangSheep\CRM\Setting\SettingService;

/**
 * 報價單領域服務層 — 系統核心。
 *
 * 職責：
 * - 報價 CRUD（含明細）於單一交易內完成，保證主檔與明細一致。
 * - 金額一律伺服器端重算（subtotal/tax/total），絕不信任前端傳入的總計。
 * - 產生 quote_number（Q-YYYY-NNNN，交易內鎖定當年序號避免併發重號）。
 * - 產生 access_token（隨機 64 hex、唯一）。
 * - 狀態機轉移（draft→sent→viewed→signed→paid→expired/void）。
 * - 公開頁存取控制判斷（依 visibility）。
 * - 記錄瀏覽（首次被檢視且 status=sent 時自動轉 viewed）。
 * - 處理簽署（簽名圖驗證+存檔、印章快照、document_hash、status→signed、our_seal_applied=1）。
 *
 * 明確排除（留接點 + 註解，不硬做）：
 * - 伺服器端 PDF（mPDF）：公開頁先用瀏覽器列印（window.print + 列印 CSS）。
 * - 金流付款導引：payment_enabled 僅存旗標，導引留 P4-5。
 * - 週期性帳務：留 P4-6。
 *
 * customer_only 強制（P4-2 起已實作）：checkAccess() 對 visibility=customer_only 要求
 * 「客戶已登入 + 登入客戶 customer_id == quote.customer_id」，否則導 portal 登入 / 回 404。
 *
 * 對應架構設計 §5.5、§7.8。
 */
class QuoteService
{
    private QuoteRepository $quotes;
    private QuoteItemRepository $items;
    private QuoteViewRepository $views;
    private QuoteSignatureRepository $signatures;
    private CustomerRepository $customers;
    private SettingService $settings;
    private Database $db;

    /** 匿名分享期限判斷用的時鐘（UTC epoch 秒）；測試以 setClock() 注入固定時間。 */
    private \Closure $clock;

    /** 合法狀態值。 */
    public const STATUSES = ['draft', 'sent', 'viewed', 'signed', 'paid', 'expired', 'void'];

    /** 合法可見性值。 */
    public const VISIBILITIES = ['private', 'public', 'password', 'customer_only'];

    /**
     * 狀態機允許的轉移（from => [允許的 to...]）。
     * 簽署/付款由系統事件觸發（簽署→signed、付款→paid），亦列於對應 from 的合法集合。
     * void/expired 為終態方向，可由多數狀態進入。
     */
    private const TRANSITIONS = [
        'draft'   => ['sent', 'void'],
        'sent'    => ['viewed', 'signed', 'paid', 'expired', 'void', 'draft'],
        'viewed'  => ['signed', 'paid', 'expired', 'void', 'sent'],
        'signed'  => ['paid', 'void', 'expired'],
        'paid'    => ['void'],
        'expired' => ['draft', 'sent', 'void'],
        'void'    => [],
    ];

    /** 簽名 PNG 上限（2MB，base64 解碼後位元組）。 */
    private const MAX_SIGNATURE_BYTES = 2 * 1024 * 1024;

    public function __construct()
    {
        $this->quotes     = new QuoteRepository();
        $this->items      = new QuoteItemRepository();
        $this->views      = new QuoteViewRepository();
        $this->signatures = new QuoteSignatureRepository();
        $this->customers  = new CustomerRepository();
        $this->settings   = new SettingService();
        $this->db         = Database::getInstance();
        $this->clock      = static fn(): int => time();
    }

    /**
     * 注入時鐘（測試到期邊界用；正式執行一律是 time()）。
     *
     * @param \Closure(): int $clock
     */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    // ───────────────────────── 查詢 ─────────────────────────

    /**
     * 分頁查詢報價單列表。
     *
     * @param array{status?: string, customer_id?: int, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15, array $filters = []): array
    {
        return $this->quotes->findAll($page, $perPage, $filters);
    }

    /**
     * 取得單一報價單（僅主檔，含客戶/建立者名）。
     */
    public function findById(int $id): ?array
    {
        return $this->quotes->findById($id);
    }

    /**
     * 取得單一報價單完整詳情（主檔 + 明細）。
     *
     * @return array<string, mixed>|null
     */
    public function getQuoteDetail(int $id): ?array
    {
        $quote = $this->quotes->findById($id);
        if ($quote === null) {
            return null;
        }
        $quote['items'] = $this->items->findByQuote($id);
        return $quote;
    }

    /**
     * 取得後台檢視頁所需的完整資料（主檔 + 明細 + 瀏覽軌跡 + 簽署）。
     *
     * @return array<string, mixed>|null
     */
    public function getQuoteForAdmin(int $id): ?array
    {
        $quote = $this->getQuoteDetail($id);
        if ($quote === null) {
            return null;
        }
        $quote['views']      = $this->views->findByQuote($id);
        $quote['view_count'] = $this->views->countByQuote($id);
        $quote['signatures'] = $this->signatures->findByQuote($id);
        return $quote;
    }

    /**
     * 取得指定客戶的報價單（客戶內頁「報價單」tab）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->quotes->findByCustomer($customerId);
    }

    // ───────────────────────── 報價 CRUD ─────────────────────────

    /**
     * 建立報價單（含明細）於單一交易內。
     * - 產生 quote_number + access_token。
     * - 伺服器端重算金額。
     * - visibility=password 時雜湊密碼。
     * - status：依是否「送出」決定 draft / sent（送出寫 sent_at）。
     *
     * @param array<string, mixed>              $data   報價主檔輸入（已由 Controller 蒐集/清洗）
     * @param array<int, array<string, mixed>>  $items  明細輸入（name/qty/unit/unit_price/description）
     * @return int 新報價單 ID
     */
    public function createQuote(array $data, array $items): int
    {
        $customerId = $this->resolveCustomerId($data['customer_id'] ?? null);
        $visibility = $this->normalizeVisibility($data['visibility'] ?? 'private');
        $normalizedItems = $this->normalizeItems($items);
        $totals = $this->calculateTotals($normalizedItems, (float) ($data['tax_rate'] ?? 0));

        // 匿名分享期限：第一次公開即起算（draft 也算，因為連結當下就能被存取）。
        // 驗證失敗（天數、無期限確認）在寫入任何資料前就拋出。
        $sharePlan = QuoteSharePolicy::planChange(
            [],
            $visibility,
            is_array($data['share'] ?? null) ? $data['share'] : [],
            $this->now()
        );

        return $this->db->transaction(function () use ($data, $customerId, $visibility, $normalizedItems, $totals, $sharePlan): int {
            $quoteNumber = $this->generateQuoteNumber();
            $accessToken = $this->generateUniqueToken();

            $sendNow = !empty($data['send_now']);

            $payload = $sharePlan['fields'] + [
                'share_policy_revision' => $sharePlan['event'] === QuoteSharePolicy::EVENT_NONE ? 0 : 1,
                'quote_number'         => $quoteNumber,
                'customer_id'          => $customerId,
                'title'                => $data['title'],
                'status'               => $sendNow ? 'sent' : 'draft',
                'visibility'           => $visibility,
                'access_token'         => $accessToken,
                'access_password_hash' => $this->resolvePasswordHash($visibility, $data['access_password'] ?? null, null),
                'subtotal'             => $totals['subtotal'],
                'tax_rate'             => $totals['tax_rate'],
                'tax'                  => $totals['tax'],
                'total'                => $totals['total'],
                'currency'             => 'TWD',
                'valid_until'          => $data['valid_until'] ?? null,
                'terms'                => (string) ($data['terms'] ?? ''),
                'notes'                => (string) ($data['notes'] ?? ''),
                'payment_enabled'      => !empty($data['payment_enabled']) ? 1 : 0,
                'payment_status'       => 'unpaid',
                'our_seal_applied'     => 0,
                'related_job_id'       => $data['related_job_id'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
                'sent_at'              => $sendNow ? date('Y-m-d H:i:s') : null,
            ];

            $quoteId = $this->quotes->insert($payload);
            $this->items->replaceForQuote($quoteId, $normalizedItems);

            return $quoteId;
        });
    }

    /**
     * 更新報價單（含明細）於單一交易內。
     * - 已簽署的報價不允許再改內容（保護存證一致性）。
     * - 重算金額；visibility=password 時處理密碼（留空表示沿用既有雜湊）。
     * - quote_number 不變；access_token 只在「明確重新公開」已關閉／過期的分享時更換。
     * - 匿名分享期限依 QuoteSharePolicy::planChange 於交易內（鎖定該列後）決定，
     *   避免與並行的「立即關閉」交錯而把已關閉的連結寫回有效。
     *
     * @param array<string, mixed>              $data
     * @param array<int, array<string, mixed>>  $items
     * @return string 分享事件（QuoteSharePolicy::EVENT_*），供呼叫端稽核
     * @throws QuoteShareValidationException 分享設定不合法或缺少必要確認
     */
    public function updateQuote(int $id, array $data, array $items): string
    {
        $existing = $this->quotes->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('報價單不存在。');
        }
        if (in_array($existing['status'], ['signed', 'paid'], true)) {
            throw new \RuntimeException('已簽署或已付款的報價單不可再編輯內容。');
        }

        $customerId = $this->resolveCustomerId($data['customer_id'] ?? null);
        $visibility = $this->normalizeVisibility($data['visibility'] ?? $existing['visibility']);
        $normalizedItems = $this->normalizeItems($items);
        $totals = $this->calculateTotals($normalizedItems, (float) ($data['tax_rate'] ?? 0));

        $shareInput = is_array($data['share'] ?? null) ? $data['share'] : [];
        $now        = $this->now();

        return $this->db->transaction(function () use ($id, $data, $customerId, $visibility, $normalizedItems, $totals, $shareInput, $now): string {
            $locked = $this->quotes->findShareStateForUpdate($id);
            if ($locked === null) {
                throw new \RuntimeException('報價單不存在。');
            }

            $sharePlan = QuoteSharePolicy::planChange($locked, $visibility, $shareInput, $now);

            $update = $sharePlan['fields'] + [
                'customer_id'          => $customerId,
                'title'                => $data['title'],
                'visibility'           => $visibility,
                'access_password_hash' => $this->resolvePasswordHash(
                    $visibility,
                    $data['access_password'] ?? null,
                    $locked['access_password_hash'] ?? null
                ),
                'subtotal'             => $totals['subtotal'],
                'tax_rate'             => $totals['tax_rate'],
                'tax'                  => $totals['tax'],
                'total'                => $totals['total'],
                'valid_until'          => $data['valid_until'] ?? null,
                'terms'                => (string) ($data['terms'] ?? ''),
                'notes'                => (string) ($data['notes'] ?? ''),
                'payment_enabled'      => !empty($data['payment_enabled']) ? 1 : 0,
            ];

            // 重新公開：換新 token，舊連結（與綁定舊 token 的密碼解鎖）永久失效。
            if ($sharePlan['rotate_token']) {
                $update['access_token'] = $this->generateUniqueToken();
            }

            $this->quotes->update($id, $update);
            if (self::revisionFieldsChanged($locked, $update)) {
                $this->quotes->incrementShareRevision($id);
            }
            $this->items->replaceForQuote($id, $normalizedItems);

            return $sharePlan['event'];
        });
    }

    /**
     * 分享授權相關欄位（可見性、token、密碼、期限）是否有任何變動。
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $update
     */
    private static function revisionFieldsChanged(array $before, array $update): bool
    {
        foreach (QuoteSharePolicy::REVISION_FIELDS as $field) {
            if (!array_key_exists($field, $update)) {
                continue;
            }
            if ((string) ($before[$field] ?? '') !== (string) ($update[$field] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 刪除報價單（明細/軌跡/簽署由 FK CASCADE 連帶刪除）。
     */
    public function deleteQuote(int $id): void
    {
        $existing = $this->quotes->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('報價單不存在。');
        }
        $this->quotes->delete($id);
    }

    // ───────────────────────── 狀態機 ─────────────────────────

    /**
     * 嘗試將報價單轉移到目標狀態（後台手動操作 / 系統事件共用）。
     * 依 TRANSITIONS 檢查合法性；非法則拋例外。
     * 轉入 sent 且原本無 sent_at 時補寫 sent_at。
     *
     * @param int    $id
     * @param string $to 目標狀態
     */
    public function transitionStatus(int $id, string $to): void
    {
        if (!in_array($to, self::STATUSES, true)) {
            throw new \RuntimeException('無效的狀態值。');
        }

        $quote = $this->quotes->findById($id);
        if ($quote === null) {
            throw new \RuntimeException('報價單不存在。');
        }

        $from = (string) $quote['status'];
        if ($from === $to) {
            return; // 無變化
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new \RuntimeException("無法從「{$from}」轉移到「{$to}」狀態。");
        }

        $update = ['status' => $to];
        if ($to === 'sent' && ($quote['sent_at'] ?? null) === null) {
            $update['sent_at'] = date('Y-m-d H:i:s');
        }

        $this->quotes->update($id, $update);
    }

    /**
     * 送出報價單（draft/expired → sent）。便捷封裝 transitionStatus。
     */
    public function sendQuote(int $id): void
    {
        $quote = $this->quotes->findById($id);
        if ($quote === null) {
            throw new \RuntimeException('報價單不存在。');
        }
        if (in_array($quote['status'], ['signed', 'paid', 'void'], true)) {
            throw new \RuntimeException('此報價單目前狀態無法送出。');
        }
        $this->transitionStatus($id, 'sent');
    }

    // ───────────────────────── 公開頁存取控制 ─────────────────────────

    /**
     * 依 access_token 取得報價單（含明細），不做存取控制（由 checkAccess 判斷）。
     *
     * @return array<string, mixed>|null
     */
    public function getQuoteByToken(string $token): ?array
    {
        $quote = $this->quotes->findByToken($token);
        if ($quote === null) {
            return null;
        }
        $quote['items'] = $this->items->findByQuote((int) $quote['id']);

        // 已簽署 → 附最新一筆簽署資料，供公開頁/列印頁顯示客戶簽名圖 + 存證
        //（signature_image_path → signature_data 供 <img>；signed_ip 不外洩）。
        if (in_array($quote['status'] ?? '', ['signed', 'paid'], true)) {
            $sigs = $this->signatures->findByQuote((int) $quote['id']);
            if (!empty($sigs)) {
                $latest = $sigs[0]; // findByQuote 以 signed_at DESC 排序，[0] 為最新
                $quote['signature_data'] = (string) ($latest['signature_image_path'] ?? '');
                $quote['signer_name']    = (string) ($latest['signer_name'] ?? '');
                $quote['signed_at']      = (string) ($latest['signed_at'] ?? '');
                $quote['document_hash']  = (string) ($latest['document_hash'] ?? '');
            }
        }

        return $quote;
    }

    /**
     * 判斷公開頁存取結果（Zero Trust：預設拒絕、不信任前端）。
     *
     * 回傳關聯陣列：
     *   - allowed (bool)：是否可直接檢視報價內容
     *   - reason  (string)：'ok' | 'not_found' | 'private' | 'need_password' | 'need_login' | 'forbidden'
     *                      | 'share_closed'
     *   - via     (string)：'anonymous'（憑網址／密碼）| 'owner'（綁定客戶本人登入）| 'customer' | 'none'
     *                      ——提交端（簽署／付款）據此決定是否要在交易內重驗匿名分享期限。
     *
     * 規則（§5.5 visibility＋Q2 分享期限）：
     *   - private        → 一律拒絕（不可由 token 存取），reason=private（控制器回 404）。
     *   - public／password（匿名分享）：
     *       · 綁定客戶本人登入 → 允許（透過獨立的客戶身分授權，不受匿名分享期限影響）。
     *       · 分享已關閉、已過期或期限從未初始化 → share_closed（控制器回與查無相同的通用 404）。
     *         未初始化不得當成永久可看。
     *   - public         → 分享有效即允許。
     *   - password       → 分享有效且已於 session 解鎖該報價則允許；否則 need_password。
     *   - customer_only  → 🔴 P4-2 起真正強制：必須「客戶已登入」且「登入客戶之 customer_id
     *                       等於 quote.customer_id」。
     *                        · 未登入（$loggedInCustomerId 為 null/0）→ need_login（導 portal 登入，帶 return）。
     *                        · 已登入但 customer_id 不符 → forbidden（控制器回 404，不洩漏存在與否）。
     *                        · 已登入且相符 → 允許。
     *                        · 報價未綁客戶（customer_id 為 null）→ 任何人都不符 → forbidden（保守）。
     *
     * @param array<string, mixed> $quote              由 getQuoteByToken 取得的報價
     * @param bool                 $passwordUnlocked   該報價是否已在本 session 通過密碼
     * @param int|null             $loggedInCustomerId 目前登入客戶（CustomerGuard::customerId()）的 customer_id；
     *                                                  未登入傳 null 或 0。絕不可傳前端提供的值。
     * @return array{allowed: bool, reason: string}
     */
    public function checkAccess(array $quote, bool $passwordUnlocked, ?int $loggedInCustomerId = null): array
    {
        $visibility = (string) ($quote['visibility'] ?? 'private');

        if (QuoteSharePolicy::isAnonymous($visibility)) {
            $owner = (int) ($quote['customer_id'] ?? 0);
            if ($owner > 0 && (int) ($loggedInCustomerId ?? 0) === $owner) {
                return ['allowed' => true, 'reason' => 'ok', 'via' => 'owner'];
            }
            if (!QuoteSharePolicy::isActive($quote, $this->now())) {
                return ['allowed' => false, 'reason' => 'share_closed', 'via' => 'anonymous'];
            }
        }

        return match ($visibility) {
            'public'        => ['allowed' => true,  'reason' => 'ok', 'via' => 'anonymous'],
            'password'      => $passwordUnlocked
                                   ? ['allowed' => true,  'reason' => 'ok', 'via' => 'anonymous']
                                   : ['allowed' => false, 'reason' => 'need_password', 'via' => 'anonymous'],
            'customer_only' => $this->checkCustomerOnlyAccess($quote, $loggedInCustomerId) + ['via' => 'customer'],
            // private 與其他未知值 → 拒絕（deny-by-default）。
            default         => ['allowed' => false, 'reason' => 'private', 'via' => 'none'],
        };
    }

    /**
     * 匿名分享目前是否有效（供密碼解鎖入口：過期／關閉後不再接受密碼嘗試）。
     *
     * @param array<string, mixed> $quote
     */
    public function isAnonymousShareActive(array $quote): bool
    {
        return QuoteSharePolicy::isActive($quote, $this->now());
    }

    /**
     * 提交端（簽署／發動付款）在自己的交易內呼叫：鎖定報價列後重讀分享授權，
     * 不信任頁面開啟時的判斷。
     *
     * @param int  $expectedRevision 請求開始時看到的 share_policy_revision
     * @param bool $requireActive    是否經由匿名分享存取（owner／customer 不受匿名期限影響）
     * @throws QuoteShareClosedException  匿名分享已關閉／過期／已不是匿名分享
     * @throws QuoteShareChangedException 授權版本已變動
     */
    public function lockAndRecheckAnonymousShare(int $quoteId, int $expectedRevision, bool $requireActive): void
    {
        $row = $this->quotes->findShareStateForUpdate($quoteId);
        if ($row === null) {
            throw new QuoteShareClosedException('報價連結無法使用。');
        }
        // 🔴 先驗期限、後比版本：關閉本身也會讓版本 +1，若先比版本，「開頁後被關閉」會變成
        // 附帶 flash 的「設定已變更」，導回通用 404 時那段訊息就洩漏了連結曾經有效。
        if ($requireActive && !QuoteSharePolicy::isActive($row, $this->now())) {
            throw new QuoteShareClosedException('報價連結無法使用。');
        }
        if ((int) ($row['share_policy_revision'] ?? 0) !== $expectedRevision) {
            throw new QuoteShareChangedException('報價的分享設定已變更，請重新開啟連結後再操作。');
        }
    }

    /**
     * 立即關閉匿名分享（管理者手動）。已關閉或不是匿名分享時不做任何事。
     *
     * @return bool 本次是否實際關閉
     */
    public function closeShare(int $id): bool
    {
        $now = $this->now();

        return $this->db->transaction(function () use ($id, $now): bool {
            $row = $this->quotes->findShareStateForUpdate($id);
            if ($row === null) {
                throw new \RuntimeException('報價單不存在。');
            }
            if (!QuoteSharePolicy::isAnonymous((string) $row['visibility'])
                || QuoteSharePolicy::shareState($row, $now) === QuoteSharePolicy::STATE_CLOSED
            ) {
                return false;
            }

            $this->quotes->update($id, ['share_closed_at' => $now]);
            $this->quotes->incrementShareRevision($id);
            return true;
        });
    }

    /**
     * 只更新匿名分享期限（報價詳情頁）。
     *
     * 已簽署／已付款的報價不能再編輯內容，但「等客戶付款」正是那個階段——
     * 期限若在付款前到期，管理者必須仍能延長、改無期限、關閉或重新公開。
     * 可見性不變；規則與編輯整張報價完全相同（QuoteSharePolicy::planChange）。
     *
     * @param array<string, mixed> $shareInput
     * @return string 分享事件（QuoteSharePolicy::EVENT_*）
     * @throws QuoteShareValidationException
     */
    public function updateSharePolicy(int $id, array $shareInput): string
    {
        $now = $this->now();

        return $this->db->transaction(function () use ($id, $shareInput, $now): string {
            $locked = $this->quotes->findShareStateForUpdate($id);
            if ($locked === null) {
                throw new \RuntimeException('報價單不存在。');
            }
            $visibility = (string) $locked['visibility'];
            if (!QuoteSharePolicy::isAnonymous($visibility)) {
                throw new \RuntimeException('此報價不是公開連結（任何人可看／需密碼），沒有分享期限可設定。');
            }

            $plan   = QuoteSharePolicy::planChange($locked, $visibility, ['present' => true] + $shareInput, $now);
            $update = $plan['fields'];
            if ($plan['rotate_token']) {
                $update['access_token'] = $this->generateUniqueToken();
            }
            if ($update === []) {
                return $plan['event'];
            }

            $this->quotes->update($id, $update);
            if (self::revisionFieldsChanged($locked, $update)) {
                $this->quotes->incrementShareRevision($id);
            }
            return $plan['event'];
        });
    }

    /**
     * 管理畫面用的分享狀態投影（時間已轉成網站時區字串）。
     *
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    public function shareView(array $quote): array
    {
        $now   = $this->now();
        $fmt   = static fn($ts): string => $ts === null || $ts === '' ? '' : date('Y-m-d H:i', (int) $ts);
        $state = QuoteSharePolicy::state($quote, $now);

        $everShared = ($quote['share_enabled_at'] ?? null) !== null || ($quote['share_closed_at'] ?? null) !== null;

        return [
            'state'            => $state,
            'anonymous'        => QuoteSharePolicy::isAnonymous((string) ($quote['visibility'] ?? '')),
            'ever_shared'      => $everShared,
            // 曾分享過且目前無效：再次公開須明確確認並換新連結。
            'needs_reopen'     => $everShared && QuoteSharePolicy::shareState($quote, $now) !== QuoteSharePolicy::STATE_ACTIVE,
            'auto_expire'      => ($quote['share_auto_expire'] ?? null) === null ? true : (int) $quote['share_auto_expire'] === 1,
            'days'             => QuoteSharePolicy::normalizeDays((string) ($quote['share_duration_days'] ?? '')) ?? QuoteSharePolicy::DEFAULT_DAYS,
            'enabled_at'       => ($quote['share_enabled_at'] ?? null) === null ? null : (int) $quote['share_enabled_at'],
            'expires_at'       => ($quote['share_expires_at'] ?? null) === null ? null : (int) $quote['share_expires_at'],
            'enabled_at_label' => $fmt($quote['share_enabled_at'] ?? null),
            'expires_at_label' => $fmt($quote['share_expires_at'] ?? null),
            'closed_at_label'  => $fmt($quote['share_closed_at'] ?? null),
            'unlimited_acked'  => ($quote['share_unlimited_ack_at'] ?? null) !== null,
            'timezone'         => date_default_timezone_get(),
            'now'              => $now,
        ];
    }

    /**
     * customer_only 報價存取判斷（Zero Trust：登入 + 歸屬雙重）。
     *
     * @param array<string, mixed> $quote
     * @param int|null             $loggedInCustomerId
     * @return array{allowed: bool, reason: string}
     */
    private function checkCustomerOnlyAccess(array $quote, ?int $loggedInCustomerId): array
    {
        $loggedIn = (int) ($loggedInCustomerId ?? 0);

        // 未登入 → 需登入客戶專區（導 /portal/login，帶 return）。
        if ($loggedIn <= 0) {
            return ['allowed' => false, 'reason' => 'need_login'];
        }

        $quoteCustomerId = (int) ($quote['customer_id'] ?? 0);

        // 報價未綁客戶，或登入客戶與報價客戶不符 → forbidden（控制器回 404）。
        if ($quoteCustomerId <= 0 || $quoteCustomerId !== $loggedIn) {
            return ['allowed' => false, 'reason' => 'forbidden'];
        }

        // 已登入且為該客戶 → 允許。
        return ['allowed' => true, 'reason' => 'ok'];
    }

    /**
     * 驗證公開頁輸入的密碼是否正確（visibility=password）。
     * 使用 password_verify；非 password 模式或無雜湊一律回 false。
     */
    public function verifyAccessPassword(array $quote, string $input): bool
    {
        if (($quote['visibility'] ?? '') !== 'password') {
            return false;
        }
        $hash = (string) ($quote['access_password_hash'] ?? '');
        if ($hash === '' || $input === '') {
            return false;
        }
        return password_verify($input, $hash);
    }

    /**
     * 記錄一次公開頁瀏覽，並於「首次被檢視且 status=sent」時自動轉 viewed。
     *
     * @param array<string, mixed> $quote
     */
    public function recordView(array $quote, string $ip, string $userAgent, ?int $customerUserId = null): void
    {
        $quoteId = (int) $quote['id'];
        $this->views->record($quoteId, $ip, $userAgent, $customerUserId);

        // sent → viewed（首次被檢視）。已是 viewed/signed/paid 等則不動。
        if (($quote['status'] ?? '') === 'sent') {
            // 直接更新（避免 transitionStatus 對 sent→viewed 已涵蓋；此處保守只在 sent 時翻）
            $this->quotes->update($quoteId, ['status' => 'viewed']);
        }
    }

    // ───────────────────────── 簽署 ─────────────────────────

    /**
     * 處理公開頁線上簽署。
     * - 驗證簽署人姓名與簽名圖（限 PNG data URL、大小上限）。
     * - 簽名圖存 /uploads/quotes/。
     * - 取當下公司印章路徑作快照（our_seal_path）。
     * - 計算當下報價內容 sha256（document_hash）。
     * - 寫 quote_signatures；report status→signed、our_seal_applied=1（若印章存在）。
     * - 已簽署/作廢的報價拒絕重簽。
     *
     * @param array<string, mixed> $quote         由 getQuoteByToken 取得（含 items）
     * @param string               $signerName    簽署人姓名
     * @param string               $signatureData 簽名板輸出（data:image/png;base64,...）
     * @param string               $ip
     * @param string               $userAgent
     * @param string               $signerType    'guest'（公開）| 'customer'（portal，未來）
     * @param array{revision: int, require_active: bool}|null $shareGuard
     *        公開頁簽署時傳入：交易內鎖列重驗分享授權版本與匿名分享期限（不信任開頁時的判斷）
     * @return int 新簽署 ID
     * @throws QuoteShareClosedException|QuoteShareChangedException
     */
    public function signQuote(
        array $quote,
        string $signerName,
        string $signatureData,
        string $ip,
        string $userAgent,
        string $signerType = 'guest',
        ?array $shareGuard = null
    ): int {
        $quoteId = (int) $quote['id'];

        if (in_array($quote['status'] ?? '', ['signed', 'paid', 'void'], true)) {
            throw new \RuntimeException('此報價單已簽署或已結案，無法重複簽署。');
        }

        $signerName = trim($signerName);
        if ($signerName === '') {
            throw new \RuntimeException('請填寫簽署人姓名。');
        }
        if (mb_strlen($signerName) > 150) {
            throw new \RuntimeException('簽署人姓名過長。');
        }

        $signaturePath = $this->storeSignatureImage($signatureData);
        if ($signaturePath === null) {
            throw new \RuntimeException('簽名圖無效：請於簽名板簽名後再送出（限 PNG，且不超過 2MB）。');
        }

        // 公司印章路徑快照（可能未設定 → null）
        $sealPath = $this->settings->get('company', 'company_seal');
        $sealPath = ($sealPath !== null && $sealPath !== '') ? $sealPath : null;

        // 內容雜湊（不可竄改快照）：以報價主檔關鍵欄位 + 明細組字串後 sha256。
        $documentHash = $this->computeDocumentHash($quote);

        try {
            return $this->db->transaction(function () use (
                $quoteId, $signerName, $signerType, $signaturePath, $sealPath, $documentHash, $ip, $userAgent, $shareGuard
            ): int {
                // 分享授權重驗：簽署是真正的提交點，必須以此刻（交易內、已鎖列）的期限與版本為準。
                if ($shareGuard !== null) {
                    $this->lockAndRecheckAnonymousShare(
                        $quoteId,
                        (int) ($shareGuard['revision'] ?? 0),
                        !empty($shareGuard['require_active'])
                    );
                }

                // 併發保護：以條件式 UPDATE 搶下「把這張報價轉為 signed」的權利。
                //
                // 上面的狀態檢查是在交易外做的，雙擊或並行 POST 時兩個請求都會通過，
                // 各自寫入一筆簽署存證 —— 同一張報價出現兩份簽名，法務效力立刻有爭議。
                // 這裡只有 rowCount === 1 的那個請求能繼續；另一個直接中止。
                $claimed = $this->db->execute(
                    "UPDATE {prefix}quotes
                     SET status = 'signed'
                     WHERE id = :id AND status NOT IN ('signed', 'paid', 'void')",
                    ['id' => $quoteId]
                );

                if ($claimed !== 1) {
                    throw new \RuntimeException('此報價單已簽署或已結案，無法重複簽署。');
                }

                $signatureId = $this->signatures->insert([
                    'quote_id'             => $quoteId,
                    'signer_name'          => $signerName,
                    'signer_type'          => in_array($signerType, ['guest', 'customer'], true) ? $signerType : 'guest',
                    'signature_image_path' => $signaturePath,
                    'our_seal_path'        => $sealPath,
                    'document_hash'        => $documentHash,
                    'signed_ip'            => $ip,
                    'user_agent'           => $userAgent,
                ]);

                // status 已由上方的 CAS 寫入，此處只補印章旗標。
                $this->quotes->update($quoteId, [
                    'our_seal_applied' => $sealPath !== null ? 1 : 0,
                ]);

                return $signatureId;
            });
        } catch (\Throwable $e) {
            // 簽名圖在交易前就已寫檔；簽署沒有成立（重複簽署、分享已關閉、授權已變動…）時
            // 不留下沒有對應存證的客戶簽名檔。
            $this->discardSignatureImage($signaturePath);
            throw $e;
        }
    }

    /**
     * 刪除 storeSignatureImage() 剛寫入、但簽署未成立的簽名檔。只接受本服務產生的檔名格式。
     */
    private function discardSignatureImage(string $webPath): void
    {
        $name = basename($webPath);
        if (preg_match('/^\d{8}_sig_[0-9a-f]{16}\.png$/', $name) !== 1) {
            return;
        }
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : (defined('BASE_PATH') ? BASE_PATH : __DIR__);
        $file    = \YangSheep\CRM\Core\UploadPath::subdir('quotes', $webRoot) . '/' . $name;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 計算報價內容的 sha256（存證用）。
     * 涵蓋編號/標題/客戶/金額/條款 + 每筆明細，欄位以 | 分隔、明細以換行分隔。
     * 任一欄位變動即雜湊改變，可證明簽署當下的內容。
     *
     * @param array<string, mixed> $quote 含 items
     */
    public function computeDocumentHash(array $quote): string
    {
        $parts = [
            'no='    . (string) ($quote['quote_number'] ?? ''),
            'title=' . (string) ($quote['title'] ?? ''),
            'cust='  . (string) ($quote['customer_name'] ?? ($quote['customer_id'] ?? '')),
            'sub='   . (string) ($quote['subtotal'] ?? ''),
            'taxr='  . (string) ($quote['tax_rate'] ?? ''),
            'tax='   . (string) ($quote['tax'] ?? ''),
            'total=' . (string) ($quote['total'] ?? ''),
            'cur='   . (string) ($quote['currency'] ?? ''),
            'valid=' . (string) ($quote['valid_until'] ?? ''),
            'terms=' . (string) ($quote['terms'] ?? ''),
        ];

        foreach (($quote['items'] ?? []) as $item) {
            $parts[] = sprintf(
                'item:%s|%s|%s|%s|%s',
                (string) ($item['name'] ?? ''),
                (string) ($item['description'] ?? ''),
                (string) ($item['qty'] ?? ''),
                (string) ($item['unit_price'] ?? ''),
                (string) ($item['amount'] ?? '')
            );
        }

        return hash('sha256', implode("\n", $parts));
    }

    // ───────────────────────── 金額計算（伺服器端） ─────────────────────────

    /**
     * 伺服器端重算金額：subtotal=Σamount、tax=round(subtotal×rate/100)、total=subtotal+tax。
     * 絕不採用前端傳入的任何總計。
     *
     * @param array<int, array<string, mixed>> $items   已正規化（含 amount）的明細
     * @param float                            $taxRate 稅率百分比（0~100）
     * @return array{subtotal: float, tax_rate: float, tax: float, total: float}
     */
    public function calculateTotals(array $items, float $taxRate): array
    {
        $taxRate = max(0.0, min(100.0, $taxRate));

        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) ($item['amount'] ?? 0);
        }
        $subtotal = round($subtotal, 2);
        $tax      = round($subtotal * $taxRate / 100, 2);
        $total    = round($subtotal + $tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax_rate' => round($taxRate, 2),
            'tax'      => $tax,
            'total'    => $total,
        ];
    }

    /**
     * 正規化明細：過濾空列（無名稱）、伺服器端算每筆 amount=qty×unit_price、補 sort。
     * 數量/單價負值歸零（防負金額）。
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        $sort = 0;
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue; // 無名稱的列視為空列，略過
            }

            $qty       = max(0.0, (float) ($row['qty'] ?? 0));
            $unitPrice = max(0.0, (float) ($row['unit_price'] ?? 0));
            $amount    = round($qty * $unitPrice, 2);

            $normalized[] = [
                'name'        => mb_substr($name, 0, 255),
                'description' => trim((string) ($row['description'] ?? '')),
                'qty'         => round($qty, 2),
                'unit'        => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 32),
                'unit_price'  => round($unitPrice, 2),
                'amount'      => $amount,
                'sort'        => $sort++,
            ];
        }
        return $normalized;
    }

    // ───────────────────────── 編號 / Token / 密碼 ─────────────────────────

    /**
     * 產生 quote_number（Q-YYYY-NNNN）。
     * 須於交易內呼叫：以 FOR UPDATE 鎖定當年既有列，取最大序號 +1，避免併發重號。
     */
    private function generateQuoteNumber(): string
    {
        $year = (int) date('Y');
        $next = $this->quotes->maxSequenceForYearForUpdate($year) + 1;
        return sprintf('Q-%04d-%04d', $year, $next);
    }

    /**
     * 產生唯一 access_token（隨機 64 hex）。
     * 碰撞機率極低，仍以查詢確認唯一（最多重試數次）。
     */
    private function generateUniqueToken(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $token = bin2hex(random_bytes(32));
            if ($this->quotes->findByToken($token) === null) {
                return $token;
            }
        }
        // 極端情況：仍以最後一次產生值回傳（DB 唯一索引為最終防線）。
        return bin2hex(random_bytes(32));
    }

    /**
     * 解析密碼雜湊：
     * - 非 password 模式 → null（清除既有密碼）。
     * - password 模式且有輸入新密碼 → 重新雜湊。
     * - password 模式但未輸入（編輯時留空）→ 沿用既有雜湊（$existingHash）。
     */
    private function resolvePasswordHash(string $visibility, ?string $input, ?string $existingHash): ?string
    {
        if ($visibility !== 'password') {
            return null;
        }
        $input = (string) ($input ?? '');
        if ($input !== '') {
            return password_hash($input, PASSWORD_DEFAULT);
        }
        return $existingHash; // 編輯時留空 = 不變
    }

    // ───────────────────────── 簽名圖存檔 ─────────────────────────

    /**
     * 驗證並儲存簽名板輸出的 PNG（data:image/png;base64,...）到 /uploads/quotes/。
     * 嚴格限制：必須是 image/png data URL、解碼後 ≤ 2MB、且為合法 PNG（魔術位元組 + getimagesizefromstring）。
     *
     * @return string|null 成功回傳 web 路徑（/uploads/quotes/...）；失敗回 null
     */
    private function storeSignatureImage(string $dataUrl): ?string
    {
        $dataUrl = trim($dataUrl);
        // 僅接受 PNG data URL
        if (!preg_match('#^data:image/png;base64,#i', $dataUrl)) {
            return null;
        }

        $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $base64 = strtr($base64, ' ', '+'); // 容忍 URL 解碼把 + 變空白
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            return null;
        }
        if (strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            return null;
        }

        // PNG 魔術位元組驗證
        if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        // 真實圖片驗證（防偽造）
        $info = @getimagesizefromstring($binary);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return null;
        }

        $webRoot   = defined('WEB_ROOT') ? WEB_ROOT : (defined('BASE_PATH') ? BASE_PATH : __DIR__);
        $uploadDir = \YangSheep\CRM\Core\UploadPath::subdir('quotes', $webRoot);
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }

        // 寫檔前自我修復 uploads/ 的執行防護。這條路徑是從 base64 直接 file_put_contents
        // 而非 move_uploaded_file，更需要下游防護。
        if (!\YangSheep\CRM\Install\UploadGuard::ensure(\YangSheep\CRM\Core\UploadPath::absolute($webRoot))) {
            return null;
        }

        $filename = date('Ymd') . '_sig_' . bin2hex(random_bytes(8)) . '.png';
        $destPath = $uploadDir . '/' . $filename;
        if (file_put_contents($destPath, $binary) === false) {
            return null;
        }

        return \YangSheep\CRM\Core\UploadPath::webSubdir('quotes') . $filename;
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 解析 customer_id：0/null/空 → null（未指定客戶）；指定則驗證存在。
     */
    private function resolveCustomerId(mixed $customerId): ?int
    {
        $id = (int) ($customerId ?? 0);
        if ($id <= 0) {
            return null;
        }
        if ($this->customers->findById($id) === null) {
            throw new \RuntimeException('指定的客戶不存在。');
        }
        return $id;
    }

    /**
     * 正規化 visibility：非白名單值退回 private（deny-by-default）。
     */
    private function normalizeVisibility(mixed $visibility): string
    {
        $v = (string) ($visibility ?? 'private');
        return in_array($v, self::VISIBILITIES, true) ? $v : 'private';
    }
}
