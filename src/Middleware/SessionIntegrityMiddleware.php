<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;

/**
 * Zero Trust：每請求 Session 完整性驗證（IP / UA 指紋綁定）。
 *
 * 閒置逾時、絕對逾時、伺服器端撤銷已由 DbSessionHandler::read() 在每次請求強制；
 * 本中介層負責「綁定指紋」——登入當下記錄的 IP/UA 若於後續請求變動（疑似 cookie 竊取/
 * session 劫持），即撤銷該 session 並要求重新登入。
 *
 * 必須排在 AuthMiddleware 之前。未登入（無 _sec）時為 no-op。
 */
final class SessionIntegrityMiddleware extends Middleware
{
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $sec = Session::get('_sec');

        // 🔴 fail-closed：已登入（session 內有 user）卻沒有 _sec 指紋，代表這個 session
        // 不是經由正常登入流程建立的（例如舊版 session、或被植入的 session）。
        // 原本的寫法只在 _sec 存在時檢查，這種 session 會直接被放行 —— 正是想擋的情況。
        if (!is_array($sec) && Session::get('user') !== null) {
            Session::revokeCurrent();
            Session::destroy();
            Session::flash('error', '登入狀態異常，請重新登入。');
            (new \YangSheep\CRM\Core\Response())->redirect('/login');
            return;
        }

        if (is_array($sec)) {
            if (!Session::bindingMatches($request, $sec)) {
                Session::revokeCurrent();
                Session::destroy();

                if ($request->isAjax()) {
                    (new Response())->json([
                        'error'   => 'session_invalid',
                        'message' => '連線環境已變更，基於安全考量請重新登入。',
                    ], 401);
                    return;
                }
                (new Response())->redirect('/login?reason=security');
                return;
            }
        }

        $next();
    }
}
