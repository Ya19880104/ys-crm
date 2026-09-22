<?php
declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Session;

/** Both password-only and completed 2FA logins use the same persistence boundary. */
final class AdminLoginSession
{
    public static function establish(array $user, Request $request, bool $exclusive): void
    {
        $uid = (int) ($user['id'] ?? 0);
        $establish = static function () use ($user, $uid, $request): void {
            if ($uid <= 0 || !session_regenerate_id(true)) {
                throw new \RuntimeException('無法更新登入狀態。');
            }
            Session::bindAuth('admin', $uid, $request->ip(), $request->userAgent(), true);
            Session::set('user', $user);
            Session::remove('_2fa_pending');
            Session::persistAuth();
        };
        try {
            if ($exclusive) {
                Session::enforceExclusiveLogin('admin', $uid, $establish);
            } else {
                $establish();
            }
        } catch (\Throwable $e) {
            Session::invalidateFailedLogin();
            throw new \RuntimeException('登入狀態無法建立，請稍後再試。', 0, $e);
        }
    }
}
