<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal\Auth;

use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Auth\CredentialRotationService;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Portal\CustomerUserRepository;

/**
 * 客戶 Portal 認證/帳號領域服務。
 *
 * 職責：
 *   - 登入驗證（email + password_hash + is_active 檢查；密碼以 PASSWORD_DEFAULT 雜湊）。
 *   - 為客戶建立 owner（主帳號，管理員操作）。
 *   - 由 owner 建立 member（子帳號）。
 *   - 密碼變更、顯示名稱變更、停用/啟用、改 member 權限。
 *
 * Zero Trust：
 *   - 密碼一律 password_hash(PASSWORD_DEFAULT) + password_verify；需要時 rehash。
 *   - 所有「依 id 操作子帳號 / 卡片 / 停用」皆綁 customer_id（由 Repository 強制），
 *     呼叫端傳入的 customer_id 一律取自 session（CustomerGuard），絕不取自前端。
 *   - 每客戶至多一個 owner（建立 owner 前查重）。
 */
class CustomerAuthService
{
    private CustomerUserRepository $repo;
    private Database $db;
    private CredentialRotationService $credentialRotation;
    private AuditLogService $auditLog;

    public function __construct()
    {
        $this->repo = new CustomerUserRepository();
        $this->db   = Database::getInstance();
        $this->credentialRotation = new CredentialRotationService();
        $this->auditLog = new AuditLogService();
    }

    // ───────────────────────── 登入 ─────────────────────────

    /**
     * 嘗試登入。成功回傳帳號列（不含 password_hash）；失敗回 null。
     *
     * 失敗包含：查無帳號 / 密碼錯 / 帳號停用。為避免帳號列舉，呼叫端對所有失敗一律
     * 回相同錯誤訊息（「帳號或密碼錯誤」），停用另以「帳號已停用」區分由呼叫端決定。
     *
     * @return array{user: array<string,mixed>, reason: string}
     *   reason: 'ok' | 'invalid' | 'inactive'
     */
    public function attempt(string $email, string $password): array
    {
        $user = $this->repo->findByEmail($email);

        if ($user === null) {
            return ['user' => [], 'reason' => 'invalid'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return ['user' => [], 'reason' => 'invalid'];
        }

        // 密碼正確但帳號停用 → 拒絕（即使密碼對也不得登入）。
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            return ['user' => [], 'reason' => 'inactive'];
        }

        // 需要時 rehash（成本/演算法變更）。建立端亦用 PASSWORD_DEFAULT，故不會誤降級。
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->repo->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        unset($user['password_hash']);
        return ['user' => $user, 'reason' => 'ok'];
    }

    /**
     * Resolve the account-rail key through the same DB lookup as attempt().
     * This keeps MySQL collation-equivalent email spellings in one throttle
     * bucket instead of approximating the database with mb_strtolower().
     */
    public function loginAttemptIdentifier(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        $user = $this->repo->findByEmail($email);
        $key = $user !== null ? (string) $user['login_email'] : $email;
        return mb_strtolower(trim($key));
    }

    /**
     * 記錄登入成功的時間 / IP。
     */
    public function recordLogin(int $customerUserId, string $ip): void
    {
        $this->repo->recordLogin($customerUserId, $ip);
    }

    // ───────────────────────── 建立帳號 ─────────────────────────

    /**
     * 管理員為某客戶建立「主帳號（owner）」。
     *
     * @param int    $customerId  客戶 id
     * @param string $email       登入 email（全表唯一）
     * @param string $password    初始密碼（明文，將雜湊）
     * @param string $displayName 顯示名稱
     * @param int    $adminUserId 建立者（管理員 id），記入 created_by
     * @param null|callable(int):void $beforeCommit 收到新 owner id；insert 成功後、
     *                                               transaction commit 前執行
     * @return int 新帳號 id
     * @throws \RuntimeException email 已存在 / 已有 owner
     */
    public function createOwner(
        int $customerId,
        string $email,
        string $password,
        string $displayName,
        int $adminUserId,
        ?callable $beforeCommit = null
    ): int {
        $email = mb_strtolower(trim($email));
        $this->assertEmailFormat($email);
        $this->assertPasswordStrength($password);

        return $this->db->transaction(function () use (
            $customerId,
            $email,
            $password,
            $displayName,
            $adminUserId,
            $beforeCommit
        ): int {
            $this->repo->lockCustomerForOwnerCreation($customerId);
            if ($this->repo->emailExists($email)) {
                throw new \RuntimeException('此 Email 已被使用，請改用其他 Email。');
            }
            if ($this->repo->hasOwner($customerId)) {
                throw new \RuntimeException('此客戶已有主帳號，無法重複建立。');
            }

            $ownerId = $this->repo->insert([
                'customer_id'      => $customerId,
                'parent_user_id'   => null,
                'role'             => 'owner',
                'login_email'      => $email,
                'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
                'display_name'     => $displayName !== '' ? $displayName : $email,
                'permissions_json' => null, // owner 恆全權，不需 scopes
                'is_active'        => 1,
                'created_by'       => $adminUserId > 0 ? $adminUserId : null,
            ]);
            if ($beforeCommit !== null) {
                $beforeCommit($ownerId);
            }

            return $ownerId;
        });
    }

