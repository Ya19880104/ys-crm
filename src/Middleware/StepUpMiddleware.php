<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;

/**
 * 零信任：敏感操作 step-up 再認證閘道。
 *
 * 敏感路由要求使用者「最近曾再認證」——`_stepup_at` 須在 STEPUP_TTL（預設 600 秒）內，
 * 否則導向 /admin/reauth?return=<目前路徑>，完成密碼（+ TOTP）再認證後才放行。
 *
 * 掛載於敏感子群組（settings / api-configs / users / roles），
 * 疊加在既有 PermissionMiddleware 之外（先有權限，再要求新鮮的再認證）。
 */
final class StepUpMiddleware extends Middleware
{
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $ttl = (int) ($_ENV['STEPUP_TTL'] ?? 600);
        if ($ttl < 0) {
            $ttl = 0;
        }

        $stepupAt = (int) Session::get('_stepup_at', 0);

        if ($stepupAt > 0 && (time() - $stepupAt) <= $ttl) {
            $next();
            return;
        }

        // 需要再認證 → 帶上目前完整路徑（含 query）作為 return
        $return = $this->currentPathWithQuery($request);

        if ($request->isAjax()) {
            (new Response())->json([
                'error'    => '需要再認證',
                'message'  => '此為敏感操作，請重新驗證身分。',
                'redirect' => '/admin/reauth?return=' . rawurlencode($return),
            ], 403);
            return;
        }

        (new Response())->redirect('/admin/reauth?return=' . rawurlencode($return));
    }

    /**
     * 取得目前請求的站內路徑（含 query string），供再認證後導回。
     * 僅取 path + query，確保為站內相對路徑（再認證端另會再次過濾）。
     */
    private function currentPathWithQuery(Request $request): string
    {
        $path = $request->path();
        if ($request->method() === 'POST' && $path === '/admin/settings/test-einvoice') {
            return '/admin/settings?tab=' . rawurlencode('電子發票');
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        if (is_string($qs) && $qs !== '') {
            return $path . '?' . $qs;
        }
        return $path;
    }
}
