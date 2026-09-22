<?php

/**
 * 安裝路由（僅在未安裝時載入）
 *
 * @var \YangSheep\CRM\Core\Router $router
 */

use YangSheep\CRM\Install\InstallController;
use YangSheep\CRM\Middleware\CsrfMiddleware;

$router->get('/install',              [InstallController::class, 'index']);
$router->get('/install/step-db',      [InstallController::class, 'stepDb']);
$router->get('/install/step-migrate', [InstallController::class, 'stepMigrate']);
$router->get('/install/step-seed',    [InstallController::class, 'stepSeed']);
$router->get('/install/step-admin',   [InstallController::class, 'stepAdmin']);
$router->get('/install/step-config',  [InstallController::class, 'stepConfig']);
$router->get('/install/complete',     [InstallController::class, 'complete']);

// 安裝期間仍是匿名 session；所有會改檔案、schema、帳號或設定的端點都必須
// 經 Router 實際執行 CSRF gate，不能只呼叫一個回傳 bool 卻忽略結果的 helper。
$router->group('', ['middleware' => [CsrfMiddleware::class]], function ($router) {
    $router->post('/install/move-structure', [InstallController::class, 'moveStructure']);
    $router->post('/install/step-db',        [InstallController::class, 'testConnection']);
    $router->post('/install/step-migrate',   [InstallController::class, 'runMigrations']);
    $router->post('/install/step-seed',      [InstallController::class, 'runSeeders']);
    $router->post('/install/step-admin',     [InstallController::class, 'createAdmin']);
    $router->post('/install/step-config',    [InstallController::class, 'saveConfig']);
});
