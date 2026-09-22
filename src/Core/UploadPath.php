<?php
declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * 上傳目錄的**唯一**來源。
 *
 * 【為什麼目錄名要隨機】uploads/ 必須留在 web root 內（圖片由 web server 直接服務），
 * 所以它的路徑是可被外部枚舉的。檔名本身已經隨機（date + bin2hex(random_bytes(8))），
 * 但固定的目錄名讓攻擊者能盲測「有沒有東西被種進來」、也讓誤設的目錄列表一次曝光全部。
 * 目錄名加上隨機尾碼可把這個面關掉，且不需要任何 server 設定權限。
 *
 * 【為什麼預設仍是 `uploads`】DB 裡存的是**完整 web 路徑**（`/uploads/quotes/x.png`）。
 * 對既有安裝改名會讓每一筆既存路徑失效。故：
 *   - 沒有設定 → `uploads`，既有安裝行為完全不變（零風險）。
 *   - 全新安裝 → provision() 產生 `uploads_<8 hex>` 並寫入 .env。
 * 兩者在同一份程式碼下共存，因為新舊列各自帶著寫入當時的前綴。
 *
 * 【可維修性】名稱持久化在 .env。若 .env 遺失（重裝、搬機），provision() 會**沿用磁碟上
 * 已存在的目錄**而不是另外產生一個新的 —— 否則既有檔案會全部變成孤兒。
 */
final class UploadPath
{
    public const DEFAULT_DIR = 'uploads';
    public const ENV_KEY     = 'UPLOADS_DIR';

    /** 隨機目錄的前綴，供 provision() 掃描磁碟時辨識自己產生的目錄。 */
    private const RANDOM_PREFIX = 'uploads_';

    /**
     * 目前使用的目錄名（單層，不含斜線）。
     *
     * 🔴 設定值一律經白名單驗證：這個值會被接到檔案系統路徑上，
     * 不驗證等於把 .env 的一個欄位變成路徑穿越載體。不合法就退回預設，不拋例外
     * —— 上傳壞掉比用預設目錄嚴重。
     */
    public static function dir(): string
    {
        return self::sanitize((string) ($_ENV[self::ENV_KEY] ?? '')) ?? self::DEFAULT_DIR;
    }

    /** web 路徑前綴，例如 `/uploads` 或 `/uploads_a1b2c3d4`。 */
    public static function webPrefix(): string
    {
        return '/' . self::dir();
    }

    /** 檔案系統絕對路徑。 */
    public static function absolute(?string $webRoot = null): string
    {
        return self::webRoot($webRoot) . '/' . self::dir();
    }

    /** 子目錄（avatars / quotes / settings / comments / jobs）的絕對路徑。 */
    public static function subdir(string $name, ?string $webRoot = null): string
    {
        return self::absolute($webRoot) . '/' . $name;
    }

    /** 子目錄的 web 路徑前綴，結尾含斜線。 */
    public static function webSubdir(string $name): string
    {
        return self::webPrefix() . '/' . $name . '/';
    }

    /**
     * 安裝時決定目錄名。回傳應寫入 .env 的值。
     *
     * 順序刻意如此：
     *   1. .env 已有合法值 → 沿用（重跑安裝不得換名）。
     *   2. 磁碟上已有 `uploads/` 且內有檔案 → 沿用（既有安裝升級，不可讓舊路徑變孤兒）。
     *   3. 磁碟上已有 `uploads_*` → 沿用（.env 遺失後的重裝復原）。
     *   4. 都沒有 → 產生新的隨機名。
     */
    public static function provision(?string $webRoot = null): string
    {
        $root = self::webRoot($webRoot);

        $configured = self::sanitize((string) ($_ENV[self::ENV_KEY] ?? ''));
        if ($configured !== null) {
            return $configured;
        }

        $legacy = $root . '/' . self::DEFAULT_DIR;
        if (is_dir($legacy) && self::hasPayload($legacy)) {
            return self::DEFAULT_DIR;
        }

        $adopted = self::findExistingRandom($root);
        if ($adopted !== null) {
            return $adopted;
        }

        return self::RANDOM_PREFIX . bin2hex(random_bytes(4));
    }

    /** magic：這個 web 路徑是否落在目前的上傳目錄下（供刪除等操作驗證）。 */
    public static function isUploadWebPath(string $path): bool
    {
        foreach (self::readableDirs() as $dir) {
            if (str_starts_with($path, '/' . $dir . '/')) return true;
        }
        return false;
    }

    /** Existing references remain readable only under the configured and fixed legacy roots. */
    public static function readableDirs(): array
    {
        return array_values(array_unique([self::dir(), self::DEFAULT_DIR]));
    }

    /** Resolve an existing upload file with the same canonical boundary for listing and deletion. */
    public static function resolveFile(string $path, ?string $webRoot = null): ?string
    {
        if (!self::isUploadWebPath($path) || str_contains($path, '..')
            || str_contains($path, "\0") || str_contains($path, '\\')) return null;
        $root = self::webRoot($webRoot);
        $dir = explode('/', ltrim($path, '/'), 2)[0];
        $uploadRoot = realpath($root . '/' . $dir);
        $web = realpath($root);
        $file = realpath($root . $path);
        if ($uploadRoot === false || $web === false || $file === false || !is_file($file)) return null;
        // A configured/legacy root itself may not be a link to outside the web root.
        if (!str_starts_with($uploadRoot, $web . DIRECTORY_SEPARATOR)
            || !str_starts_with($file, $uploadRoot . DIRECTORY_SEPARATOR)) return null;
        return $file;
    }

    // ───────────────────────── 內部 ─────────────────────────

    /** @return string|null null＝不是合法的單層目錄名 */
    private static function sanitize(string $name): ?string
    {
        $name = trim($name);
        // 單層、無點、無斜線：`..`／`a/b`／空字串一律拒絕。
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1 ? $name : null;
    }

    private static function webRoot(?string $webRoot = null): string
    {
        if ($webRoot !== null) {
            return $webRoot;
        }
        return defined('WEB_ROOT') ? WEB_ROOT : (defined('BASE_PATH') ? BASE_PATH : __DIR__);
    }

    /** 目錄裡有沒有真正的上傳檔（.htaccess／.user.ini 等防護檔不算）。 */
    private static function hasPayload(string $dir): bool
    {
        foreach ((array) @scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            return true;
        }
        return false;
    }

    private static function findExistingRandom(string $root): ?string
    {
        foreach ((array) @scandir($root) as $entry) {
            if (!is_string($entry) || !str_starts_with($entry, self::RANDOM_PREFIX)) {
                continue;
            }
            if (is_dir($root . '/' . $entry) && self::sanitize($entry) !== null) {
                return $entry;
            }
        }
        return null;
    }
}
