<?php

declare(strict_types=1);

namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Session;

/**
 * 密碼輪替的單一交易邊界：寫入、讀回驗證、撤銷舊 session 必須一起成功。
 */
final class CredentialRotationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @param null|callable(int):void $beforeCommit */
    public function rotateAdmin(
        int $userId,
        string $plainPassword,
        ?callable $beforeCommit = null
    ): int
    {
        return $this->rotate($userId, $plainPassword, 'admin', $beforeCommit, null);
    }

    /**
     * @param null|callable(int):void $beforeCommit 需與憑證輪替同成同敗的副作用；
     *        會在密碼寫入、讀回驗證與 session 撤銷成功後、commit 前執行。
     */
    public function rotateCustomer(
        int $userId,
        string $plainPassword,
        ?callable $beforeCommit = null
    ): int
    {
        return $this->rotate($userId, $plainPassword, 'customer', $beforeCommit, null);
    }

    /**
     * Portal self-service rotation guarded by the exact hash used to verify
     * the current password. If concurrent requests both verified the same old
     * hash, only the first conditional UPDATE may replace it.
     *
     * @param null|callable(int):void $beforeCommit
     */
    public function rotateCustomerIfCurrentHash(
        int $userId,
        string $expectedHash,
        string $plainPassword,
        ?callable $beforeCommit = null
    ): int {
        if ($expectedHash === '') {
            throw new \RuntimeException('憑證已變更，請重新驗證目前密碼。');
        }

        return $this->rotate($userId, $plainPassword, 'customer', $beforeCommit, $expectedHash);
    }

    /** @param null|callable(int):void $beforeCommit */
    private function rotate(
        int $userId,
        string $plainPassword,
        string $userType,
        ?callable $beforeCommit = null,
        ?string $expectedHash = null
    ): int
    {
        if ($userId <= 0 || $plainPassword === '') {
            throw new \RuntimeException('密碼輪替需要有效的帳號與非空密碼。');
        }

        // 兩個登入平面有刻意不同的既有契約：後台一律 Argon2id，Portal
        // 維持 PASSWORD_DEFAULT。共用交易邊界不能把演算法也錯誤地共用。
        $algorithm = $userType === 'admin' ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($plainPassword, $algorithm);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('無法產生密碼雜湊。');
        }

        return $this->db->transaction(function () use (
            $userId,
            $plainPassword,
            $hash,
            $userType,
            $beforeCommit,
            $expectedHash
        ): int {
            if ($userType === 'customer') {
                $sql = 'UPDATE {prefix}customer_users '
                    . 'SET password_hash = :hash, updated_at = NOW() WHERE id = :id';
                $params = ['hash' => $hash, 'id' => $userId];
                if ($expectedHash !== null) {
                    $sql .= ' AND password_hash = :expected_hash';
                    $params['expected_hash'] = $expectedHash;
                }
                $written = $this->db->execute($sql, $params);
                if ($expectedHash !== null && $written !== 1) {
                    throw new \RuntimeException('憑證已變更，請重新驗證目前密碼。');
                }
                $stored = $this->db->fetchColumn(
                    'SELECT password_hash FROM {prefix}customer_users WHERE id = :id',
                    ['id' => $userId]
                );
            } else {
                $written = $this->db->execute(
                    'UPDATE {prefix}users SET password = :hash WHERE id = :id',
                    ['hash' => $hash, 'id' => $userId]
                );
                $stored = $this->db->fetchColumn(
                    'SELECT password FROM {prefix}users WHERE id = :id',
                    ['id' => $userId]
                );
            }

            if ($written !== 1 || !is_string($stored) || !password_verify($plainPassword, $stored)) {
                throw new \RuntimeException('密碼寫入後讀回驗證失敗。');
            }

            $revoked = Session::revokeAllForUserOrFail($userType, $userId);
            if ($beforeCommit !== null) {
                $beforeCommit($revoked);
            }

            return $revoked;
        });
    }
}
