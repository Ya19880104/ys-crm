<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Router
{
    private array $routes = [];
    private array $groupStack = [];
    private Request $request;
    private Response $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    public function get(string $path, array|callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array|callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function patch(string $path, array|callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, array|callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * 路由群組 — 支援前綴 + middleware
     *
     * 用法：
     *   $router->group('/admin', ['middleware' => [AuthMiddleware::class]], function ($router) { ... });
     *   $router->group('/admin', function ($router) { ... }); // 無 middleware
     */
    public function group(string $prefix, array|callable $optionsOrCallback, ?callable $callback = null): void
    {
        if (is_callable($optionsOrCallback)) {
            $options = [];
            $callback = $optionsOrCallback;
        } else {
            $options = $optionsOrCallback;
        }

        $this->groupStack[] = [
            'prefix'     => $prefix,
            'middleware'  => $options['middleware'] ?? [],
        ];

        $callback($this);

        array_pop($this->groupStack);
    }

    /**
     * 已註冊的路由（含群組解析後的完整 middleware 串）。
     *
     * 【為何要開放這個】RbacTest 需要證明「某個端點確實落在某個權限群組內」。
     * 用讀原始碼、比對字串先後順序的方式做不到 —— 那只能證明檔案裡某處出現過
     * 權限宣告，把 middleware 從某個群組整個拿掉，測試照樣是綠的（複審 2026-08-17
     * 實測：移除 /stages/reorder 的 stage.manage 之後 RbacTest 仍 45/0）。
     * 唯一可靠的作法是問 Router 本人：這條路由最後掛到了哪些 middleware。
     *
     * @return list<array{method: string, path: string, pattern: string, handler: mixed, middleware: list<string>}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * 分派請求到對應的路由
     */
    public function dispatch(): void
    {
        $method = $this->request->method();
        $path = $this->request->path();

        // 移除尾端斜線（根路徑除外）
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['pattern'], $path);
            if ($params === null) {
                continue;
            }

            $this->request->setRouteParams($params);

            // 執行 middleware chain -> handler
            $handler = $route['handler'];
            $middlewareClasses = $route['middleware'];

            $this->runMiddlewareChain($middlewareClasses, $handler);
            return;
        }

        // 404
        http_response_code(404);
        $view = new View();
        if (file_exists(VIEWS_PATH . '/errors/404.php')) {
            echo $view->render('errors/404', ['title' => '404 Not Found']);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }

    /**
     * 新增路由定義
     */
    private function addRoute(string $method, string $path, array|callable $handler): void
    {
        $fullPrefix = '';
        $middleware = [];

        foreach ($this->groupStack as $group) {
            $fullPrefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }

        $fullPath = $fullPrefix . $path;

        // 將 {param} 轉為正則
        $pattern = $this->pathToPattern($fullPath);

        $this->routes[] = [
            'method'     => $method,
            'path'       => $fullPath,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware'  => $middleware,
        ];
    }

    /**
     * 路徑轉正則模式
     * /admin/cards/{id} -> #^/admin/cards/(?P<id>[^/]+)$#
     */
    private function pathToPattern(string $path): string
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * 嘗試匹配路由，成功回傳參數陣列
     */
    private function matchRoute(string $pattern, string $path): ?array
    {
        if (preg_match($pattern, $path, $matches)) {
            // 只保留具名群組
            return array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
        }
        return null;
    }

    /**
     * 執行 middleware chain 後呼叫 handler
     */
    private function runMiddlewareChain(array $middlewareClasses, array|callable $handler): void
    {
        // 建立從內到外的 chain
        $next = function () use ($handler) {
            $this->callHandler($handler);
        };

        // 從最後一個 middleware 往前包裝
        foreach (array_reverse($middlewareClasses) as $middlewareClass) {
            $params = [];
            // 支援 MiddlewareClass:param 格式
            if (str_contains($middlewareClass, ':')) {
                [$middlewareClass, $paramStr] = explode(':', $middlewareClass, 2);
                $params = explode(',', $paramStr);
            }

            $currentNext = $next;
            $next = function () use ($middlewareClass, $currentNext, $params) {
                $middleware = new $middlewareClass();
                $middleware->handle($this->request, $currentNext, ...$params);
            };
        }

        $next();
    }

    /**
     * 呼叫路由 handler
     */
    private function callHandler(array|callable $handler): void
    {
        if (is_callable($handler)) {
            $handler($this->request, $this->response);
            return;
        }

        [$controllerClass, $method] = $handler;

        if (!class_exists($controllerClass)) {
            throw new \RuntimeException("Controller 不存在: {$controllerClass}");
        }

        $controller = new $controllerClass($this->request, $this->response);

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("方法不存在: {$controllerClass}::{$method}");
        }

        $controller->$method();
    }
}
