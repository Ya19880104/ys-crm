<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;

class RateLimitMiddleware extends Middleware
{
    /**
     * 預設最大請求次數（每個時間窗口）
     */
    private const DEFAULT_MAX_ATTEMPTS = 60;

    /**
     * 預設時間窗口（秒）
     */
    private const DEFAULT_WINDOW = 60;

    /**
     * 通用頻率限制
     *
     * 支援透過 params 傳入限制設定：
     *   RateLimitMiddleware:action_name,max_attempts,window_seconds
     *
     * 例如：RateLimitMiddleware:login,5,300
     */
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $action      = $params[0] ?? 'default';
        $maxAttempts = isset($params[1]) ? (int) $params[1] : self::DEFAULT_MAX_ATTEMPTS;
        $window      = isset($params[2]) ? (int) $params[2] : self::DEFAULT_WINDOW;

        // 只有 resolver 證明可作 throttle source 的位址才能成為 IP rail。
        // 其他情況退回目前 session 的固定 bucket，避免攻擊者輪替可偽造 header
        // 就無限建立新 key。Session 儲存本身已按訪客隔離，不需再信任來源位址。
        $trustedIp = $request->clientIpForThrottle();
        $key       = $trustedIp !== null
            ? "rate_limit:{$action}:ip:{$trustedIp}"
            : "rate_limit:{$action}:session";

        // 使用 Session 追蹤頻率限制
        $attempts = Session::get($key, []);
        $now      = time();

        // 清除過期的記錄
        $attempts = array_filter($attempts, fn(int $timestamp) => ($now - $timestamp) < $window);

        if (count($attempts) >= $maxAttempts) {
            if ($request->isAjax()) {
                (new Response())->json([
                    'error'   => '請求過於頻繁',
                    'message' => '請稍後再試。',
                ], 429);
                return;
            }

            http_response_code(429);
            Session::flash('error', '請求過於頻繁，請稍後再試。');
            (new Response())->redirect($request->header('Referer') ?? '/');
        }

        // 記錄此次請求
        $attempts[] = $now;
        Session::set($key, array_values($attempts));

        $next();
    }
}
