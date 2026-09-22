<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\User\UserRepository;

/**
 * 管理員平面認證中介層（Zero Trust 對齊 CustomerAuthMiddleware）。
 *
 * 兩層強制：
 *   1) session 必須有 `user`（已登入）。
 *   2) 低頻每請求重查 DB：該 admin user 仍存在且 status=active，否則撤銷 session 並踢出。
 *      ── 對齊 portal 端「帳號被停用 / 刪除 → 下個請求即被踢出」。
 *
 * 效能取捨：單次 indexed PK 查詢成本低，但管理後台每頁多次 AJAX 仍可能放大。
 * 故以 session 內 `_admin_chk_at` 時戳節流：每 RECHECK_INTERVAL 秒至多查一次 DB。
 * 此延遲（最多 N 秒）可接受，因 DbSessionHandler 的撤銷（kill-switch）為即時生效，
 * 本重查僅補「帳號被改 status 但未走 kill-switch 撤銷」這條路徑。務必 fail-closed：
 * 查不到 / 非 active 即 revokeCurrent + 導 /login?reason=expired。
 */
class AuthMiddleware extends Middleware
{
    /** 帳號狀態重查間隔（秒）。0 = 每請求都查。 */
    private const RECHECK_INTERVAL = 30;

    /**
     * 驗證使用者是否已登入
     * 未登入時：AJAX 回傳 401 JSON，一般請求導向登入頁
     */
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        if (!Session::has('user')) {
            $this->reject($request, '/login', '請先登入。');
            return;
        }

        // 低頻每請求重查帳號狀態（Zero Trust，fail-closed）。
        if (!$this->recheckActive()) {
            Session::revokeCurrent();
            Session::destroy();
            $this->reject($request, '/login?reason=expired', '登入狀態已失效，請重新登入。');
            return;
        }

        $next();
    }

    /**
     * 重查目前登入 admin 的帳號狀態是否仍有效（存在 + status=active）。
     * 以 session 內時戳節流，每 RECHECK_INTERVAL 秒至多查一次。
     *
     * fail-closed：取不到 user id / 查無此人 / status 非 active → 回 false。
     * DB 異常時亦回 false（寧可踢出也不放行不確定狀態）。
     */
    private function recheckActive(): bool
    {
        $user = Session::get('user');
        $uid = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($uid <= 0) {
            return false;
        }

        // 節流：距上次檢查未達間隔且上次為通過 → 沿用結果，省一次 DB 查詢。
        $now = time();
        $lastAt = (int) (Session::get('_admin_chk_at') ?? 0);
        if (self::RECHECK_INTERVAL > 0 && $lastAt > 0 && ($now - $lastAt) < self::RECHECK_INTERVAL) {
            return true;
        }

        try {
            $row = (new UserRepository())->findById($uid);
        } catch (\Throwable) {
            // DB 不可用：fail-closed（不放行不確定狀態）。
            return false;
        }

        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            return false;
        }

        Session::set('_admin_chk_at', $now);
        return true;
    }

    /**
     * 拒絕存取：AJAX 回 401 JSON；一般請求導向登入頁。
     */
    private function reject(Request $request, string $redirectTo, string $message): void
    {
        if ($request->isAjax()) {
            (new Response())->json([
                'error'   => '未授權',
                'message' => $message,
            ], 401);
            return;
        }

        Session::flash('error', $message);
        (new Response())->redirect($redirectTo);
    }
}
