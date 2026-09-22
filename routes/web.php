<?php

/**
 * 公開路由
 *
 * @var \YangSheep\CRM\Core\Router $router
 */

use YangSheep\CRM\Public\PublicController;
use YangSheep\CRM\Quote\PublicQuoteController;
use YangSheep\CRM\Payment\PublicPaymentController;
use YangSheep\CRM\Auth\AuthController;
use YangSheep\CRM\Auth\TwoFactorController;
use YangSheep\CRM\Middleware\CsrfMiddleware;
use YangSheep\CRM\Middleware\SessionIntegrityMiddleware;
use YangSheep\CRM\Portal\Auth\CustomerAuthController;
use YangSheep\CRM\Portal\Auth\CustomerAuthMiddleware;
use YangSheep\CRM\Portal\PortalDashboardController;
use YangSheep\CRM\Portal\PortalQuoteController;
use YangSheep\CRM\Portal\PortalPaymentController;
use YangSheep\CRM\Portal\PortalAssetController;
use YangSheep\CRM\Portal\PortalContactController;
use YangSheep\CRM\Portal\PortalTeamController;
use YangSheep\CRM\Portal\PortalPaymentMethodController;
use YangSheep\CRM\Portal\PortalProfileController;

// 公開首頁（GET 不需要 CSRF）
$router->get('/', [PublicController::class, 'home']);

// 公開報價單頁（外部訪客；§7.8）。GET 不需 CSRF；存取控制（visibility）於 Controller 內把關。
//   /q/{token}          檢視
$router->get('/q/{token}', [PublicQuoteController::class, 'show']);
// 列印專用頁：獨立全頁 HTML，A4 版面，自動觸發 window.print()。
$router->get('/q/{token}/print', [PublicQuoteController::class, 'printPage']);
// PDF 下載：與頁面走同一道門禁（resolveAccessibleQuote），密碼／僅客戶皆適用。
$router->get('/q/{token}/pdf', [PublicQuoteController::class, 'pdf']);

// ─── 公開付款（外部訪客；§7.9）─────────────────────────────────────────
// GET 路由（不需 CSRF）：
//   /q/{token}/pay           付款方式選擇頁（簽署後「前往付款」連入；信用卡 / 虛擬 ATM）
//   /pay/return/{provider}   gateway 瀏覽器導回頁（顯示結果；入帳以 webhook 為準）
//   /pay/sandbox/{payment_no} 沙盒確認頁
$router->get('/q/{token}/pay',           [PublicPaymentController::class, 'payPage']);
$router->get('/pay/return/{provider}',   [PublicPaymentController::class, 'paymentReturn']);
$router->get('/pay/sandbox/{payment_no}', [PublicPaymentController::class, 'sandboxForm']);

// ★ webhook：server-to-server 通知，CSRF 豁免（以 provider->verifyCallback 驗章）。
//   必須放在 CSRF 群組「之外」，否則外部金流商無法回呼（無 CSRF token）。
$router->post('/pay/callback/{provider}', [PublicPaymentController::class, 'callback']);

// ★ 排程 URL 觸發（CSRF 豁免；以設定中的 cron token constant-time 驗證，fail-closed）。
//   CLI（cli/cron.php）為主；此端點供「主機僅能以 URL 觸發 cron」時使用。對應架構設計 §6 / §7.10。
//   GET /cron/run?token=...&cmd=run|expiry|recurring|reminders|mail&today=YYYY-MM-DD
$router->get('/cron/run', [\YangSheep\CRM\Console\CronController::class, 'run']);

// ★ 沙盒送出：在 CSRF 群組「之外」（模擬 gateway），由 Controller 自驗 CSRF + 沙盒簽章。
$router->post('/pay/sandbox/{payment_no}', [PublicPaymentController::class, 'sandboxSubmit']);

// POST 路由掛載 CSRF 保護
$router->group('', ['middleware' => [CsrfMiddleware::class]], function ($router) {
    $router->post('/login', [\YangSheep\CRM\Auth\AuthController::class, 'login']);
    $router->post('/logout', [\YangSheep\CRM\Auth\AuthController::class, 'logout']);
    // 登入第二階段（2FA / TOTP）驗證 — 完成登入的關鍵端點
    $router->post('/login/2fa', [TwoFactorController::class, 'challengeVerify']);

    // 公開報價單：密碼解鎖 / 線上簽署（皆過 CSRF）。
    $router->post('/q/{token}/unlock', [PublicQuoteController::class, 'unlock']);
    $router->post('/q/{token}/sign',   [PublicQuoteController::class, 'sign']);

    // 公開付款：由報價發動付款（過 CSRF）。
    $router->post('/q/{token}/pay', [PublicPaymentController::class, 'pay']);

    // 客戶 Portal 認證（登入 / 登出，過 CSRF）。GET 登入頁見下方（不需 CSRF）。
    $router->post('/portal/login',  [CustomerAuthController::class, 'login']);
    $router->post('/portal/logout', [CustomerAuthController::class, 'logout']);
});

