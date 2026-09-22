<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Session;

class AuthService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 嘗試以「帳號或 Email」加密碼登入。
     *
     * 【為何要收兩種識別字】登入頁的標籤寫「帳號 / Email」、placeholder 直接放了一個
     * email，但這裡原本只查 username —— 使用者照著畫面輸入 email 一定登不進去，
     * 而錯誤訊息是「帳號或密碼錯誤」，完全看不出是介面在騙人（2026-09-02 回報）。
     * 客戶 Portal 那一側本來就是以 email 登入（見 migration 042），補上之後兩側一致。
     *
     * 【為何分成兩次查詢而不是一句 OR】username 是 VARCHAR(50)、email 是 VARCHAR(255)，
     * 兩者的值域可能重疊（有人把帳號取成 a@b.com）。用 `username = :id OR email = :id`
     * 搭配 LIMIT 1，命中兩列時要選哪一列由資料庫決定，不同版本／不同執行計畫可能不同。
     * 拆成「先比帳號、再比 email」讓優先順序是明確寫死的，也用得到各自的唯一索引。
     *
     * 【大小寫】email 一律不分大小寫比對。production 的 utf8mb4_unicode_ci 本來就
     * 不分大小寫，但測試用的 SQLite 預設分 —— 明寫 LOWER() 讓兩邊行為一致，
     * 否則這條規則只會在測試裡是對的。
     *
     * @param string $identifier 帳號或 Email
     * @return array|null 成功回傳使用者陣列，失敗回傳 null
     */
    public function attempt(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $resolved = $this->resolveActiveIdentifier($identifier);
        if ($resolved === null) {
            return null;
        }
        $user = $resolved['user'];

        if (!password_verify($password, $user['password'])) {
            return null;
        }

        // 若密碼需要 rehash（演算法或成本變更時）。
        // 必須與密碼「建立」端（UserService / InstallController）一致採用 PASSWORD_ARGON2ID；
        // 否則 argon2id 雜湊每次登入都會被 PASSWORD_DEFAULT(bcrypt) 誤判為需 rehash，
        // 反而把既有 argon2id 密碼降級成 bcrypt。
        if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
            $this->db->execute(
                "UPDATE {prefix}users SET password = :password WHERE id = :id",
                [
                    'password' => password_hash($password, PASSWORD_ARGON2ID),
                    'id'       => $user['id'],
                ]
            );
        }

        // 更新最後登入時間
        $this->db->execute(
            "UPDATE {prefix}users SET last_login_at = NOW() WHERE id = :id",
            ['id' => $user['id']]
        );

        // 移除密碼再回傳
        unset($user['password']);

        return $user;
    }

    /**
     * Return the account-rail key for exactly the identity that attempt() would
     * authenticate. MySQL's utf8mb4_unicode_ci equates more strings than
     * mb_strtolower() (for example accent/case variants). Reusing the canonical
     * value stored on the matched DB row prevents those equivalent spellings
     * from creating independent throttle buckets. Unknown identities retain a
     * normalized input key and receive the same public response as before.
     */
    public function loginAttemptIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }

        $resolved = $this->resolveActiveIdentifier($identifier);
        $key = $resolved !== null ? $resolved['identifier'] : $identifier;
        return mb_strtolower(trim($key));
    }

    /**
     * @return array{user: array<string,mixed>, identifier: string}|null
     */
    private function resolveActiveIdentifier(string $identifier): ?array
    {
        // 1) 先比帳號；查詢與 attempt() 共用，避免 throttle/auth precedence 漂移。
        $user = $this->db->fetch(
            "SELECT * FROM {prefix}users WHERE username = :id AND status = 'active' LIMIT 1",
            ['id' => $identifier]
        );
        if ($user !== null) {
            return ['user' => $user, 'identifier' => (string) $user['username']];
        }

        // 2) 再比 Email（不分大小寫）。回傳 DB 儲存值而非 request spelling。
        $user = $this->db->fetch(
            "SELECT * FROM {prefix}users
             WHERE LOWER(email) = LOWER(:id) AND status = 'active' LIMIT 1",
            ['id' => $identifier]
        );
        if ($user === null) {
            return null;
        }

        return ['user' => $user, 'identifier' => (string) $user['email']];
    }

    /**
     * 取得當前登入的使用者
     */
    public function currentUser(): ?array
    {
        return Session::get('user');
    }

    /**
     * 是否已登入
     */
    public function check(): bool
    {
        return Session::has('user');
    }

    /**
     * 登入成功後存入 Session
     */
    public function setUser(array $user): void
    {
        Session::set('user', $user);
    }
}
