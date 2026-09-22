<?php

declare(strict_types=1);

namespace YangSheep\CRM\Setting;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;

class SettingController extends Controller
{
    private SettingService $settingService;

    /**
     * 設定結構定義 — 各群組的欄位、標籤及屬性
     */
    private const SETTING_SCHEMA = [
        'site' => [
            'label'  => '網站設定',
            'tab'    => '一般',
            'icon'   => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            'fields' => [
                'site_name'     => ['label' => '網站名稱',       'type' => 'text',     'encrypted' => false],
                'site_url'      => ['label' => '網站網址',       'type' => 'url',      'encrypted' => false],
                'site_desc'     => ['label' => '網站描述',       'type' => 'textarea', 'encrypted' => false],
                'og_image'      => ['label' => 'OG 分享圖片',   'type' => 'image',    'encrypted' => false,
                                    'help'  => '建議尺寸 1200x630，用於社群平台分享預覽'],
                'site_logo'     => ['label' => '網站 Logo',     'type' => 'image',    'encrypted' => false,
                                    'help'  => '建議透明 PNG，高度 32px 以上（會等比縮放至高度 32px、最大寬度 140px）。顯示於登入頁 / 首頁 / 後台側欄，置於網站名稱左側；留空則只顯示網站名稱文字。'],
            ],
        ],
        'company' => [
            'label'  => '公司資料',
            'tab'    => '一般',
            // heroicon: office-building
            'icon'   => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            'fields' => [
                'company_name'    => ['label' => '公司名稱',     'type' => 'text',  'encrypted' => false],
                'company_tax_id'  => ['label' => '統一編號',     'type' => 'text',  'encrypted' => false],
                'company_address' => ['label' => '公司地址',     'type' => 'text',  'encrypted' => false],
                'company_phone'   => ['label' => '公司電話',     'type' => 'text',  'encrypted' => false],
                'company_email'   => ['label' => '公司信箱',     'type' => 'email', 'encrypted' => false],
                'company_contact' => ['label' => '聯絡人',       'type' => 'text',  'encrypted' => false],
                'company_seal'    => ['label' => '公司印章',     'type' => 'image', 'encrypted' => false,
                                      'help'  => '圓形透明 PNG 最佳，供報價單線上簽署時合成我方用印'],
            ],
        ],
        'security' => [
            'label'  => '安全設定',
            'tab'    => '安全',
            'icon'   => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
            'fields' => [
                'session_lifetime_minutes' => ['label' => 'Session 有效期（分鐘）', 'type' => 'number', 'encrypted' => false],
                'login_max_attempts'      => ['label' => '登入嘗試上限',       'type' => 'number', 'encrypted' => false],
                'login_lockout_minutes'   => ['label' => '鎖定時間（分鐘）',   'type' => 'number', 'encrypted' => false],
                // 地理位置查詢：預設關閉。開啟代表把登入者的 IP 送到第三方服務，
                // 那是隱私決定，必須由人明確選擇，不能預設替使用者決定。
                'geoip_enabled'  => [
                    'label'     => '登入紀錄查詢地理位置',
                    'type'      => 'select',
                    'encrypted' => false,
                    'options'   => ['0' => '關閉（預設）', '1' => '啟用'],
                    'hint'      => '啟用後會把登入來源 IP 送到下方的第三方服務查詢。內部位址不會外送，查詢失敗不影響登入紀錄顯示。',
                ],
                'geoip_endpoint' => [
                    'label'     => '地理位置查詢端點',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '以 {ip} 為佔位符，必須是 https。例：https://ipapi.co/{ip}/json/',
                ],
                'single_session' => [
                    'label'     => '管理帳號多重登入',
                    'type'      => 'select',
                    'encrypted' => false,
                    'options'   => ['0' => '允許（預設）', '1' => '不允許（僅保留新登入）'],
                    'hint'      => '不允許時，管理帳號完成登入（含兩階段驗證）後撤銷該帳號舊 Session。切換設定本身不會立即登出既有裝置；不影響客戶 Portal 帳號。',
                ],
            ],
        ],
        'turnstile' => [
            'label'  => 'Turnstile 設定',
            'tab'    => '安全',
            'icon'   => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
            'fields' => [
                'site_key'   => ['label' => 'Site Key',   'type' => 'text',     'encrypted' => false],
                'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'encrypted' => true],
            ],
        ],
        'notification' => [
            'label'  => '通知設定',
            'tab'    => '通知',
            'icon'   => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
            'fields' => [
                'notify_email'         => ['label' => '通知信箱',           'type' => 'email',    'encrypted' => false],
                'smtp_host'            => ['label' => 'SMTP 伺服器',       'type' => 'text',     'encrypted' => false],
                'smtp_port'            => ['label' => 'SMTP Port',         'type' => 'number',   'encrypted' => false],
                'smtp_username'        => ['label' => 'SMTP 帳號',         'type' => 'text',     'encrypted' => false],
                'smtp_password'        => ['label' => 'SMTP 密碼',         'type' => 'password', 'encrypted' => true],
                'smtp_encryption'      => ['label' => 'SMTP 加密方式',     'type' => 'select',   'encrypted' => false,
                                           'options' => ['none' => '無', 'tls' => 'TLS', 'ssl' => 'SSL']],
            ],
        ],
        'cdn' => [
            'label'  => '網路與 CDN',
            'tab'    => '網路與 CDN',
            // heroicon: cloud
            'icon'   => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z',
            'fields' => [
                'cdn_provider'  => ['label' => 'CDN 供應商', 'type' => 'select',   'encrypted' => false,
                                    'options' => ['none' => '無', 'cloudflare' => 'Cloudflare', 'bunny' => 'Bunny.net']],
                'cdn_api_token' => ['label' => 'API Token',  'type' => 'password', 'encrypted' => true,
                                    'help'  => '用於快取清除（purge）等 API 操作；加密儲存'],
                'cdn_zone_id'   => ['label' => 'Zone ID',    'type' => 'text',     'encrypted' => false,
                                    'help'  => 'Cloudflare Zone ID'],
                'cdn_pull_zone' => ['label' => 'Pull Zone',  'type' => 'text',     'encrypted' => false,
                                    'help'  => 'Bunny.net Pull Zone 名稱'],

                // ── 訪客 IP 偵測 ────────────────────────────────
                // 與 CDN 放在同一頁：兩者處理的是同一件事 —— 站台前面有東西，
                // 而真實訪客位址只存在於它寫進來的 header 裡。
                // 選錯的後果不是「顯示怪怪的」，而是速率限制與封鎖全部失效，
                // 所以下方會即時列出各模式現在會得到什麼，供直接比對。
                'client_ip_mode' => [
                    'label'     => '訪客 IP 偵測方式',
                    'type'      => 'select',
                    'encrypted' => false,
                    'options'   => [
                        'remote_addr'      => 'REMOTE_ADDR（預設，最安全）',
                        'x_forwarded_for'  => 'X-Forwarded-For',
                        'x_real_ip'        => 'X-Real-IP',
                        'cf_connecting_ip' => 'CF-Connecting-IP（Cloudflare）',
                        'true_client_ip'   => 'True-Client-IP',
                        'custom'           => '自訂 header',
                    ],
                    'hint'      => '請對照下方的偵測結果選擇：哪一列等於您已知的公網 IP，就選哪一個。'
                                 . '在確認前端設備真的會插入該 header 之前，請維持 REMOTE_ADDR —— '
                                 . '採信一個沒人在寫的 header，等於讓任何人自己指定要被記成哪個 IP。',
                ],
                'client_ip_header' => [
                    'label'     => '自訂 header 名稱',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '僅在上方選「自訂 header」時使用，例如 X-Client-IP。',
                ],
                'client_ip_hops' => [
                    'label'     => '信任層數',
                    'type'      => 'number',
                    'encrypted' => false,
                    'hint'      => '我方基礎設施會在 header 尾端附加幾段。取「由右數第 N 段」——'
                                 . '客戶端可以自己先塞一段假的，取最左段就會拿到偽造值。'
                                 . '常見的單層反向代理為 1。',
                ],
                'trusted_proxies' => [
                    'label'     => '信任的來源位址',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '逗號分隔，可用 CIDR。只有來自這些位址的請求，其 header 才會被採信。'
                                 . '留空則使用私有網段預設值。',
                ],
            ],
        ],
        'quote_style' => [
            'label'  => '報價單外觀',
            'tab'    => '一般',
            // heroicon: color-swatch
            'icon'   => 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01',
            'fields' => [
                // 這些值會成為公開報價單的 CSS 變數。
                // 原本整組色票寫死在 views/public/quote/show.php 裡（米色／褐色系），
                // 要換一次得改 CSS —— 對「每個客戶的品牌不同」這件事完全沒有彈性。
                'quote_theme' => [
                    'label'     => '配色',
                    'type'      => 'select',
                    'encrypted' => false,
                    'options'   => [
                        'warm'    => '暖米色（預設）',
                        'clean'   => '純白簡潔',
                        'slate'   => '冷灰',
                        'navy'    => '深藍',
                        'custom'  => '自訂（使用下方色碼）',
                    ],
                    'hint'      => '公開報價單與列印輸出的配色。選「自訂」時才會使用下方的色碼。',
                ],
                'quote_color_brand' => [
                    'label'     => '自訂：主色',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '標題與強調文字，例如 #1f3a5f。僅在配色選「自訂」時生效。',
                ],
                'quote_color_rail' => [
                    'label'     => '自訂：左欄底色',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '左側資訊欄的背景，例如 #f5f7fa。',
                ],
                'quote_color_line' => [
                    'label'     => '自訂：分隔線',
                    'type'      => 'text',
                    'encrypted' => false,
                    'hint'      => '表格與區塊的線條顏色，例如 #dbe2ea。',
                ],
                'quote_brand_display' => [
                    'label'     => '左上角品牌顯示',
                    'type'      => 'select',
                    'encrypted' => false,
                    'options'   => [
                        'logo_and_name' => 'Logo + 公司名稱（預設）',
                        'logo_only'     => '只顯示 Logo',
                        'name_only'     => '只顯示公司名稱',
                    ],
                    'hint'      => 'Logo 於「公司資料」上傳。選「只顯示 Logo」但未上傳時，會自動退回顯示名稱 —— 否則左上角會是一片空白。',
                ],
            ],
        ],
        'payment' => [
            'label'  => '金流設定',
            'tab'    => '金流',
            // heroicon: credit-card
            'icon'   => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
            'fields' => [
                'payment_provider'    => ['label' => '啟用金流商', 'type' => 'select', 'encrypted' => false,
                                          'options' => ['sandbox' => '沙盒測試（無需金鑰）', 'payuni' => 'PayUni 統一金', 'shopline' => 'SHOPLINE Payments'],
                                          'help'    => '選擇對外發動付款時使用的金流商；沙盒可在無真實金鑰下跑通完整付款流程'],
                'payment_env'         => ['label' => '金流環境',   'type' => 'select', 'encrypted' => false,
                                          'options' => ['test' => '測試（sandbox）', 'prod' => '正式（production）'],
                                          'help'    => '決定 PayUni / SHOPLINE 走測試或正式 API 端點'],
                // PayUni（統一金）
                'payuni_merchant_id'  => ['label' => 'PayUni 商店代號 MerID', 'type' => 'text',     'encrypted' => false],
                'payuni_hash_key'     => ['label' => 'PayUni HashKey',        'type' => 'password', 'encrypted' => true,
                                          'help'  => '加密儲存；留空表示不修改'],
                'payuni_hash_iv'      => ['label' => 'PayUni HashIV',         'type' => 'password', 'encrypted' => true,
                                          'help'  => '加密儲存；留空表示不修改'],
                // SHOPLINE Payments
                'shopline_merchant_id' => ['label' => 'SHOPLINE 特店 ID',  'type' => 'text',     'encrypted' => false],
                'shopline_secret'      => ['label' => 'SHOPLINE 金鑰',      'type' => 'password', 'encrypted' => true,
                                           'help'  => 'API Key / Webhook 簽章金鑰，加密儲存；留空表示不修改'],
            ],
        ],
        'billing' => [
            'label'  => '週期帳務設定',
            'tab'    => '金流',
            // heroicon: refresh / arrow-path
            'icon'   => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
            'fields' => [
                'recurring_advance_days'         => ['label' => '提前產生天數',     'type' => 'number', 'encrypted' => false,
                                                     'help'  => '到期前幾天預先產生週期帳單（預設 7）'],
                'recurring_autocharge_after_days' => ['label' => '自動扣款延遲天數', 'type' => 'number', 'encrypted' => false,
                                                     'help'  => '帳單產生後幾天自動扣款（預設 3）'],
            ],
        ],
        'offline_atm' => [
            'label'  => '離線 ATM 設定',
            'tab'    => '金流',
            // heroicon: library / bank
            'icon'   => 'M12 14l9-5-9-5-9 5 9 5z M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z',
            'fields' => [
                'offline_atm_enabled'      => ['label' => '啟用離線 ATM', 'type' => 'select', 'encrypted' => false,
                                               'options' => ['0' => '停用', '1' => '啟用']],
                'offline_atm_bank'         => ['label' => '銀行＋分行',   'type' => 'text',   'encrypted' => false],
                'offline_atm_account_name' => ['label' => '戶名',         'type' => 'text',   'encrypted' => false],
                'offline_atm_account_no'   => ['label' => '帳號',         'type' => 'text',   'encrypted' => false],
            ],
        ],
        'einvoice' => [
            'label'  => '電子發票',
            'tab'    => '電子發票',
            // heroicon: document-text
            'icon'   => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'fields' => [
                // 開關一律用 select 而非 checkbox：未勾選的 checkbox 不會出現在 POST，
                // 會導致「開了就關不掉」（設定值永遠停留在 1）。
                'einvoice_enabled'      => ['label' => '啟用電子發票', 'type' => 'select', 'encrypted' => false,
                                            'options' => ['0' => '停用', '1' => '啟用'],
                                            'help'    => '停用時完全不建立發票紀錄、不呼叫 API。'],
                'einvoice_auto_issue'   => ['label' => '付款後自動開立', 'type' => 'select', 'encrypted' => false,
                                            'options' => ['0' => '停用（僅手動開立）', '1' => '啟用'],
                                            'help'    => '付款入帳成功後自動開立；失敗會留待排程重試，不影響付款狀態。'],
                'einvoice_environment'  => ['label' => 'PayNow 環境', 'type' => 'select', 'encrypted' => false,
                                            'options' => ['test' => '測試（invoiceapi-dev）', 'production' => '正式（invoiceapi-prod）'],
                                            'help'    => '⚠️ 測試與正式的 JWT 不通用，切換環境後務必重新填寫下方 Token。'],
                'einvoice_token'        => ['label' => 'PayNow JWT Token', 'type' => 'password', 'encrypted' => true,
                                            'help'    => 'PayNow 核發之商家 JWT（加密儲存）。留空表示不修改現有值。'],
                'einvoice_seller_tax_id' => ['label' => '賣方統一編號', 'type' => 'text', 'encrypted' => false,
                                            'help'    => '取得官方發票列印頁所需（SOAP 的 mem_cid 參數）。'],
                'einvoice_base_url'     => ['label' => 'API 端點覆寫', 'type' => 'url', 'encrypted' => false,
                                            'help'    => '留空即依環境自動選擇，一般不需填寫。'],
                'einvoice_default_tax_type' => ['label' => '預設稅別', 'type' => 'select', 'encrypted' => false,
                                            'options' => ['1' => '應稅', '2' => '零稅率', '3' => '免稅']],
                'einvoice_zero_tax_rate_reason' => ['label' => '零稅率原因', 'type' => 'select', 'encrypted' => false,
                                            'options' => [
                                                'None'                      => '未指定',
                                                'ExportGoods'               => '外銷貨物',
                                                'ExportLabor'               => '外銷相關勞務',
                                                'FreeTaxGoods'              => '免稅商店銷售旅客貨物',
                                                'OperatingGoodsOrLabor'     => '保稅區營業貨物或勞務',
                                                'InterNationsTransPort'     => '國際運輸',
                                                'InterNationsShip'          => '國際運輸船舶或航空器',
                                                'SalesInterNationsShip'     => '國際運輸設備使用貨物或修繕勞務',
                                                'Eight'                     => '第八款',
                                                'Nine'                      => '第九款',
                                            ],
                                            'help'    => '稅別選零稅率時必填，否則開立會被擋下。'],
                'einvoice_rate_limit'   => ['label' => '每分鐘呼叫上限', 'type' => 'number', 'encrypted' => false,
                                            'help'    => '出網限流，預設 50。'],
                'einvoice_circuit_threshold' => ['label' => '熔斷失敗次數', 'type' => 'number', 'encrypted' => false,
                                            'help'    => '連續失敗達此次數即暫停送出，預設 5。'],
                'einvoice_circuit_pause_minutes' => ['label' => '熔斷暫停分鐘', 'type' => 'number', 'encrypted' => false,
                                            'help'    => '熔斷後暫停多久自動恢復，預設 10。'],
                'einvoice_log_retention_days' => ['label' => 'API 紀錄保留天數', 'type' => 'number', 'encrypted' => false,
                                            'help'    => '預設 90 天。紀錄含完整的請求與回應（憑證已遮蔽），是診斷開立失敗的唯一依據；過短會失去診斷價值，過長則表格會持續膨脹。最低 7 天。'],
            ],
        ],
    ];

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->settingService = new SettingService();
    }

    /**
     * 系統設定頁面
     */
    public function index(): void
    {
        // 取得所有設定值
        $settings = $this->settingService->getAllGrouped();

        $this->view->layout('admin');
        $this->render('admin/settings/index', [
            'title'    => '系統設定',
            'schema'   => self::SETTING_SCHEMA,
            'settings' => $settings,
            // 訪客 IP 偵測診斷：列出每一種模式「現在」會得到什麼。
            // 這是這個設定唯一能被正確選擇的方式 —— 管理員拿自己已知的公網 IP
            // 對照即可，不必理解各家設備寫哪個 header。
            'ipDiagnosis' => \YangSheep\CRM\Core\ClientIpResolver::diagnose($_SERVER),
            'currentIp'   => $this->request->ip(),
            'ipIsReal'    => $this->request->clientIpIsReal(),
        ]);
    }

    /**
     * POST 儲存設定
     */
    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        $singleSession = $this->request->input('security__single_session');
        if ($singleSession !== null && !in_array($singleSession, ['0', '1'], true)) {
            $this->backWithError('請選擇有效的管理帳號多重登入設定。');
        }
        $saved = 0;
        $failedImages = [];

        // 🔴 環境切換前先記下舊值 —— 迴圈跑完就分不出「改過」還是「本來就是這個」。
        $previousInvoiceEnv = (string) ($this->settingService->get('einvoice', 'einvoice_environment') ?? '');

        // 🔴 【為何要在迴圈之前清】原本是迴圈跑完才清 —— 那會把使用者在
        // **同一次提交**輸入的新 token 一併抹掉：切換環境並貼上正式機 JWT 後按儲存，
        // 迴圈存進新 token，緊接著的清除又把它清空，而畫面提示還寫著
        //「請重新填入該環境的 Token」，指的正是他剛剛才輸入的那一個。
        //
        // 改成先清：舊環境的 token 立即失效，接著迴圈照常寫入這次輸入的值
        //（password 型欄位留空時本來就會跳過，所以沒填的情況仍維持清空）。
        $notice = $this->clearInvoiceTokenIfEnvironmentChanged(
            $previousInvoiceEnv,
            (string) ($this->request->input('einvoice__einvoice_environment') ?? '')
        );

        foreach (self::SETTING_SCHEMA as $group => $groupDef) {
            foreach ($groupDef['fields'] as $key => $fieldDef) {
                // 圖片類型：處理檔案上傳
                if ($fieldDef['type'] === 'image') {
                    $inputKey = "{$group}__{$key}";
                    $file = $this->request->file($inputKey);
                    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                        $result = $file['error'] === UPLOAD_ERR_OK
                            ? $this->handleImageUpload($file, $key)
                            : null;
                        if ($result !== null) {
                            $this->settingService->set($group, $key, $result, false);
                            $saved++;
                        } else {
                            $failedImages[] = $fieldDef['label'];
                        }
                    }
                    continue;
                }

                $inputKey = "{$group}__{$key}";
                $value = $this->request->input($inputKey);
                if ($inputKey === 'security__single_session' && $value === null) {
                    continue; // Partial/older settings forms must preserve the security policy.
                }

                // password 欄位如果提交為空，表示不修改
                if ($fieldDef['type'] === 'password' && ($value === null || $value === '')) {
                    continue;
                }

                $value = $value ?? '';

                $this->settingService->set(
                    $group,
                    $key,
                    (string) $value,
                    $fieldDef['encrypted'] ?? false
                );
                $saved++;
            }
        }

        if ($failedImages !== []) {
            $this->redirectWith('/admin/settings', 'error', "已儲存 {$saved} 項設定。以下圖片未儲存："
                . implode('、', $failedImages) . '。請重試；若持續失敗，請聯絡管理員。' . $notice);
        }
        $this->redirectWith('/admin/settings', 'success', "已儲存 {$saved} 項設定。" . $notice);
    }

    /**
     * 電子發票環境切換時清空 JWT token。
     *
     * 【為什麼非清不可】測試機與正式機的 JWT 不通用。切了環境卻沿用舊 token，
     * 得到的是 401 —— 而設定畫面上**每一個欄位看起來都是對的**（環境對、token 有值），
     * 於是查修方向會整個歪掉：去懷疑網路、防火牆、對方系統，就是不會懷疑 token。
     *
     * 寧可強迫重新輸入一次，也不要留下一個「看起來正確卻永遠打不通」的狀態。
     *
     * ⚠️ 這段程式原本只存在於 InvoiceSettings 的註解裡（宣稱「由 SettingController
     * 主動清空」），但實作從來沒有寫。註解不是程式 —— 2026-09-02 補上。
     */
    private function clearInvoiceTokenIfEnvironmentChanged(string $previousEnv, string $submittedEnv): string
    {
        // 🔴 新環境取自「本次送出的值」，不是再查一次 DB —— 這個方法現在跑在
        // 儲存迴圈**之前**，DB 裡還是舊值，查了只會得到 $previousEnv 而永遠不相等。
        $currentEnv = trim($submittedEnv);

        // 舊值為空 = 第一次設定，不算「切換」，不必清（也沒東西可清）。
        if ($previousEnv === '' || $currentEnv === '' || $previousEnv === $currentEnv) {
            return '';
        }

        $hadToken = (string) ($this->settingService->get('einvoice', 'einvoice_token') ?? '') !== '';
        $this->settingService->set('einvoice', 'einvoice_token', '', true);

        $user = \YangSheep\CRM\Core\Session::get('user');
        (new \YangSheep\CRM\AuditLog\AuditLogService())->log(
            (int) ($user['id'] ?? 0),
            'einvoice_environment_switched',
            'setting',
            null,
            ['from' => $previousEnv, 'to' => $currentEnv, 'token_cleared' => $hadToken],
            $this->request->ip()
        );

        $submittedToken = trim((string) ($this->request->input('einvoice__einvoice_token') ?? ''));

        if ($submittedToken !== '') {
            // 使用者在同一次提交就填了新 token —— 迴圈接下來會寫入它，不必再提醒補填。
            return ' 電子發票環境已變更，已改用本次填入的 JWT Token。';
        }

        return $hadToken
            ? ' ⚠️ 已偵測到電子發票環境變更，原 JWT Token 已清空，請填入該環境的 Token。'
            : ' 電子發票環境已變更，請填入該環境的 JWT Token。';
    }

    /**
     * 寄送 SMTP 測試信（系統設定頁「寄送測試信」按鈕）。
     *
     * 直接呼叫 Mailer::sendTest 即時寄送（不經佇列），把結果以 flash 回報。
     * 無 SMTP 設定或寄送失敗時 Mailer 回傳 error，不丟致命錯。對應架構設計 §7.2、§7.10。
     */
    public function testEmail(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF Token 驗證失敗。');
        }

        // 收件者：表單指定 → 否則用通知信箱 → 否則公司信箱。
        $to = trim((string) $this->request->input('test_email_to', ''));
        if ($to === '') {
            $to = trim((string) ($this->settingService->get('notification', 'notify_email') ?? ''));
        }
        if ($to === '') {
            $to = trim((string) ($this->settingService->get('company', 'company_email') ?? ''));
        }

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWith('/admin/settings', 'error', '請先填寫有效的測試收件信箱（或設定通知信箱）。');
        }

        $mailer = new \YangSheep\CRM\Notification\Mailer($this->settingService);
        $result = $mailer->sendTest($to);

        if (($result['ok'] ?? false) === true) {
            $this->redirectWith('/admin/settings', 'success', "測試信已成功寄送至 {$to}。");
        } else {
            $error = (string) ($result['error'] ?? '寄送失敗');
            $this->redirectWith('/admin/settings', 'error', "測試信寄送失敗：{$error}");
        }
    }

    /**
     * 測試 PayNow 電子發票連線
     */
    public function testEinvoiceConnection(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->json(['success' => false, 'message' => 'CSRF Token 驗證失敗。'], 403);
            return;
        }

        $settings = new \YangSheep\CRM\EInvoice\InvoiceSettings($this->settingService);

        if (!$settings->enabled()) {
            $this->json(['success' => false, 'message' => '電子發票功能尚未啟用。']);
            return;
        }

        if (!$settings->token()) {
            $this->json(['success' => false, 'message' => 'PayNow JWT Token 尚未設定，請先填寫後儲存。']);
            return;
        }

        $client = new \YangSheep\CRM\EInvoice\PayNowRestClient(
            $settings->baseUrl(),
            $settings->token(),
            $settings->environment()
        );
        $result = $client->testConnection();

        $ok  = ($result['success'] ?? false) === true;
        $msg = $result['message'] ?? ($ok ? '連線成功' : '連線失敗');
        $this->json(['success' => $ok, 'message' => $msg]);
    }

    /**
     * 處理設定頁的圖片上傳
     */
    private function handleImageUpload(array $file, string $key): ?string
    {
        // 驗證 MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (!isset($allowed[$mime])) {
            return null;
        }

        // 驗證大小（2MB）
        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        // 驗證是真正的圖片
        if (@getimagesize($file['tmp_name']) === false) {
            return null;
        }

        $ext = $allowed[$mime];
        $filename = $key . '_' . date('Ymd_His') . '.' . $ext;

        $uploadDir = \YangSheep\CRM\Core\UploadPath::subdir('settings');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 同 UserService：寫檔前自我修復 uploads/ 的執行防護。
        if (!\YangSheep\CRM\Install\UploadGuard::ensure(\YangSheep\CRM\Core\UploadPath::absolute())) {
            return null;
        }

        $dest = $uploadDir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return \YangSheep\CRM\Core\UploadPath::webSubdir('settings') . $filename;
        }

        return null;
    }
}
