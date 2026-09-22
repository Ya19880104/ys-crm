<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use PDO;

/** 原 installer session 遺失後的本機、fail-closed recovery policy。 */
final class InstallationRecovery
{
    private PDO $pdo;
    private string $prefix;
    private InstallationMarkers $markers;
    private string $migrationsPath;
    private ?string $recoveryCode = null;

    /** Secret returned only to the local operator, never logged or put in a URL. */
    public function recoveryCode(): ?string
    {
        return $this->recoveryCode;
    }

    public function __construct(
        PDO $pdo,
        string $prefix,
        InstallationMarkers $markers,
        ?string $migrationsPath = null
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('DB_PREFIX 格式無效');
        }

        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->markers = $markers;
        $this->migrationsPath = $migrationsPath ?? BASE_PATH . '/database/migrations';
    }

    /** @return 'reset'|'promoted' */
    public function recover(bool $explicitlyPromoteCompletedInstall): string
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('安裝 recovery 僅限本機 CLI');
        }
        if ($this->markers->completeExists()) {
            throw new \RuntimeException('安裝已完成，無需 recovery');
        }
        if (!$this->markers->pendingExists()) {
            throw new \RuntimeException('找不到 install.pending，無可恢復的安裝流程');
        }

        $usersTable = $this->prefix . 'users';
        $userCount = $this->tableExists($usersTable)
            ? $this->countRows($usersTable)
            : 0;

        if ($userCount === 0) {
            $this->recoveryCode = $this->markers->prepareResumeFromCli();
            return 'reset';
        }

        if (!$explicitlyPromoteCompletedInstall) {
            throw new \RuntimeException(
                '資料庫已有使用者；必須明示 promote-complete，且通過完整性檢查後才能封閉 installer'
            );
        }

        $this->assertReadyToPromote();
        $this->markers->completePendingFromCli();
        return 'promoted';
    }

    private function assertReadyToPromote(): void
    {
        foreach (['migrations', 'settings', 'roles', 'users'] as $suffix) {
            if (!$this->tableExists($this->prefix . $suffix)) {
                throw new \RuntimeException("安裝 schema 不完整：缺少 {$suffix} table");
            }
        }

        $expectedMigrations = glob(rtrim($this->migrationsPath, '/\\') . '/*.sql');
        if (!is_array($expectedMigrations) || $expectedMigrations === []) {
            throw new \RuntimeException('無法取得預期 migration 清單');
        }
        $expectedMigrations = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $expectedMigrations
        );

        $statement = $this->pdo->query(
            'SELECT migration FROM `' . $this->prefix . 'migrations` ORDER BY migration'
        );
        if ($statement === false) {
            throw new \RuntimeException('無法讀取 migration 執行紀錄');
        }
        $executed = $statement->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($expectedMigrations, array_map('strval', $executed)));
        if ($missing !== []) {
            throw new \RuntimeException('安裝 schema 尚有未執行 migration：' . implode(', ', $missing));
        }

        $sql = 'SELECT COUNT(*) FROM `' . $this->prefix . 'users` u '
            . 'INNER JOIN `' . $this->prefix . 'roles` r ON r.id = u.role_id '
            . "WHERE u.status = 'active' AND r.slug = 'super_admin'";
        $statement = $this->pdo->query($sql);
        if ($statement === false || (int) $statement->fetchColumn() < 1) {
            throw new \RuntimeException('找不到 active super-admin，不得封閉 installer');
        }
    }

    private function tableExists(string $table): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function countRows(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`');
        if ($statement === false) {
            throw new \RuntimeException("無法讀取 {$table}");
        }
        return (int) $statement->fetchColumn();
    }
}
