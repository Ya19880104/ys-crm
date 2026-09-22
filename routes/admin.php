<?php

/**
 * 後台路由（需認證）
 *
 * @var \YangSheep\CRM\Core\Router $router
 */

use YangSheep\CRM\Middleware\SessionIntegrityMiddleware;
use YangSheep\CRM\Middleware\AuthMiddleware;
use YangSheep\CRM\Middleware\Require2faMiddleware;
use YangSheep\CRM\Middleware\StepUpMiddleware;
use YangSheep\CRM\Middleware\CsrfMiddleware;
use YangSheep\CRM\Middleware\PermissionMiddleware;

// Require2faMiddleware 排在 AuthMiddleware 之後（確保已登入才檢查），
// 並在 CsrfMiddleware 之前；其 allowlist 確保 /admin/2fa/setup 與 /logout 仍可達。
$router->group('/admin', [
    'middleware' => [
        SessionIntegrityMiddleware::class,
        AuthMiddleware::class,
        Require2faMiddleware::class,
        CsrfMiddleware::class,
    ],
], function ($router) {

    // 儀表板
    $router->get('/dashboard', [\YangSheep\CRM\Board\BoardController::class, 'dashboard']);

    // 兩階段驗證設定（已登入；Require2faMiddleware allowlist 放行此路徑）
    $router->get('/2fa/setup',  [\YangSheep\CRM\Auth\TwoFactorController::class, 'setupForm']);
    $router->post('/2fa/setup', [\YangSheep\CRM\Auth\TwoFactorController::class, 'setupVerify']);

    // step-up 再認證（不可掛 StepUpMiddleware，否則造成重導迴圈）
    $router->get('/reauth',  [\YangSheep\CRM\Auth\TwoFactorController::class, 'reauthForm']);
    $router->post('/reauth', [\YangSheep\CRM\Auth\TwoFactorController::class, 'reauthVerify']);

    // 個人資料：任何已登入者都能編輯**自己**的顯示名稱／Email／頭像／密碼。
    // 刻意不掛 user.manage：那是「管理別人的帳號」的權限，與「維護自己的資料」是兩件事。
    // Controller 一律以 session 中的使用者為對象，不接受請求傳入的 id（防提權）。
    $router->get('/profile',  [\YangSheep\CRM\User\ProfileController::class, 'edit']);
    $router->post('/profile', [\YangSheep\CRM\User\ProfileController::class, 'update']);
    // 帳號可用性即時檢查（只回答可不可以用，不透露是誰佔用）。
    $router->post('/profile/check-username', [\YangSheep\CRM\User\ProfileController::class, 'checkUsername']);

    // 客戶管理（CRM 域核心模組）
    $router->group('/customers', [
        'middleware' => [PermissionMiddleware::class . ':customers.view'],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\Customer\CustomerController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\Customer\CustomerController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\Customer\CustomerController::class, 'store']);
        $router->get('/{id}',     [\YangSheep\CRM\Customer\CustomerController::class, 'show']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Customer\CustomerController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Customer\CustomerController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Customer\CustomerController::class, 'destroy']);
        // 聯絡人
        $router->post('/{id}/contacts',                       [\YangSheep\CRM\Customer\CustomerController::class, 'storeContact']);
        $router->post('/{id}/contacts/{contact_id}/delete',   [\YangSheep\CRM\Customer\CustomerController::class, 'destroyContact']);

        // 客戶 Portal 登入帳號管理（需 customer_user.manage 權限；對應架構設計 §7.5）。
        // 因無 SMTP，建立 / 重設密碼後以一次性密碼畫面顯示（flash），由管理員轉達客戶。
        $router->group('/{id}/portal-account', [
            'middleware' => [PermissionMiddleware::class . ':customer_user.manage'],
        ], function ($router) {
            $router->post('',                       [\YangSheep\CRM\Customer\CustomerUserAdminController::class, 'store']);
            $router->post('/{uid}/toggle',          [\YangSheep\CRM\Customer\CustomerUserAdminController::class, 'toggle']);
            $router->post('/{uid}/reset-password',  [\YangSheep\CRM\Customer\CustomerUserAdminController::class, 'resetPassword']);
        });

        // 發票資料表單與寫入均需客戶編輯權限；列表保留在 customers.view 內頁。
        $router->group('/{customer_id}/invoice-profiles', [
            'middleware' => [PermissionMiddleware::class . ':customers.edit'],
        ], function ($router) {
            $router->get('/create',           [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'create']);
            $router->post('',                 [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'store']);
            $router->get('/{id}/edit',        [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'edit']);
            $router->post('/{id}',            [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'update']);
            $router->post('/{id}/set-default', [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'setDefault']);
            $router->post('/{id}/delete',      [\YangSheep\CRM\EInvoice\InvoiceProfileController::class, 'delete']);
        });
    });

    // 工作看板（CRM 域核心模組；對應架構設計 §7.6）
    // 群組進入需 jobs.view；create/edit/delete/欄位管理之細粒度權限於 JobController 內再檢查。
    // 計時器 start/stop、追加內容、移動皆走 POST + CSRF（移動另支援 AJAX + CSRF header）。
    $router->group('/jobs', [
        'middleware' => [PermissionMiddleware::class . ':jobs.view'],
    ], function ($router) {
        // 看板總覽
        $router->get('',        [\YangSheep\CRM\Job\JobController::class, 'index']);
        // 欄位管理（放在 /{id} 之前，避免 'columns' 被當成 id）
        $router->get('/columns',                    [\YangSheep\CRM\Job\JobController::class, 'columns']);
        $router->post('/columns',                   [\YangSheep\CRM\Job\JobController::class, 'storeColumn']);
        $router->post('/columns/{id}',              [\YangSheep\CRM\Job\JobController::class, 'updateColumn']);
        $router->post('/columns/{id}/delete',       [\YangSheep\CRM\Job\JobController::class, 'destroyColumn']);
        // 卡片
        $router->get('/create',  [\YangSheep\CRM\Job\JobController::class, 'create']);
        $router->post('',        [\YangSheep\CRM\Job\JobController::class, 'store']);
        $router->get('/{id}',     [\YangSheep\CRM\Job\JobController::class, 'show']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Job\JobController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Job\JobController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Job\JobController::class, 'destroy']);
        $router->post('/{id}/move',   [\YangSheep\CRM\Job\JobController::class, 'move']);
        // 追加內容
        $router->post('/{id}/entries',                     [\YangSheep\CRM\Job\JobController::class, 'addEntry']);
        $router->post('/{id}/entries/{entry_id}/delete',   [\YangSheep\CRM\Job\JobController::class, 'deleteEntry']);
        // 計時器
        $router->post('/{id}/timer/start', [\YangSheep\CRM\Job\JobController::class, 'timerStart']);
        $router->post('/{id}/timer/stop',  [\YangSheep\CRM\Job\JobController::class, 'timerStop']);
    });

    // 報價單系統（CRM 域核心模組；對應架構設計 §7.8），排在工作看板之後。
    // 群組進入需 quotes.view；create/edit/delete/送出之細粒度權限於 QuoteController 內再檢查。
    // 公開報價頁（/q/{token} 等）為外部路由，定義於 routes/web.php，不在此後台群組內。
    $router->group('/quotes', [
        'middleware' => [PermissionMiddleware::class . ':quotes.view'],
    ], function ($router) {
        $router->get('',           [\YangSheep\CRM\Quote\QuoteController::class, 'index']);
        $router->get('/create',    [\YangSheep\CRM\Quote\QuoteController::class, 'create']);
        $router->post('',          [\YangSheep\CRM\Quote\QuoteController::class, 'store']);
        $router->get('/{id}',      [\YangSheep\CRM\Quote\QuoteController::class, 'show']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Quote\QuoteController::class, 'edit']);
        $router->post('/{id}',     [\YangSheep\CRM\Quote\QuoteController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Quote\QuoteController::class, 'destroy']);
        // 狀態動作（送出 / 變更狀態如作廢、標記已付款）
        $router->post('/{id}/send',   [\YangSheep\CRM\Quote\QuoteController::class, 'send']);
        $router->post('/{id}/status', [\YangSheep\CRM\Quote\QuoteController::class, 'changeStatus']);
        // 公開連結分享期限（Q2／Q3；quotes.edit 於 Controller 內再檢查）：只改期限、立即關閉
        $router->post('/{id}/share',       [\YangSheep\CRM\Quote\QuoteController::class, 'updateShare']);
        $router->post('/{id}/share/close', [\YangSheep\CRM\Quote\QuoteController::class, 'closeShare']);
    });

    // 付款記錄（CRM 域核心模組；對應架構設計 §7.9），排在報價單之後。
    // 群組進入需 payment.view；手動標記已付款（POST mark-paid）於 PaymentController 內再檢查 payment.manage。
    // 公開付款流程（/q/{token}/pay、/pay/callback/{provider} 等）為外部路由，定義於 routes/web.php。
    $router->group('/payments', [
        'middleware' => [PermissionMiddleware::class . ':payment.view'],
    ], function ($router) {
        $router->get('',                [\YangSheep\CRM\Payment\PaymentController::class, 'index']);
        $router->get('/{id}',           [\YangSheep\CRM\Payment\PaymentController::class, 'show']);
        $router->post('/{id}/mark-paid', [\YangSheep\CRM\Payment\PaymentController::class, 'markPaid']);
        // 退款：僅 provider 實作 RefundableProviderInterface 時可用；同樣於 Controller 內檢查 payment.manage。
        $router->post('/{id}/refund',    [\YangSheep\CRM\Payment\PaymentController::class, 'refund']);
    });

    // 電子發票（PayNow REST）。排在付款記錄之後 —— 發票依附於付款。
    //
    // 權限分三級（migration 050 的設計，先前建了權限卻沒有任何程式讀取）：
    //   invoice.view    檢視（含 API log）
    //   invoice.issue   手動開立 / 重試（會產生真實發票）
    //   invoice.cancel  作廢 / 重開（影響法定憑證，額外要求 step-up 再認證）
    //
    // ⚠️ 路由順序有意義：/invoices/logs 必須註冊在 /invoices/{id} 之前，
    // 否則 {id} 會把 "logs" 吃掉（比對是先到先得）。
    $router->group('/invoices', [
        'middleware' => [PermissionMiddleware::class . ':invoice.view'],
    ], function ($router) {
        $router->get('',      [\YangSheep\CRM\EInvoice\InvoiceController::class, 'index']);
        $router->get('/logs', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'logs']);
        $router->get('/{id}', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'show']);
        $router->post('/{id}/print-url', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'printUrl']);
    });

    // 開立 / 重試：會送出真實請求並產生發票號碼。
    $router->group('/invoices', [
        'middleware' => [PermissionMiddleware::class . ':invoice.issue'],
    ], function ($router) {
        $router->post('/{id}/issue', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'issue']);
    });

    // 從付款紀錄直接開立（{id} 是 payment id，不是 invoice id）。
    // 掛在 /payments 之下是因為按鈕在付款明細頁，但權限跟著「開立發票」走 ——
    // 能看付款不等於能開發票。
    $router->group('/payments', [
        'middleware' => [PermissionMiddleware::class . ':invoice.issue'],
    ], function ($router) {
        $router->post('/{id}/issue-invoice', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'issueForPayment']);
    });

    // 作廢 / 重開：不可逆，且國稅局會留下作廢紀錄 —— 與系統設定同級，需 step-up。
    $router->group('/invoices', [
        'middleware' => [PermissionMiddleware::class . ':invoice.cancel', StepUpMiddleware::class],
    ], function ($router) {
        $router->post('/{id}/cancel',  [\YangSheep\CRM\EInvoice\InvoiceController::class, 'cancel']);
        $router->post('/{id}/reissue', [\YangSheep\CRM\EInvoice\InvoiceController::class, 'reissue']);
    });

    // 週期帳務（CRM 域；對應架構設計 §7.8 週期單、§7.9），排在付款記錄之後。
    // 群組進入需 recurring.view；建立/編輯/啟停/立即產生之 recurring.manage 於 Controller 內再檢查。
    $router->group('/recurring', [
        'middleware' => [PermissionMiddleware::class . ':recurring.view'],
    ], function ($router) {
        $router->get('',           [\YangSheep\CRM\Recurring\RecurringController::class, 'index']);
        $router->get('/create',    [\YangSheep\CRM\Recurring\RecurringController::class, 'create']);
        $router->post('',          [\YangSheep\CRM\Recurring\RecurringController::class, 'store']);
        $router->get('/{id}',      [\YangSheep\CRM\Recurring\RecurringController::class, 'show']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Recurring\RecurringController::class, 'edit']);
        $router->post('/{id}',     [\YangSheep\CRM\Recurring\RecurringController::class, 'update']);
        $router->post('/{id}/generate', [\YangSheep\CRM\Recurring\RecurringController::class, 'generateNow']);
        $router->post('/{id}/delete',   [\YangSheep\CRM\Recurring\RecurringController::class, 'destroy']);
    });

    // 通知記錄（CRM 域；對應架構設計 §7.10），排在週期帳務之後。
    // 群組進入需 notification.view；標記已讀 / 重送失敗信走 POST + CSRF。
    $router->group('/notifications', [
        'middleware' => [PermissionMiddleware::class . ':notification.view'],
    ], function ($router) {
        $router->get('',                       [\YangSheep\CRM\Notification\NotificationController::class, 'index']);
        $router->post('/{id}/read',            [\YangSheep\CRM\Notification\NotificationController::class, 'markRead']);
        $router->post('/emails/{id}/resend',   [\YangSheep\CRM\Notification\NotificationController::class, 'resendEmail']);
    });

    // 客戶網站資產（Excel-like 總表；CRM 域）
    $router->group('/websites', [
        'middleware' => [PermissionMiddleware::class . ':websites.view'],
    ], function ($router) {
        $router->get('',           [\YangSheep\CRM\Website\WebsiteController::class, 'index']);
        $router->get('/create',    [\YangSheep\CRM\Website\WebsiteController::class, 'create']);
        $router->post('',          [\YangSheep\CRM\Website\WebsiteController::class, 'store']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Website\WebsiteController::class, 'edit']);
        $router->post('/{id}',     [\YangSheep\CRM\Website\WebsiteController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Website\WebsiteController::class, 'destroy']);
    });

    // 客戶主機資產（Excel-like 總表；CRM 域）
    $router->group('/hosting', [
        'middleware' => [PermissionMiddleware::class . ':hosting.view'],
    ], function ($router) {
        $router->get('',           [\YangSheep\CRM\Hosting\HostingController::class, 'index']);
        $router->get('/create',    [\YangSheep\CRM\Hosting\HostingController::class, 'create']);
        $router->post('',          [\YangSheep\CRM\Hosting\HostingController::class, 'store']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Hosting\HostingController::class, 'edit']);
        $router->post('/{id}',     [\YangSheep\CRM\Hosting\HostingController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Hosting\HostingController::class, 'destroy']);
    });

    // 使用者管理（敏感：要求 step-up 再認證）
    $router->group('/users', [
        'middleware' => [PermissionMiddleware::class . ':user.manage', StepUpMiddleware::class],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\User\UserController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\User\UserController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\User\UserController::class, 'store']);
        $router->get('/{id}',      [\YangSheep\CRM\User\UserController::class, 'edit']);
        $router->get('/{id}/edit', [\YangSheep\CRM\User\UserController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\User\UserController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\User\UserController::class, 'destroy']);
    });

    // 角色權限管理（敏感：要求 step-up 再認證）
    $router->group('/roles', [
        'middleware' => [PermissionMiddleware::class . ':role.manage', StepUpMiddleware::class],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\Role\RoleController::class, 'index']);
        $router->get('/{id}',      [\YangSheep\CRM\Role\RoleController::class, 'edit']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Role\RoleController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Role\RoleController::class, 'update']);
    });

    // 看板管理
    $router->group('/boards', [
        'middleware' => [PermissionMiddleware::class . ':board.manage'],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\Board\BoardController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\Board\BoardController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\Board\BoardController::class, 'store']);
        $router->get('/{id}',     [\YangSheep\CRM\Board\BoardController::class, 'show']);
        $router->get('/{id}/edit',[\YangSheep\CRM\Board\BoardController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Board\BoardController::class, 'update']);
    });

    // 狀態管理
    $router->group('/stages', [
        'middleware' => [PermissionMiddleware::class . ':stage.manage'],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\Stage\StageController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\Stage\StageController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\Stage\StageController::class, 'store']);
        $router->get('/{id}',      [\YangSheep\CRM\Stage\StageController::class, 'edit']);
        $router->get('/{id}/edit', [\YangSheep\CRM\Stage\StageController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Stage\StageController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Stage\StageController::class, 'destroy']);
    });

    // 卡片管理（需 card.create 權限進入，細粒度權限在 Controller 層檢查）
    $router->group('/cards', [
        'middleware' => [PermissionMiddleware::class . ':card.create'],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\Card\CardController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\Card\CardController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\Card\CardController::class, 'store']);
        $router->get('/{id}',     [\YangSheep\CRM\Card\CardController::class, 'show']);
        $router->get('/{id}/edit',[\YangSheep\CRM\Card\CardController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\Card\CardController::class, 'update']);
        $router->post('/{id}/delete', [\YangSheep\CRM\Card\CardController::class, 'destroy']);
        // 卡片留言
        $router->post('/{id}/comments',                [\YangSheep\CRM\Card\CardController::class, 'addComment']);
        $router->post('/{id}/comments/{comment_id}/delete', [\YangSheep\CRM\Card\CardController::class, 'deleteComment']);
    });

    // 系統設定（敏感：要求 step-up 再認證）
    $router->group('/settings', [
        'middleware' => [PermissionMiddleware::class . ':system.settings', StepUpMiddleware::class],
    ], function ($router) {
        $router->get('/modules', [\YangSheep\CRM\Module\ModuleController::class, 'index']);
        $router->get('/modules/data', [\YangSheep\CRM\Module\ModuleController::class, 'data']);
        $router->get('',          [\YangSheep\CRM\Setting\SettingController::class, 'index']);
        $router->post('',         [\YangSheep\CRM\Setting\SettingController::class, 'update']);
        // 寄送 SMTP 測試信（POST + CSRF；對應架構設計 §7.2 SMTP 設定）
        $router->post('/test-email', [\YangSheep\CRM\Setting\SettingController::class, 'testEmail']);
        // 測試 PayNow 電子發票連線（POST + CSRF）
        $router->post('/test-einvoice', [\YangSheep\CRM\Setting\SettingController::class, 'testEinvoiceConnection']);
    });

    // API 設定（敏感：要求 step-up 再認證）
    $router->group('/api-configs', [
        'middleware' => [PermissionMiddleware::class . ':api_config.manage', StepUpMiddleware::class],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\ApiConfig\ApiConfigController::class, 'index']);
        $router->get('/create',   [\YangSheep\CRM\ApiConfig\ApiConfigController::class, 'create']);
        $router->post('',         [\YangSheep\CRM\ApiConfig\ApiConfigController::class, 'store']);
        $router->get('/{id}',     [\YangSheep\CRM\ApiConfig\ApiConfigController::class, 'edit']);
        $router->post('/{id}',    [\YangSheep\CRM\ApiConfig\ApiConfigController::class, 'update']);
    });

    // 媒體檔案管理
    $router->group('/media', [
        'middleware' => [PermissionMiddleware::class . ':system.settings'],
    ], function ($router) {
        $router->get('',           [\YangSheep\CRM\Media\MediaController::class, 'index']);
        $router->post('/delete',   [\YangSheep\CRM\Media\MediaController::class, 'destroy']);
    });

    // 稽核紀錄：操作紀錄與登入紀錄併為同一頁的兩個頁簽（?type=audit|login）。
    // 兩者回答同一個問題「誰在什麼時候做了什麼」，分開會讓人只看了一半。
    $router->group('/audit-logs', [
        'middleware' => [PermissionMiddleware::class . ':audit_log.view'],
    ], function ($router) {
        $router->get('',          [\YangSheep\CRM\AuditLog\AuditLogController::class, 'index']);
    });

    // 排程設定（敏感：會觸發實際出帳，故與系統設定同級 —— 需 step-up 再認證）
    $router->group('/cron', [
        'middleware' => [PermissionMiddleware::class . ':system.settings', StepUpMiddleware::class],
    ], function ($router) {
        $router->get('',                  [\YangSheep\CRM\Console\CronAdminController::class, 'index']);
        $router->post('/trigger',         [\YangSheep\CRM\Console\CronAdminController::class, 'trigger']);
        $router->post('/daily-at',        [\YangSheep\CRM\Console\CronAdminController::class, 'saveDailyAt']);
        $router->post('/token/regenerate',[\YangSheep\CRM\Console\CronAdminController::class, 'regenerateToken']);
        $router->post('/token/revoke',    [\YangSheep\CRM\Console\CronAdminController::class, 'revokeToken']);
    });

});
