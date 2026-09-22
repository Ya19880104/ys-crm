<?php

/**
 * AJAX API 路由（回傳 JSON）
 *
 * @var \YangSheep\CRM\Core\Router $router
 */

use YangSheep\CRM\Middleware\SessionIntegrityMiddleware;
use YangSheep\CRM\Middleware\AuthMiddleware;
use YangSheep\CRM\Middleware\Require2faMiddleware;
use YangSheep\CRM\Middleware\CsrfMiddleware;
use YangSheep\CRM\Middleware\PermissionMiddleware;

// 與 routes/admin.php 的 /admin 群組對齊四層：
// SessionIntegrity（指紋/逾時/撤銷）→ Auth（已登入）→ Require2fa（強制 2FA）→ Csrf。
// 消除 session 劫持在 API 平面繞過指紋檢查與 2FA 閘道的旁路。
//
// 🔴 這四層只回答「你是誰、session 有沒有被劫持」，**不回答「你能做什麼」**。
// 稽核（2026-08-16）指出本平面原本缺少 RBAC：任何已登入的後台帳號——包含只有
// 檢視權限的角色——都能直接 PATCH 卡片或重排 stage。後台頁面看不到按鈕不算防護，
// 攻擊者是直接打 endpoint。故每個 endpoint 都必須明確宣告所需權限。
$router->group('/api', [
    'middleware' => [
        SessionIntegrityMiddleware::class,
        AuthMiddleware::class,
        Require2faMiddleware::class,
        CsrfMiddleware::class,
    ],
], function ($router) {
    // ── 讀取 ──
    $router->group('', [
        'middleware' => [PermissionMiddleware::class . ':card.view_public'],
    ], function ($router) {
        // 看板卡片列表
        $router->get('/boards/{id}/cards', [\YangSheep\CRM\Board\BoardController::class, 'cards']);
    });

    // ── 卡片拖拉（移動所屬 stage）──
    // 🔴 `_all` 與 `_own` 是同一動作的兩種範圍，路由層必須兩者皆放行，
    // 真正的分野在 CardDragService::canDrag()（drag_all 全放行、drag_own 限 assignee）。
    // 只寫 card.drag_all 會讓 seed 的 staff 角色（只有 drag_own）在還沒走到
    // 歸屬判斷之前就被 403 —— 那是把該做的事擋掉，不是防護。
    $router->group('', [
        'middleware' => [PermissionMiddleware::class . ':card.drag_all|card.drag_own'],
    ], function ($router) {
        $router->post('/cards/{id}/move', [\YangSheep\CRM\Card\CardController::class, 'move']);
    });

    // ── 卡片內容更新 ──
    // 同上；歸屬檢查在 CardController::apiUpdate() 內以 CardService::canEdit() 執行。
    $router->group('', [
        'middleware' => [PermissionMiddleware::class . ':card.edit_all|card.edit_own'],
    ], function ($router) {
        $router->patch('/cards/{id}', [\YangSheep\CRM\Card\CardController::class, 'apiUpdate']);
    });

    // ── 階段排序（改動看板結構，屬管理操作）──
    $router->group('', [
        'middleware' => [PermissionMiddleware::class . ':stage.manage'],
    ], function ($router) {
        $router->post('/stages/reorder', [\YangSheep\CRM\Stage\StageController::class, 'reorder']);
    });
});