    /**
     * 由 owner 建立「子帳號（member）」。
     *
     * @param int      $customerId  owner 所屬客戶 id（取自 session，非前端）
     * @param int      $ownerUserId 建立者 owner 的 customer_user id（parent_user_id）
     * @param string   $email       登入 email
     * @param string   $password    初始密碼
     * @param string   $displayName 顯示名稱
     * @param string[] $scopes      授予的能力 scopes（會過濾白名單）
     * @return int 新子帳號 id
     * @throws \RuntimeException email 已存在 / 密碼太弱
     */
    public function createMember(int $customerId, int $ownerUserId, string $email, string $password, string $displayName, array $scopes): int
    {
        $email = mb_strtolower(trim($email));
        $this->assertEmailFormat($email);
        $this->assertPasswordStrength($password);

        if ($this->repo->emailExists($email)) {
            throw new \RuntimeException('此 Email 已被使用，請改用其他 Email。');
        }

        return $this->repo->insert([
            'customer_id'      => $customerId,
            'parent_user_id'   => $ownerUserId,
            'role'             => 'member',
            'login_email'      => $email,
            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
            'display_name'     => $displayName !== '' ? $displayName : $email,
            'permissions_json' => $this->encodeScopes($scopes),
            'is_active'        => 1,
            'created_by'       => null, // 由客戶端建立，非管理員
        ]);
    }

    // ───────────────────────── 帳號維護 ─────────────────────────

    /**
     * 變更自己的密碼（需先驗舊密碼）。
     *
     * @param null|callable(int):void $beforeCommit
     * @throws \RuntimeException 舊密碼錯 / 新密碼太弱
     */
    public function changeOwnPassword(
        int $customerUserId,
        string $oldPassword,
        string $newPassword,
        ?callable $beforeCommit = null
    ): void {
        $row = $this->repo->findWithHashById($customerUserId);
        if ($row === null) {
            throw new \RuntimeException('帳號不存在。');
        }
        if (!password_verify($oldPassword, (string) $row['password_hash'])) {
            throw new \RuntimeException('目前密碼不正確。');
        }
        $this->assertPasswordStrength($newPassword);
        $this->credentialRotation->rotateCustomerIfCurrentHash(
            $customerUserId,
            (string) $row['password_hash'],
            $newPassword,
            $beforeCommit
        );
    }

    /**
     * 管理員重設 Portal 帳號密碼。
     *
     * 密碼寫入、session 撤銷與 audit 必須同成同敗：若 audit 在 controller
     * 才寫且失敗，系統產生的新密碼尚未顯示，帳號卻已換掉 credential。
     */
    public function resetPassword(
        int $customerUserId,
        string $newPassword,
        int $adminUserId,
        int $customerId,
        ?string $ip = null
    ): int {
        $this->assertPasswordStrength($newPassword);

        return $this->credentialRotation->rotateCustomer(
            $customerUserId,
            $newPassword,
            function () use ($adminUserId, $customerId, $customerUserId, $ip): void {
                $this->auditLog->log(
                    $adminUserId,
                    'customer_portal_account_password_reset',
                    'customer_user',
                    $customerUserId,
                    ['customer_id' => $customerId],
                    $ip
                );
            }
        );
    }

