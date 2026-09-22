<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use PDO;

class MigrationRunner
{
    private PDO $pdo;
    private string $prefix;
    private string $migrationsPath;

    public function __construct(PDO $pdo, string $prefix = 'ys_crm_')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->migrationsPath = BASE_PATH . '/database/migrations';
    }

    /**
     * 執行所有未執行的 migration
     *
     * @return array<string, bool> migration 名稱 => 是否成功
     */
    public function runAll(): array
    {
        $results = [];
        $files = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrations();
        $batch = $this->getNextBatch();

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($name, $executed, true)) {
                $results[$name] = true; // 已執行
                continue;
            }

            try {
                $sql = file_get_contents($this->migrationsPath . '/' . $file);
                $sql = str_replace('{prefix}', $this->prefix, $sql);

                $this->pdo->exec($sql);

                // 記錄到 migrations 表（migrations 表本身也在第一個 migration 建立）
                $this->recordMigration($name, $batch);

                $results[$name] = true;
            } catch (\Throwable $e) {
                $results[$name] = false;
                throw new \RuntimeException(
                    "Migration 失敗 [{$name}]: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $results;
    }

    /**
     * 取得所有 migration 檔案（按檔名排序）
     */
    private function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        $files = array_map('basename', $files);
        sort($files);
        return $files;
    }

    /**
     * 取得已執行的 migration 名稱
     */
    private function getExecutedMigrations(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT migration FROM `{$this->prefix}migrations` ORDER BY id"
            );
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            if ($this->isMissingMigrationsTable($e)) {
                // migrations 表不存在（第一次執行）
                return [];
            }

            throw new \RuntimeException('無法讀取 migration 執行紀錄：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 取得下一個 batch 號碼
     */
    private function getNextBatch(): int
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT COALESCE(MAX(batch), 0) + 1 FROM `{$this->prefix}migrations`"
            );
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            if ($this->isMissingMigrationsTable($e)) {
                return 1;
            }

            throw new \RuntimeException('無法取得 migration batch：' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 只辨識 driver 明確回報的「migrations table 不存在」。權限、斷線與其他
     * 查詢錯誤都必須向外失敗，否則 runner 會把既有 schema 當成空白而重放 DDL。
     */
    private function isMissingMigrationsTable(\Throwable $error): bool
    {
        if (!$error instanceof \PDOException) {
            return false;
        }

        $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if ($sqlState === '42S02' || $driverCode === 1146) {
            return true;
        }

        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return false;
        }

        if ($driver !== 'sqlite') {
            return false;
        }

        $table = preg_quote($this->prefix . 'migrations', '/');
        return preg_match('/no such table:\s*[`"]?' . $table . '[`"]?/i', $error->getMessage()) === 1;
    }

    /**
     * 記錄已執行的 migration
     */
    private function recordMigration(string $name, int $batch): void
    {
        // 001 執行完成後 migrations 表已存在，因此連第一支 migration 都能正常
        // 記錄。這裡不可吞例外：若 DDL 已落地但 bookkeeping 失敗卻回報成功，
        // 下一次執行會重跑未記錄的 migration，非冪等 DDL 便可能永久卡住升級。
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->prefix}migrations` (migration, batch) VALUES (?, ?)"
        );
        $stmt->execute([$name, $batch]);
    }
}
