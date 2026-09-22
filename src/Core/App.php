<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

use YangSheep\CRM\Install\InstallationState;

class App
{
    private Router $router;
    private Request $request;
    private Response $response;
    private ?InstallationState $installationState;

    public function __construct(?InstallationState $installationState = null)
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router($this->request, $this->response);
        $this->installationState = $installationState;
    }

    public function run(): void
    {
        // 🔴 【時區必須排在最前面】原本是先 Session::start() 再設時區，
        // 而 Session::start() 會安裝 DbSessionHandler、進而建立 DB 連線 ——
        // 那條連線在建立時 PHP 還停在 php.ini 的預設時區（本機是 UTC），
        // 於是 Database::syncTimeZone() 把連線設成 +00:00，整個 web 路徑的
        // NOW() 全部差 8 小時。CLI 路徑因為順序相反而是對的，
        // 所以症狀只出現在網頁操作，更難察覺。
        //
        // 時區是整個 process 的前提，不是「其中一項設定」，必須第一個做。
        $appConfig = require CONFIG_PATH . '/app.php';
        date_default_timezone_set($appConfig['timezone'] ?? 'Asia/Taipei');

        // 啟動 Session
        Session::start($this->installationState);

        // Zero Trust：全域安全回應標頭
        SecurityHeaders::send();

        // 載入路由
        $this->loadRoutes();

        // 分派請求
        try {
            $this->router->dispatch();
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function loadRoutes(): void
    {
        $router = $this->router;

        $requestPath = $this->request->path();
        $isInstallRoute = str_starts_with($requestPath, '/install');
        $isInstalled = $this->installationState === InstallationState::Installed;
        $isUninstalled = $this->installationState === InstallationState::Uninstalled;

        // 安裝路由（僅在未安裝時載入；已安裝後完全阻擋安裝路由）
        if ($isUninstalled && file_exists(ROUTES_PATH . '/install.php')) {
            require ROUTES_PATH . '/install.php';
        }

        // 已安裝但使用者嘗試存取 /install → 導回首頁
        if ($isInstalled && $isInstallRoute) {
            (new Response())->redirect('/');
            return;
        }

        // 其餘路由（已安裝時載入）
        if ($isInstalled) {
            if (file_exists(ROUTES_PATH . '/web.php')) {
                require ROUTES_PATH . '/web.php';
            }
            if (file_exists(ROUTES_PATH . '/admin.php')) {
                require ROUTES_PATH . '/admin.php';
            }
            if (file_exists(ROUTES_PATH . '/api.php')) {
                require ROUTES_PATH . '/api.php';
            }
        }
    }

    private function handleException(\Throwable $e): void
    {
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        if ($this->request->isAjax()) {
            $data = ['error' => '伺服器內部錯誤'];
            if ($debug) {
                $data['message'] = $e->getMessage();
                $data['trace'] = $e->getTraceAsString();
            }
            $this->response->json($data, 500);
            return;
        }

        http_response_code(500);

        if ($debug) {
            echo '<h1>Error</h1>';
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        } else {
            $view = new View();
            if (file_exists(VIEWS_PATH . '/errors/500.php')) {
                echo $view->render('errors/500', ['title' => '伺服器錯誤']);
            } else {
                echo '<h1>伺服器內部錯誤</h1><p>請稍後再試。</p>';
            }
        }
    }
}