    /**
     * 變更自己的顯示名稱。
     */
    public function changeOwnDisplayName(int $customerUserId, string $displayName): void
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            throw new \RuntimeException('顯示名稱長度需為 1–100 字。');
        }
        $this->repo->updateDisplayName($customerUserId, $displayName);
    }

    /**
     * owner 停用/啟用子帳號（限本客戶；不可停用 owner 自己/其他 owner）。
     *
     * @throws \RuntimeException 目標不存在 / 試圖停用 owner / 試圖操作自己
     */
    /** @param null|callable(int):void $beforeCommit 收到撤銷 session 筆數 */
    public function setMemberActive(
        int $targetId,
        int $customerId,
        int $actingUserId,
        bool $active,
        ?callable $beforeCommit = null
    ): int
    {
        return $this->db->transaction(function () use (
            $targetId,
            $customerId,
            $actingUserId,
            $active,
            $beforeCommit
        ): int {
            $target = $this->repo->findByIdForCustomer($targetId, $customerId, true);
            if ($target === null) {
                throw new \RuntimeException('找不到該子帳號。');
            }
            if ((string) $target['role'] === 'owner') {
                throw new \RuntimeException('主帳號無法被停用。');
            }
            if ($targetId === $actingUserId) {
                throw new \RuntimeException('無法停用自己的帳號。');
            }

            return $this->changeScopedActiveState(
                $targetId,
                $customerId,
                $active,
                $beforeCommit
            );
        });
    }

    /**
     * 後台管理員可啟停 customer scope 內的 owner/member；狀態、session 與 audit
     * 仍必須共用同一 transaction。
     *
     * @param null|callable(int):void $beforeCommit 收到撤銷 session 筆數
     */
    public function setAccountActiveByAdmin(
        int $targetId,
        int $customerId,
        bool $active,
        ?callable $beforeCommit = null
    ): int {
        return $this->db->transaction(function () use (
            $targetId,
            $customerId,
            $active,
            $beforeCommit
        ): int {
            if ($this->repo->findByIdForCustomer($targetId, $customerId, true) === null) {
                throw new \RuntimeException('找不到該帳號。');
            }

            return $this->changeScopedActiveState(
                $targetId,
                $customerId,
                $active,
                $beforeCommit
            );
        });
    }

    /**
     * owner 變更子帳號權限 scopes（限本客戶、限 member）。
     *
     * @param string[] $scopes
     * @throws \RuntimeException 目標不存在 / 非 member
     */
    public function updateMemberScopes(int $targetId, int $customerId, array $scopes): void
    {
        $target = $this->repo->findByIdForCustomer($targetId, $customerId);
        if ($target === null) {
            throw new \RuntimeException('找不到該子帳號。');
        }
        if ((string) $target['role'] !== 'member') {
            throw new \RuntimeException('僅子帳號可設定權限。');
        }
        $this->repo->updatePermissionsForCustomer($targetId, $customerId, $this->encodeScopes($scopes));
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 將前端傳入的 scopes 過濾為白名單後 JSON 編碼（deny-by-default）。
     *
     * @param string[] $scopes
     */
    private function encodeScopes(array $scopes): ?string
    {
        $clean = array_values(array_intersect(
            array_map('strval', $scopes),
            CustomerGuard::ALL_SCOPES
        ));
        // member 不可自行取得 team（子帳號管理僅 owner）；即使誤傳也剔除。
        $clean = array_values(array_diff($clean, ['team']));
        return $clean === [] ? json_encode([]) : json_encode($clean);
    }

    /** @param null|callable(int):void $beforeCommit */
    private function changeScopedActiveState(
        int $targetId,
        int $customerId,
        bool $active,
        ?callable $beforeCommit
    ): int {
        $affected = $this->repo->setActiveForCustomer($targetId, $customerId, $active);
        $stored = $this->repo->findByIdForCustomer($targetId, $customerId);
        if ($stored === null || (int) $stored['is_active'] !== ($active ? 1 : 0)) {
            throw new \RuntimeException('帳號狀態寫入後讀回驗證失敗。');
        }

        // 啟用也要撤銷：舊版本可能曾在停用時撤銷失敗；若只在停用撤銷，
        // 重新啟用會讓尚未逾時的歷史/被竊 session 直接復活。
        $revoked = Session::revokeAllForUserOrFail('customer', $targetId);
        if ($beforeCommit !== null) {
            $beforeCommit($revoked);
        }

        return $affected;
    }

    /**
     * Email 格式驗證。
     */
    private function assertEmailFormat(string $email): void
    {
        if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email 格式不正確。');
        }
    }

    /**
     * 密碼強度（最低 8 字；對外帳號基本門檻）。
     */
    private function assertPasswordStrength(string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new \RuntimeException('密碼長度至少需 8 個字元。');
        }
        if (mb_strlen($password) > 200) {
            throw new \RuntimeException('密碼過長。');
        }
    }

    /**
     * 產生一次性初始密碼（管理員建立 owner 後顯示；因無 SMTP 無法寄送）。
     * 12 字、含大小寫與數字，避免易混淆字元。
     */
    public static function generateInitialPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $len = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $len - 1)];
        }
        return $out;
    }
}
