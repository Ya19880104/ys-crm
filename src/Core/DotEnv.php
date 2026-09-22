<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * 內建 .env 解析器 — 取代 vlucas/phpdotenv，無需 Composer
 */
class DotEnv
{
    /**
     * 載入 .env 檔案到 $_ENV 和 putenv()
     */
    public static function load(string $path): void
    {
        $filePath = rtrim($path, '/\\') . '/.env';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳過註解和空行
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // 解析 KEY=VALUE
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // 移除值的引號（記錄是否為雙引號，僅雙引號才做跳脫還原）
            $wasDoubleQuoted = false;
            if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
                $value = substr($value, 1, -1);
                $wasDoubleQuoted = true;
            } elseif (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
                // 單引號為字面值，不做跳脫還原
                $value = substr($value, 1, -1);
            }

            // 處理跳脫字元（雙引號內）——與 EnvWriter::formatValue() 的跳脫規則對稱。
            // 用單次掃描的 callback 正確解碼 \\ → \ 、\" → " 、\n → 換行 、\t → tab，
            // 避免 str_replace 連續取代造成 \\n 等序列的歧義。
            if ($wasDoubleQuoted) {
                $value = preg_replace_callback(
                    '/\\\\(.)/s',
                    static function (array $m): string {
                        return match ($m[1]) {
                            'n'     => "\n",
                            't'     => "\t",
                            '"'     => '"',
                            '\\'    => '\\',
                            default => '\\' . $m[1], // 未知跳脫保留原樣（含反斜線）
                        };
                    },
                    $value
                );
            }

            // 設定到所有全域位置
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
