<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Request
{
    private array $get;
    private array $post;
    private array $server;
    private array $files;
    private array $cookies;
    private array $routeParams = [];

    public function __construct()
    {
        $this->get     = $_GET;
        $this->post    = $_POST;
        $this->server  = $_SERVER;
        $this->files   = $_FILES;
        $this->cookies = $_COOKIE;

        // 解析 JSON request body（fetch API 用 application/json 發送）
        $contentType = $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            // 限制 JSON body 大小（預設 2MB，防 DoS）
            $maxBodySize = (int) ($_ENV['MAX_JSON_BODY_SIZE'] ?? 2 * 1024 * 1024);
            $raw = file_get_contents('php://input', false, null, 0, $maxBodySize + 1);
            if ($raw && strlen($raw) <= $maxBodySize) {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $this->post = array_merge($this->post, $json);
                }
            }
        }
    }

    public function method(): string
    {
        // 支援 _method 覆蓋（僅允許 PATCH/DELETE/PUT）
        $override = $this->post['_method'] ?? null;
        if ($override !== null) {
            $allowed = ['PATCH', 'DELETE', 'PUT'];
            $upper = strtoupper($override);
            if (in_array($upper, $allowed, true)) {
                return $upper;
            }
        }
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = $path ?: '/';

        // Nginx rewrite 可能把 REQUEST_URI 設成 /index.php 或 /index.php/path
        // 移除前綴 /index.php 以還原真正的路由路徑
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        return $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * 取得訪客 IP。
     *
     * 實際策略集中在 Core\ClientIpResolver：那裡定義了可選的偵測模式
     * （REMOTE_ADDR / X-Forwarded-For / X-Real-IP / CF-Connecting-IP / …），
     * 並能回報「每一種模式現在會得到什麼」供設定頁診斷。
     *
     * 🔴 預設不採信任何 header。實測（2026-09-02）舊版無條件採信 X-Forwarded-For，
     * 而本站所有請求的 REMOTE_ADDR 都是同一個代理位址 —— 於是任何人送一個偽造的
     * XFF 就能決定自己被記成哪個 IP，登入速率限制形同虛設。
     */
    public function ip(): string
    {
        return ClientIpResolver::resolve($this->server)['ip'];
    }

    /**
     * 這個 IP 是不是真的訪客位址？
     *
     * false 代表只拿得到反向代理的位址（前端尚未插入真實 IP，或未選對偵測模式）。
     * UI 應據此明白標示，而不是把 proxy 位址當成訪客位址顯示。
     */
    public function clientIpIsReal(): bool
    {
        return ClientIpResolver::resolve($this->server)['trusted_for_throttle'];
    }

    /**
     * 僅在 resolver 證明此 IP 可作來源鑑別時，才提供給 IP/global rate rail。
     * Audit log 仍可用 ip() 記下可追查的候選值與 resolver provenance。
     */
    public function clientIpForThrottle(): ?string
    {
        $resolved = ClientIpResolver::resolve($this->server);
        return $resolved['trusted_for_throttle'] ? $resolved['ip'] : null;
    }


    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function isAjax(): bool
    {
        // /api/* 一律視為 API 請求：這些端點只回 JSON，若因為呼叫端沒帶
        // X-Requested-With 或 Accept 而被判成一般頁面請求，權限不足時會回傳
        // 302 導向登入頁而非 403 JSON —— 呼叫端會把那份 HTML 當成資料解析，
        // 錯誤原因也就被藏起來了。
        if (str_starts_with($this->path(), '/api/')) {
            return true;
        }

        return ($this->header('X-Requested-With') === 'XMLHttpRequest')
            || (str_contains($this->header('Accept') ?? '', 'application/json'));
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }
}
