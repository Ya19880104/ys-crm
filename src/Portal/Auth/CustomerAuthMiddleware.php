<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal\Auth;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Portal\CustomerUserRepository;

/**
 * 客戶 Portal 認證中介層（Zero Trust 客戶平面守門）。
 *
 * 每請求強制（不預設信任登入過一次就放行）：
 *   1) session 必須有 `_customer_user_id` 且 `_sec.utype === 'customer'`（與管理員平面隔離）。
 *      ── 管理員 session（`user` key、`_sec.utype='admin'`）在此被拒絕；客戶 session
 *         亦因不含 `user` key 而無法通過 admin 的 AuthMiddleware/PermissionMiddleware。
 *   2) 重查 DB：帳號存在、is_active=1、customer_id 與 session 一致。
 *      任一不符 → 撤銷 session（DbSessionHandler 已可撤銷）+ 導回 /portal/login。
 *      （帳號被管理員/owner 停用、或客戶被刪除 → 下一個請求即被踢出。）
 *   3) （可選）能力 scope gate：以 middleware 參數帶入所需 scope（如 :team），
 *      member 無此 scope → 403。owner 恆放行（'team' 亦僅 owner）。
 *
 * 註：閒置/絕對逾時、伺服器端撤銷由 DbSessionHandler::read() 在每請求強制（與管理員共用）；
 *     IP/UA 指紋比對由 SessionIntegrityMiddleware 處理（對 utype 無關，兩平面通用）。
 *     本中介層補上「客戶帳號 DB 有效性 + 平面隔離 + scope」三項。
 */
final class CustomerAuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $requiredScope = isset($params[0]) ? (string) $params[0] : null;

        // (1) session 層：必須是「客戶」平面且已登入。
        $customerUserId = (int) (Session::get(CustomerGuard::SESSION_USER_ID) ?? 0);
        $sessionCustomerId = (int) (Session::get(CustomerGuard::SESSION_CUSTOMER) ?? 0);
        $utype = Session::get('_sec')['utype'] ?? null;

        if ($customerUserId <= 0 || $sessionCustomerId <= 0 || $utype !== CustomerGuard::SESSION_TYPE) {
            $this->reject($request, '/portal/login');
            return;
        }

        // (2) 每請求重查 DB（Zero Trust）：帳號有效性 + customer_id 一致性。
        $user = (new CustomerUserRepository())->findById($customerUserId);
        if (
            $user === null
            || (int) ($user['is_active'] ?? 0) !== 1
            || (int) $user['customer_id'] !== $sessionCustomerId
        ) {
            // 帳號失效 / 被停用 / 客戶上下文不符 → 撤銷並登出。
            Session::revokeCurrent();
            Session::destroy();
            $this->reject($request, '/portal/login?reason=expired');
            return;
        }

        // 以最新 DB 值刷新 context 快照（避免停用以外的權限/名稱變更滯後一拍）。
        Session::set(CustomerGuard::SESSION_CONTEXT, CustomerGuard::makeContext($user));

        // (3) 能力 scope gate（若路由要求特定 scope）。
        if ($requiredScope !== null && $requiredScope !== '' && !CustomerGuard::can($requiredScope)) {
            $this->forbidden($request);
            return;
        }

        $next();
    }

    /**
     * 未登入 / session 無效：AJAX 回 401 JSON；一般請求導向登入頁。
     */
    private function reject(Request $request, string $redirectTo): void
    {
        if ($request->isAjax()) {
            (new Response())->json(['error' => '未授權', 'message' => '請先登入客戶專區。'], 401);
            return;
        }
        Session::flash('error', '請先登入客戶專區。');
        (new Response())->redirect($redirectTo);
    }

    /**
     * 已登入但無權限：AJAX 回 403 JSON；一般請求導向 portal 首頁。
     */
    private function forbidden(Request $request): void
    {
        if ($request->isAjax()) {
            (new Response())->json(['error' => '權限不足', 'message' => '您沒有存取此功能的權限。'], 403);
            return;
        }
        Session::flash('error', '您沒有存取此功能的權限。');
        (new Response())->redirect('/portal');
    }
}
