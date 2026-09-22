<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Role\RoleService;

class PermissionMiddleware extends Middleware
{
    /**
     * 權限檢查中介層
     *
     * 路由定義時傳入所需的 permission code：
     *   PermissionMiddleware:users.manage
     *
     * 也支援「任一即可」（以 `|` 分隔）：
     *   PermissionMiddleware:card.edit_all|card.edit_own
     *
     * 【為何需要 any-of】（複審 2026-08-17）
     * `_all` / `_own` 是同一個動作的兩種範圍，不是兩個動作。路由層只寫 `_all`，
     * 會讓只有 `_own` 的角色（seed 的 staff 就是）在**還沒走到歸屬判斷之前**
     * 就被 403 —— 那不是「權限不足」，是把可以做的事擋掉了。
     *
     * 🔴 這裡放寬到 any-of 的**前提**是：Controller／Service 必須真的做歸屬檢查。
     * 只改路由不補歸屬，等於把 `_own` 直接升級成 `_all`。
     * 對應的檢查在 CardDragService::canDrag() 與 CardService::canEdit()。
     */
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $permissionCode = $params[0] ?? null;

        if ($permissionCode === null) {
            // 未指定權限碼，直接通過
            $next();
            return;
        }

        $user = Session::get('user');
        if ($user === null) {
            if ($request->isAjax()) {
                (new Response())->json([
                    'error'   => '未授權',
                    'message' => '請先登入。',
                ], 401);
                return;
            }
            (new Response())->redirect('/login');
        }

        $codes = array_values(array_filter(
            array_map('trim', explode('|', (string) $permissionCode)),
            static fn(string $c): bool => $c !== ''
        ));

        $roleService   = new RoleService();
        $hasPermission = false;
        foreach ($codes as $code) {
            if ($roleService->hasPermission((int) $user['id'], $code)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            if ($request->isAjax()) {
                (new Response())->json([
                    'error'   => '權限不足',
                    'message' => '您沒有執行此操作的權限。',
                ], 403);
                return;
            }

            Session::flash('error', '您沒有執行此操作的權限。');
            (new Response())->redirect('/admin/dashboard');
        }

        $next();
    }
}
