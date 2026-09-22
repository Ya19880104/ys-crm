<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;

class CsrfMiddleware extends Middleware
{
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        // 只驗證寫入請求
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->input('_csrf_token')
                ?? $request->header('X-CSRF-Token');

            if (!Csrf::validate($token)) {
                if ($request->isAjax()) {
                    (new Response())->json(['error' => 'CSRF Token 驗證失敗'], 403);
                    return;
                }
                http_response_code(403);
                echo '<h1>403 Forbidden</h1><p>CSRF Token 驗證失敗，請重新整理頁面。</p>';
                return;
            }
        }

        $next();
    }
}
