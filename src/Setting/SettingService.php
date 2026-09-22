<?php

declare(strict_types=1);

namespace YangSheep\CRM\Setting;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Encryption;

class SettingService
{
    private Database $db;
    private Encryption $encryption;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    /**
     * 取得單一設定值
     */
    public function get(string $group, string $key): ?string
    {
        $row = $this->db->fetch(
            "SELECT setting_value, is_encrypted
             FROM {prefix}settings
             WHERE setting_group = ? AND setting_key = ?",
            [$group, $key]
        );

        if ($row === null) {
            return null;
        }

        // 若為加密值，自動解密
        if ($row['is_encrypted']) {
            try {
                return $this->encryption->decrypt($row['setting_value']);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $row['setting_value'];
    }

    /**
     * 設定單一值
     */
    public function set(string $group, string $key, string $value, bool $encrypted = false): void
    {
        $storedValue = $encrypted ? $this->encryption->encrypt($value) : $value;

        // UPSERT：存在則更新，不存在則新增
        $existing = $this->db->fetch(
            "SELECT id FROM {prefix}settings WHERE setting_group = ? AND setting_key = ?",
            [$group, $key]
        );

        if ($existing !== null) {
            $this->db->execute(
                "UPDATE {prefix}settings
                 SET setting_value = ?, is_encrypted = ?, updated_at = NOW()
                 WHERE setting_group = ? AND setting_key = ?",
                [$storedValue, $encrypted ? 1 : 0, $group, $key]
            );
        } else {
            $this->db->execute(
                "INSERT INTO {prefix}settings
                    (setting_group, setting_key, setting_value, is_encrypted, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$group, $key, $storedValue, $encrypted ? 1 : 0]
            );
        }
    }

    /**
     * 取得指定群組的所有設定
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value, is_encrypted
             FROM {prefix}settings
             WHERE setting_group = ?
             ORDER BY setting_key ASC",
            [$group]
        );

        $result = [];
        foreach ($rows as $row) {
            $value = $row['setting_value'];
            if ($row['is_encrypted']) {
                try {
                    $value = $this->encryption->decrypt($value);
                } catch (\RuntimeException) {
                    $value = '';
                }
            }
            $result[$row['setting_key']] = $value;
        }

        return $result;
    }

    /**
     * 取得所有設定，按 group 分組
     */
    public function getAllGrouped(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_group, setting_key, setting_value, is_encrypted
             FROM {prefix}settings
             ORDER BY setting_group ASC, setting_key ASC"
        );

        $result = [];
        foreach ($rows as $row) {
            $value = $row['setting_value'];
            if ($row['is_encrypted']) {
                try {
                    $value = $this->encryption->decrypt($value);
                } catch (\RuntimeException) {
                    $value = '';
                }
            }
            $result[$row['setting_group']][$row['setting_key']] = $value;
        }

        return $result;
    }
}
