<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal\Auth;

use YangSheep\CRM\Core\Session;

/**
 * 客戶 Portal 守衛（Zero Trust 客戶平面的單一真相來源）。
 *
 * 與管理員平面完全隔離：
 *   - 管理員 session key = `user`（整個 user 陣列）+ `_sec.utype='admin'`。
 *   - 客戶 session key   = `_customer_user_id` / `_customer_id` + `_sec.utype='customer'`。
 *   兩者為不同 session key；客戶 session 絕不含 `user`，故 admin 的 AuthMiddleware /
 *   PermissionMiddleware（檢查 Session::has('user')）天然拒絕客戶 session 進入 /admin/*。
 *   反向：CustomerAuthMiddleware 嚴格要求 `_customer_user_id` 且 `_sec.utype==='customer'`，
 *   故 admin session 也無法被當成客戶。
 *
 * 本類別「不查 DB」——僅讀取登入時寫入 session 的快照（id / customer_id / role / scopes）。
 * 每請求的「帳號仍有效（is_active / 未撤銷）」由 CustomerAuthMiddleware 重查 DB 強制
 * （符合 Zero Trust「每請求顯式驗證」）。
 */
final class CustomerGuard
{
    /** Session keys（客戶平面專用，與管理員 `user` / `_2fa_pending` 等不衝突）。 */
    public const SESSION_USER_ID  = '_customer_user_id';
    public const SESSION_CUSTOMER = '_customer_id';
    public const SESSION_CONTEXT  = '_customer_ctx';

    /** Zero Trust：`_sec.utype` 必須等於此值，客戶 session 方為有效。 */
    public const SESSION_TYPE = 'customer';

    /**
     * member 可被授予的能力 scopes 白名單（owner 恆全權，忽略此清單）。
     * 對應 portal 各功能區塊；CustomerAuthMiddleware / 各 Portal 控制器以此強制。
     */
    public const ALL_SCOPES = [
        'quotes',          // 檢視自己的報價 + 線上付款
        'payments',        // 檢視付款記錄 + 發動付款
        'assets',          // 檢視主機 / 網站（唯讀）
        'contacts',        // 管理聯絡人（CRUD）
        'payment_methods', // 卡片管理
        'team',            // 子帳號管理（注意：實務上僅 owner 可用；member 即使被勾選 team 仍不得管理團隊）
    ];

    /**
     * 是否已登入客戶 portal（session 層判斷；DB 有效性由 middleware 把關）。
     */
    public static function check(): bool
    {
        return self::id() > 0
            && self::customerId() > 0
            && (Session::get('_sec')['utype'] ?? null) === self::SESSION_TYPE;
    }

    /**
     * 當前登入的 customer_user id（未登入回 0）。
     */
    public static function id(): int
    {
        return (int) (Session::get(self::SESSION_USER_ID) ?? 0);
    }

    /**
     * 當前登入者所屬 customer_id（強制 scope 的根據；未登入回 0）。
     * 🔴 所有 portal 查詢一律以此值 scope，絕不信任前端傳入的 customer_id。
     */
    public static function customerId(): int
    {
        return (int) (Session::get(self::SESSION_CUSTOMER) ?? 0);
    }

    /**
     * 取得登入快照（id / customer_id / role / display_name / scopes / login_email）。
     *
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        $ctx = Session::get(self::SESSION_CONTEXT);
        return is_array($ctx) ? $ctx : [];
    }

    /**
     * 當前帳號角色（owner / member；未登入回空字串）。
     */
    public static function role(): string
    {
        return (string) (self::context()['role'] ?? '');
    }

    /**
     * 是否為主帳號（owner）。owner 擁有全部能力（含子帳號管理）。
     */
    public static function isOwner(): bool
    {
        return self::role() === 'owner';
    }

    /**
     * 顯示名稱。
     */
    public static function displayName(): string
    {
        return (string) (self::context()['display_name'] ?? '');
    }

    /**
     * 登入 email。
     */
    public static function email(): string
    {
        return (string) (self::context()['login_email'] ?? '');
    }

    /**
     * 當前帳號被授予的 scopes（owner 回全部白名單；member 回其 permissions_json）。
     *
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        if (self::isOwner()) {
            return self::ALL_SCOPES;
        }
        $scopes = self::context()['scopes'] ?? [];
        if (!is_array($scopes)) {
            return [];
        }
        // 僅保留白名單內的合法 scope（deny-by-default：未知值一律剔除）。
        return array_values(array_intersect(
            array_map('strval', $scopes),
            self::ALL_SCOPES
        ));
    }

    /**
     * 是否具備某能力 scope（後端強制用；非僅前端隱藏）。
     *
     * 規則（deny-by-default）：
     *   - owner：除 'team' 外皆 true；'team' 亦 true（owner 可管理團隊）。
     *   - member：必須其 scopes 含此 scope。'team' 對 member 一律 false
     *     （子帳號管理僅限 owner，即使誤被勾選 team 也不放行）。
     */
    public static function can(string $scope): bool
    {
        if ($scope === 'team') {
            return self::isOwner();
        }
        if (self::isOwner()) {
            return true;
        }
        return in_array($scope, self::scopes(), true);
    }

    /**
     * 將一筆 customer_user 列正規化為 session context 快照（登入時呼叫）。
     *
     * @param array<string, mixed> $user customer_users 列（含 permissions_json）
     * @return array<string, mixed>
     */
    public static function makeContext(array $user): array
    {
        $scopes = [];
        $raw = $user['permissions_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $scopes = array_values(array_intersect(
                    array_map('strval', $decoded),
                    self::ALL_SCOPES
                ));
            }
        } elseif (is_array($raw)) {
            $scopes = array_values(array_intersect(
                array_map('strval', $raw),
                self::ALL_SCOPES
            ));
        }

        return [
            'id'           => (int) $user['id'],
            'customer_id'  => (int) $user['customer_id'],
            'role'         => (string) ($user['role'] ?? 'member'),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'login_email'  => (string) ($user['login_email'] ?? ''),
            'scopes'       => $scopes,
        ];
    }
}
