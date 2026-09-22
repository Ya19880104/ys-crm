<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * Zero Trust：全域安全回應標頭。
 *
 * 於 App::run() 早期送出（dispatch 之前）。
 */
final class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');

        // NOINDEX 為全站預設。CRM 沒有任何一頁該被搜尋引擎收錄 ——
        // 後台自不必說，公開報價頁 `/q/{token}` 雖然是給客戶看的，
        // 但那是「知道網址才能看」的分享連結，被索引等同外洩客戶報價內容。
        //
        // 用 X-Robots-Tag 而不是只靠 robots.txt：robots.txt 只是「請不要爬」，
        // 而且擋不住已經拿到網址的爬蟲把它列入索引；X-Robots-Tag 是每個回應
        // 都帶的明確指令，對 PDF／圖片等非 HTML 回應同樣有效。
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        // 關閉舊式 XSS auditor（現代瀏覽器改以 CSP 防護，auditor 反成漏洞）
        header('X-XSS-Protection: 0');
        // 移除預設 X-Powered-By（資訊洩漏）
        header_remove('X-Powered-By');

        // Content-Security-Policy：XSS 第二道防線（即使某處轉義漏失也限制可執行來源）。
        // 現況沿用 Tailwind Play CDN（執行期需 'unsafe-eval'）+ Alpine/qrcode（cdn.jsdelivr.net）+
        // 大量 inline script/style（需 'unsafe-inline'），故 script/style 放寬至這些來源；
        // 但仍以 object-src 'none'、base-uri 'none'、frame-ancestors 'self' 擋下高風險載體。
        // 註：刻意「不設」form-action——金流導轉式付款需 POST 表單到 PayUni/Shopline 外部網域，
        //     設 form-action 'self' 會擋掉真實付款。img 允許 data:（簽名 PNG data URL）。
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            . "img-src 'self' data:; "
            . "font-src 'self' data: https://cdn.jsdelivr.net; "
            . "connect-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'none'; "
            . "frame-ancestors 'self'";
        header('Content-Security-Policy: ' . $csp);

        // HSTS：僅在 HTTPS 下送出（含經反向代理/CDN 的轉發判斷）
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
