<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use PDO;

/**
 * Create the first administrator, or verify an exact retry after a process break.
 *
 * The installer can lose its file-session write after MySQL has committed the user.
 * A blind INSERT IGNORE would then claim that a newly submitted password was saved
 * even though the old hash remained. This service only accepts a pre-existing row
 * when every identity attribute and the submitted password verify exactly.
 */
final class AdminBootstrapper
{
    /** @param array{username:string,email:string,display_name:string,password:string} $input */
    public function createOrVerify(PDO $pdo, string $prefix, array $input): int
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('資料表前綴格式無效。');
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $role = $pdo->prepare("SELECT id FROM `{$prefix}roles` WHERE slug = 'super_admin'");
            $role->execute();
            $roleId = (int) $role->fetchColumn();
            if ($roleId <= 0) {
                throw new \RuntimeException('找不到 super_admin 角色，請確認初始資料已匯入');
            }

            $existing = $this->findIdentityRows($pdo, $prefix, $input['username'], $input['email']);
            if ($existing !== []) {
                if (count($existing) !== 1 || !$this->isExactRetry($existing[0], $input, $roleId)) {
                    throw new \RuntimeException('既有管理員與本次輸入不一致，無法安全重試。');
                }

                $id = (int) $existing[0]['id'];
                if ($startedTransaction) {
                    $pdo->commit();
                }
                return $id;
            }

            $passwordHash = password_hash($input['password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 3,
            ]);

            $insert = $pdo->prepare(
                "INSERT INTO `{$prefix}users` (username, email, password, display_name, role_id, status)
                 VALUES (?, ?, ?, ?, ?, 'active')"
            );
            $insert->execute([
                $input['username'],
                $input['email'],
                $passwordHash,
                $input['display_name'],
                $roleId,
            ]);
            if ($insert->rowCount() !== 1) {
                throw new \RuntimeException('管理員建立結果無法確認。');
            }

            $created = $this->findIdentityRows($pdo, $prefix, $input['username'], $input['email']);
            if (count($created) !== 1 || !$this->isExactRetry($created[0], $input, $roleId)) {
                throw new \RuntimeException('管理員建立後驗證失敗。');
            }

            $id = (int) $created[0]['id'];
            if ($id <= 0) {
                throw new \RuntimeException('管理員建立後缺少有效識別碼。');
            }

            if ($startedTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $error) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return list<array{id:mixed,username:mixed,email:mixed,password:mixed,display_name:mixed,role_id:mixed,status:mixed}>
     */
    private function findIdentityRows(PDO $pdo, string $prefix, string $username, string $email): array
    {
        $lookup = $pdo->prepare(
            "SELECT id, username, email, password, display_name, role_id, status
             FROM `{$prefix}users`
             WHERE username = :username OR email = :email
             ORDER BY id
             LIMIT 2"
        );
        $lookup->execute(['username' => $username, 'email' => $email]);
        return $lookup->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $row @param array<string,string> $input */
    private function isExactRetry(array $row, array $input, int $roleId): bool
    {
        return hash_equals((string) $row['username'], $input['username'])
            && hash_equals((string) $row['email'], $input['email'])
            && hash_equals((string) $row['display_name'], $input['display_name'])
            && (int) $row['role_id'] === $roleId
            && (string) $row['status'] === 'active'
            && password_verify($input['password'], (string) $row['password']);
    }
}
