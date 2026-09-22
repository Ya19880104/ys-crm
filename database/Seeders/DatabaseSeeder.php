<?php

declare(strict_types=1);

namespace YangSheep\CRM\Database\Seeders;

use PDO;

class DatabaseSeeder
{
    private PDO $pdo;
    private string $prefix;
    private string $seedersPath;

    public function __construct(PDO $pdo, string $prefix = 'ys_crm_')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->seedersPath = __DIR__;
    }

    /**
     * 執行所有 Seeder
     *
     * @return array<string, int> 每個 seeder 匯入的筆數
     */
    public function run(): array
    {
        $results = [];

        $this->pdo->beginTransaction();

        try {
            $results['roles']       = $this->seedFromSql('roles_seeder.sql');
            $results['permissions'] = $this->seedFromSql('permissions_seeder.sql');
            $results['role_permissions'] = $this->seedRolePermissions();
            $results['settings']    = $this->seedFromSql('settings_seeder.sql');

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $results;
    }

    private function seedFromSql(string $filename): int
    {
        $filePath = $this->seedersPath . '/' . $filename;
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Seeder 檔案不存在: {$filename}");
        }

        $sql = file_get_contents($filePath);
        $sql = str_replace('{prefix}', $this->prefix, $sql);

        // 分割多個 INSERT 語句
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn ($s) => !empty($s)
        );

        $count = 0;
        foreach ($statements as $statement) {
            $count += $this->pdo->exec($statement . ';');
        }

        return $count;
    }

    private function seedRolePermissions(): int
    {
        $seeder = new RolePermissionsSeeder($this->pdo, $this->prefix);
        return $seeder->run();
    }
}
