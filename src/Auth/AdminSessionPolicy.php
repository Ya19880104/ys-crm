<?php
declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Role\RoleService;

/** Read-only authorization for public routes; rejection must not redirect a customer. */
final class AdminSessionPolicy
{
    public static function allows(Request $request, string $permission): bool
    {
        $user = Session::get('user');
        $binding = Session::get('_sec');
        $uid = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($uid <= 0 || !is_array($binding)
            || (int) ($binding['uid'] ?? 0) !== $uid
            || ($binding['utype'] ?? '') !== 'admin'
            || Session::get('_2fa_pending') !== null
            || !Session::bindingMatches($request, $binding)) {
            return false;
        }
        try {
            $current = Database::getInstance()->fetch(
                'SELECT status, totp_enabled FROM {prefix}users WHERE id = :id', ['id' => $uid]
            );
            if (!$current || $current['status'] !== 'active') {
                return false;
            }
            if (($_ENV['ADMIN_REQUIRE_2FA'] ?? 'true') !== 'false'
                && (int) ($current['totp_enabled'] ?? 0) !== 1) {
                return false;
            }
            return (new RoleService())->hasPermission($uid, $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
