<?php

declare(strict_types=1);

/**
 * 內建 PSR-4 Autoloader — 取代 Composer autoload，打包即可用
 */
class Autoloader
{
    private static array $prefixes = [];

    /**
     * 註冊 autoloader
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * 新增 namespace 前綴對應的目錄
     *
     * @param string $prefix Namespace 前綴（如 'YangSheep\CRM\\'）
     * @param string $baseDir 對應的基礎目錄
     */
    public static function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . '/';
        self::$prefixes[$prefix] = $baseDir;
    }

    /**
     * 自動載入類別
     */
    public static function loadClass(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
}