// ─── 客戶 Portal（客戶專區，第二個對外認證面）────────────────────────────
// GET 登入頁（不需 CSRF；已登入則導向 /portal）。
$router->get('/portal/login', [CustomerAuthController::class, 'loginForm']);

// Portal 內頁群組：每請求驗證客戶 session（CustomerAuthMiddleware）+ IP/UA 指紋
// （SessionIntegrityMiddleware，兩平面共用）+ POST CSRF。與 /admin 完全隔離。
$router->group('/portal', [
    'middleware' => [
        SessionIntegrityMiddleware::class,
        CustomerAuthMiddleware::class,
        CsrfMiddleware::class,
    ],
], function ($router) {
    // 總覽
    $router->get('', [PortalDashboardController::class, 'index']);

    // 報價單（需 quotes scope）。
    $router->group('/quotes', ['middleware' => [CustomerAuthMiddleware::class . ':quotes']], function ($router) {
        $router->get('',          [PortalQuoteController::class, 'index']);
        $router->get('/{id}',     [PortalQuoteController::class, 'show']);
        // 由自己的報價發動付款（需同時具 payments scope）。
        $router->post('/{id}/pay', [PortalPaymentController::class, 'pay']);
    });

    // 付款記錄（需 payments scope）。
    $router->group('/payments', ['middleware' => [CustomerAuthMiddleware::class . ':payments']], function ($router) {
        $router->get('', [PortalPaymentController::class, 'index']);
    });

    // 主機與網站（需 assets scope；唯讀）。
    $router->group('/assets', ['middleware' => [CustomerAuthMiddleware::class . ':assets']], function ($router) {
        $router->get('', [PortalAssetController::class, 'index']);
    });

    // 聯絡人（需 contacts scope）。
    $router->group('/contacts', ['middleware' => [CustomerAuthMiddleware::class . ':contacts']], function ($router) {
        $router->get('',                       [PortalContactController::class, 'index']);
        $router->post('',                      [PortalContactController::class, 'store']);
        $router->post('/{contact_id}/delete',  [PortalContactController::class, 'destroy']);
    });

    // 付款卡片（需 payment_methods scope）。
    $router->group('/payment-methods', ['middleware' => [CustomerAuthMiddleware::class . ':payment_methods']], function ($router) {
        $router->get('',               [PortalPaymentMethodController::class, 'index']);
        $router->post('',              [PortalPaymentMethodController::class, 'store']);
        $router->post('/{id}/default', [PortalPaymentMethodController::class, 'setDefault']);
        $router->post('/{id}/delete',  [PortalPaymentMethodController::class, 'destroy']);
    });

    // 團隊與子帳號（🔴 僅 owner；CustomerAuthMiddleware:team 對 member 一律拒絕）。
    $router->group('/team', ['middleware' => [CustomerAuthMiddleware::class . ':team']], function ($router) {
        $router->get('',                    [PortalTeamController::class, 'index']);
        $router->post('',                   [PortalTeamController::class, 'store']);
        $router->post('/{id}/toggle',       [PortalTeamController::class, 'toggleActive']);
        $router->post('/{id}/permissions',  [PortalTeamController::class, 'updatePermissions']);
    });

    // 個人資料（無 scope 限制，所有登入客戶皆可）。
    $router->get('/profile',           [PortalProfileController::class, 'index']);
    $router->post('/profile',          [PortalProfileController::class, 'updateProfile']);
    $router->post('/profile/password', [PortalProfileController::class, 'changePassword']);
});

// 登入頁面（GET 不需要 CSRF）
$router->get('/login', [AuthController::class, 'loginForm']);

// 登入第二階段輸入頁（GET 不需要 CSRF；需 _2fa_pending 有效，否則導回 /login）
$router->get('/login/2fa', [TwoFactorController::class, 'challengeForm']);
