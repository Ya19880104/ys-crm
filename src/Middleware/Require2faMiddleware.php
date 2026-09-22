<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;

/**
 * 零信任：強制 admin 啟用 2FA。
 *
 * 已登入但尚未啟用 TOTP 的使用者，除「2FA 設定頁」與「登出」外，
 * 一律導向 /admin/2fa/setup，直到完成啟用。
 *
 * 由 ADMIN_REQUIRE_2FA 控制（預設啟用；設為 'false' 可關閉強制）。
 *
 * 掛載位置：/admin group，須排在 AuthMiddleware 之後（確保已登入）。
 * 以路徑排除保證設定頁 / 登出可達，避免重導迴圈。
 */
final class Require2faMiddleware extends Middleware
{
    /** 即使尚未啟用 2FA 也必須可達的路徑（避免迴圈 / 鎖死） */
    private const ALLOWLIST = [
        '/admin/2fa/setup',
        '/logout',
    ];

    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        // 強制開關（預設 true；明確設為 'false' 才關閉）
        if (($_ENV['ADMIN_REQUIRE_2FA'] ?? 'true') === 'false') {
            $next();
            return;
        }

        $user = Session::get('user');
        if ($user === null) {
            // 未登入交給 AuthMiddleware 處理；此處不攔截
            $next();
            return;
        }

        // 排除設定頁與登出（去尾端斜線後比對）
        $path = rtrim($request->path(), '/');
        if ($path === '') {
            $path = '/';
        }
        if (in_array($path, self::ALLOWLIST, true)) {
            $next();
            return;
        }

        if ($this->hasTwoFactor($user)) {
            $next();
            return;
        }

        // 尚未啟用 2FA → 強制導向設定頁
        if ($request->isAjax()) {
            (new Response())->json([
                'error'   => '需要兩階段驗證',
                'message' => '請先啟用兩階段驗證。',
                'redirect' => '/admin/2fa/setup',
            ], 403);
            return;
        }

        Session::flash('info', '基於安全要求，請先啟用兩階段驗證。');
        (new Response())->redirect('/admin/2fa/setup');
    }

    /**
     * 判斷使用者是否已啟用 2FA。
     *
     * session 快照若顯示已啟用即放行（快路徑）；若顯示未啟用，再以 DB 為準
     * 重新確認，避免「剛啟用但 session 快照仍為舊值」造成的重導迴圈，
     * 同時不讓過期的 enabled 快照成為繞過點（停用情境由 DB 收斂）。
     */
    private function hasTwoFactor(array $user): bool
    {
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            return true;
        }

        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }

        try {
            $enabled = (int) Database::getInstance()->fetchColumn(
                "SELECT totp_enabled FROM {prefix}users WHERE id = :id LIMIT 1",
                ['id' => $uid]
            );
            if ($enabled === 1) {
                // 回寫 session 快照，避免後續每請求都打 DB
                $user['totp_enabled'] = 1;
                Session::set('user', $user);
                return true;
            }
        } catch (\Throwable) {
            // DB 異常時採安全預設：要求設定（fail-closed）
            return false;
        }

        return false;
    }
}
