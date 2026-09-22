<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use PDO;

/**
 * 將 installer Step 6 的 DB settings 與 .env 更新收斂成可重試流程。
 *
 * 兩種資源無法共用同一個 ACID transaction，因此固定採 DB-first：
 *
 * 1. DB commit 失敗時尚未碰觸 .env；
 * 2. DB commit 後才 atomic-replace .env；若 replace 明確失敗，補償還原 DB；
 * 3. 若 process 在 DB commit 後中斷，owner-bound pending installer 仍停在 Step 6，
 *    相同輸入重送會冪等補齊 .env。
 *
 * APP_URL 已在 Step 2 由可信 INSTALL_HOST 固定，Step 6 不得改寫，因此上述短暫
 * DB-first 狀態不會讓下一個 request 被 canonical Host gate 擋在 recovery 之前。
 */
final class InstallationConfigCommitter
{
    /**
     * @param list<array{0:string,1:string,2:mixed}> $updates
     * @param array<string,mixed> $envUpdates
     * @param null|callable(string,string):void $envWriter test-only file-write seam
     * @param null|callable():void $databaseCommitter test-only commit-failure seam
     */
    public function apply(
        PDO $pdo,
        string $prefix,
        array $updates,
        string $envPath,
        array $envUpdates,
        ?callable $envWriter = null,
        ?callable $databaseCommitter = null
    ): void {
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('無效的資料表前綴');
        }
        if ($updates === []) {
            throw new \InvalidArgumentException('缺少 installer settings 更新');
        }

        $table = $prefix . 'settings';
        $select = $pdo->prepare(
            "SELECT setting_value FROM `{$table}` WHERE setting_group = ? AND setting_key = ?"
        );
        $snapshot = [];
        foreach ($updates as $update) {
            if (count($update) !== 3) {
                throw new \InvalidArgumentException('installer setting 格式無效');
            }
            [$group, $key] = $update;
            $identity = $group . "\0" . $key;
            if (array_key_exists($identity, $snapshot)) {
                throw new \InvalidArgumentException('installer setting 不得重複');
            }

            $select->execute([$group, $key]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !array_key_exists('setting_value', $row)) {
                throw new \RuntimeException("缺少必要設定：{$group}.{$key}");
            }
            $snapshot[$identity] = [$group, $key, $row['setting_value']];
        }

        $this->commitSettings($pdo, $table, $updates, $databaseCommitter);

        try {
            EnvWriter::updateFile($envPath, $envUpdates, $envWriter);
        } catch (\Throwable $envError) {
            try {
                $this->commitSettings($pdo, $table, array_values($snapshot));
            } catch (\Throwable $restoreError) {
                throw new \RuntimeException(
                    '.env 更新失敗，且 DB settings 補償還原失敗：' . $restoreError->getMessage(),
                    0,
                    $envError
                );
            }
            throw $envError;
        }
    }

    /**
     * @param list<array{0:string,1:string,2:mixed}> $updates
     * @param null|callable():void $databaseCommitter
     */
    private function commitSettings(
        PDO $pdo,
        string $table,
        array $updates,
        ?callable $databaseCommitter = null
    ): void {
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                "UPDATE `{$table}` SET setting_value = ? WHERE setting_group = ? AND setting_key = ?"
            );
            foreach ($updates as [$group, $key, $value]) {
                $statement->execute([$value, $group, $key]);
            }

            if ($databaseCommitter !== null) {
                $databaseCommitter();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
