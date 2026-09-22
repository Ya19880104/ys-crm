<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

use YangSheep\CRM\Core\Encryption;
use YangSheep\CRM\Core\UploadPath;

class EnvWriter
{
    /** Secret-bearing env artifacts never inherit group/other access from umask. */
    public const SECRET_FILE_MODE = 0600;

    /**
     * 取得 .env 最佳寫入路徑
     *
     * 優先寫入 BASE_PATH 上一層（更安全，完全在 web root 之外）
     * 若上一層不可寫入，則 fallback 到 BASE_PATH
     *
     * @return array{path: string, secure: bool, display: string}
     */
    public static function getEnvPath(): array
    {
        // 若已有 .env 存在（已安裝），使用現有位置
        if (defined('ENV_PATH') && file_exists(ENV_PATH . '/.env')) {
            $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
            return [
                'path'    => ENV_PATH,
                'secure'  => ENV_PATH !== $webRoot,
                'display' => ENV_PATH,
            ];
        }

        // 嘗試 web root 上一層目錄（最安全位置）
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $parentDir = dirname($webRoot);
        if ($parentDir !== $webRoot && is_dir($parentDir) && is_writable($parentDir)) {
            return [
                'path'    => $parentDir,
                'secure'  => true,
                'display' => $parentDir,
            ];
        }

        // Fallback 到 BASE_PATH
        return [
            'path'    => BASE_PATH,
            'secure'  => false,
            'display' => BASE_PATH,
        ];
    }

    /**
     * 將單一 .env value 格式化為可安全寫入的字面（含必要的引號與跳脫）。
     *
     * 安全性：
     *  - 強制剝除 CR / LF / NUL，避免攻擊者於安裝期把換行塞進 value 而注入額外的
     *    KEY=VALUE 行（.env 注入）。
     *  - 跳脫規則與 DotEnv::load() 的還原邏輯「對稱」：
     *      含空白 / '#' / '"' / '\\' 時用雙引號包裹，並只 escape '\\' → '\\\\'、
     *      '"' → '\\"'。載入端依序還原 '\\n'/'\\t'/'\\"'/'\\\\'，可完整 round-trip
     *      含反斜線與雙引號的金鑰。
     *
     * @param mixed $value 原始值（一律轉字串處理）
     */
    private static function formatValue(mixed $value): string
    {
        // 1. 強制剝除換行類字元（防 .env 注入）
        $value = str_replace(["\r", "\n", "\0"], '', (string) $value);

        // 2. 僅在需要時包裹雙引號並跳脫（與 DotEnv 還原對稱）
        if (
            str_contains($value, ' ')
            || str_contains($value, '#')
            || str_contains($value, '"')
            || str_contains($value, '\\')
        ) {
            // 先跳脫反斜線，再跳脫雙引號（順序固定，避免互相干擾）
            $escaped = str_replace('\\', '\\\\', $value);
            $escaped = str_replace('"', '\\"', $escaped);
            return '"' . $escaped . '"';
        }

        return $value;
    }

    /**
     * 寫入 .env 檔案
     */
    public static function write(array $values): void
    {
        $lines = [];

        foreach ($values as $key => $value) {
            // 空 key 作為空行分隔
            if ($key === '') {
                $lines[] = '';
                continue;
            }
            $lines[] = "{$key}=" . self::formatValue($value);
        }

        $content = implode("\n", $lines) . "\n";
        $envInfo = self::getEnvPath();
        $envPath = $envInfo['path'] . '/.env';

        self::replaceFileAtomically($envPath, $content);
    }

    /**
     * 更新 .env 中的特定值
     */
    public static function update(string $key, string $value): void
    {
        self::updateMany([$key => $value]);
    }

    /** Update all requested keys through one complete-file replacement. */
    public static function updateMany(array $values): void
    {
        $envPath = self::findEnvFile();

        if ($envPath === null) {
            throw new \RuntimeException('.env 檔案不存在');
        }

        self::updateFile($envPath, $values);
    }

    /**
     * Update one explicit env file with rollback and byte-for-byte readback.
     *
     * The optional writer is a test seam for short-write / disk failure injection.
     * Production always uses replaceFileAtomically().
     *
     * @param array<string,mixed> $values
     * @param null|callable(string,string):void $writer
     * @param null|callable():void $afterOriginalBackup test-only hard-stop seam
     * @param null|callable():void $afterStagingCreate test-only incomplete-write seam
     */
    public static function updateFile(
        string $envPath,
        array $values,
        ?callable $writer = null,
        ?callable $afterOriginalBackup = null,
        ?callable $afterStagingCreate = null
    ): void {
        if (!is_file($envPath) || !is_readable($envPath)) {
            throw new \RuntimeException('.env 檔案不存在或無法讀取');
        }

        $original = file_get_contents($envPath);
        if ($original === false) {
            throw new \RuntimeException('無法讀取 .env 檔案');
        }
        $content = $original;

        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) !== 1) {
                throw new \InvalidArgumentException('無效的 .env key');
            }

            $pattern = "/^" . preg_quote((string) $key, '/') . "=.*/m";
            $formatted = self::formatValue($value);

            if (preg_match($pattern, $content) === 1) {
                // 以 callback 取代，避免 $formatted 內若含 '$n' 等被當成反向參照。
                $replaced = preg_replace_callback(
                    $pattern,
                    static fn () => "{$key}={$formatted}",
                    $content
                );
                if ($replaced === null) {
                    throw new \RuntimeException('無法更新 .env 內容');
                }
                $content = $replaced;
            } else {
                if ($content !== '' && !str_ends_with($content, "\n")) {
                    $content .= "\n";
                }
                $content .= "{$key}={$formatted}\n";
            }
        }

        try {
            if ($writer !== null) {
                $writer($envPath, $content);
            } else {
                self::replaceFileAtomically(
                    $envPath,
                    $content,
                    $afterOriginalBackup,
                    $afterStagingCreate
                );
            }

            $readback = file_get_contents($envPath);
            if ($readback === false || !hash_equals($content, $readback)) {
                throw new \RuntimeException('.env atomic replace readback 不一致');
            }
        } catch (\Throwable $error) {
            // A test seam may deliberately short-write the target; production replace
            // is itself rollback-safe, and this second layer restores exact old bytes.
            try {
                self::replaceFileAtomically($envPath, $original);
            } catch (\Throwable $restoreError) {
                throw new \RuntimeException(
                    '.env 更新與復原皆失敗：' . $restoreError->getMessage(),
                    0,
                    $error
                );
            }
            throw $error;
        }
    }

    /**
     * Recover a replacement interrupted by process termination or power loss.
     *
     * Pending content is completely written, flushed, and closed before the original is
     * moved. Recovery therefore finishes the prepared replacement when possible; if no
     * prepared target remains, it restores the exact backup. No file contents are logged.
     */
    public static function recoverInterruptedReplace(string $path): bool
    {
        $backup = $path . '.replace-backup';
        $pending = $path . '.replace-pending';
        if (!is_file($backup) && !is_file($pending) && self::stagingFiles($path) === []) {
            return false;
        }

        $lock = self::acquireReplaceLock($path);
        try {
            return self::recoverInterruptedReplaceUnlocked($path);
        } finally {
            self::releaseReplaceLock($lock);
        }
    }

    /** Same-directory replacement with deterministic, cross-process recovery files. */
    private static function replaceFileAtomically(
        string $path,
        string $content,
        ?callable $afterOriginalBackup = null,
        ?callable $afterStagingCreate = null
    ): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('無法寫入 .env 所在目錄');
        }

        $pending = $path . '.replace-pending';
        $backup = $path . '.replace-backup';
        $staging = '';
        $lock = self::acquireReplaceLock($path);
        try {
            self::recoverInterruptedReplaceUnlocked($path);
            if (is_file($path)) {
                self::restrictSecretFilePermissions($path);
            }

            // An incomplete write must never have the deterministic "prepared" name.
            // Only a fully flushed/closed unique staging file is atomically promoted to
            // .replace-pending; recovery always discards leftover staging files.
            $staging = $path . '.replace-staging-' . bin2hex(random_bytes(8));
            $pendingHandle = @fopen($staging, 'x+b');
            if ($pendingHandle === false) {
                throw new \RuntimeException('無法建立 .env staging 檔');
            }
            try {
                // Set the restrictive mode before the first secret byte is written. A
                // hard kill in the following test hook must still leave a 0600 artifact.
                self::restrictSecretFilePermissions($staging);
                if ($afterStagingCreate !== null) {
                    $afterStagingCreate();
                }
                $remaining = $content;
                while ($remaining !== '') {
                    $written = fwrite($pendingHandle, $remaining);
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('.env pending 檔寫入不完整');
                    }
                    $remaining = substr($remaining, $written);
                }
                if (!fflush($pendingHandle)) {
                    throw new \RuntimeException('.env pending 檔 flush 失敗');
                }
                if (function_exists('fsync') && !@fsync($pendingHandle)) {
                    throw new \RuntimeException('.env pending 檔同步失敗');
                }
            } finally {
                fclose($pendingHandle);
            }

            if (!rename($staging, $pending)) {
                throw new \RuntimeException('無法宣告完整的 .env pending 檔');
            }
            $staging = '';

            if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
                if (!rename($path, $backup)) {
                    throw new \RuntimeException('無法建立 .env replace backup');
                }
                if ($afterOriginalBackup !== null) {
                    $afterOriginalBackup();
                }
            }

            if (!rename($pending, $path)) {
                if (is_file($backup) && !is_file($path)) {
                    @rename($backup, $path);
                }
                throw new \RuntimeException('無法原子替換 .env 檔案');
            }

            self::restrictSecretFilePermissions($path);
            if (is_file($backup) && !@unlink($backup)) {
                throw new \RuntimeException('無法清除 .env replace backup');
            }
        } finally {
            if (is_file($backup) && !is_file($path)) {
                @rename($backup, $path);
            }
            if (is_file($pending)) {
                @unlink($pending);
            }
            if ($staging !== '' && is_file($staging)) {
                @unlink($staging);
            }
            self::releaseReplaceLock($lock);
        }
    }

    /** @return resource */
    private static function acquireReplaceLock(string $path)
    {
        $handle = @fopen($path . '.replace-lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('無法建立 .env replace lock');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('無法取得 .env replace lock');
        }
        return $handle;
    }

    /** @param resource $handle */
    private static function releaseReplaceLock($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** Caller must hold the deterministic replace lock. */
    private static function recoverInterruptedReplaceUnlocked(string $path): bool
    {
        $backup = $path . '.replace-backup';
        $pending = $path . '.replace-pending';
        foreach (array_merge([$path, $backup, $pending], self::stagingFiles($path)) as $secretFile) {
            if (is_file($secretFile)) {
                self::restrictSecretFilePermissions($secretFile);
            }
        }
        $handled = false;
        foreach (self::stagingFiles($path) as $staging) {
            // The staging name is deliberately never a commit marker. Whether empty,
            // partial, or complete, a prior process did not atomically declare it ready.
            if (!@unlink($staging)) {
                throw new \RuntimeException('無法清除中斷的 .env staging 檔');
            }
            $handled = true;
        }
        $hasBackup = is_file($backup);
        $hasPending = is_file($pending);
        if (!$hasBackup && !$hasPending) {
            return $handled;
        }

        if ($hasPending) {
            if (file_get_contents($pending) === false) {
                throw new \RuntimeException('中斷的 .env pending 檔無法讀取');
            }

            // Crash before the Windows first rename (target + pending) resumes the same
            // deterministic replace. Crash after it (backup + pending) promotes directly.
            if (is_file($path)) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    if ($hasBackup) {
                        throw new \RuntimeException('.env replace recovery 狀態衝突');
                    }
                    if (!rename($path, $backup)) {
                        throw new \RuntimeException('無法保存 recovery 前的 .env');
                    }
                    $hasBackup = true;
                }
            }

            if (!rename($pending, $path)) {
                if ($hasBackup && !is_file($path)) {
                    @rename($backup, $path);
                }
                throw new \RuntimeException('無法完成中斷的 .env replace');
            }
            if ($hasBackup && is_file($backup) && !@unlink($backup)) {
                throw new \RuntimeException('無法清除中斷的 .env backup');
            }
            return true;
        }

        // Target already exists means pending promotion completed and only cleanup was
        // interrupted. Without a target/pending, restoring backup is the only safe state.
        if (is_file($path)) {
            if (!@unlink($backup)) {
                throw new \RuntimeException('無法清除已完成 replace 的 .env backup');
            }
            return true;
        }
        if (!rename($backup, $path)) {
            throw new \RuntimeException('無法還原中斷 replace 的 .env backup');
        }
        return true;
    }

    private static function restrictSecretFilePermissions(string $path): void
    {
        $changed = @chmod($path, self::SECRET_FILE_MODE);
        // Windows ACLs are not represented by POSIX mode bits; chmod is best-effort there.
        // POSIX deployment must fail closed rather than expose DB credentials or APP_KEY.
        if (!$changed && DIRECTORY_SEPARATOR !== '\\') {
            throw new \RuntimeException('無法限制 .env 檔案權限');
        }
    }

    /** @return list<string> */
    private static function stagingFiles(string $path): array
    {
        $matches = glob($path . '.replace-staging-*', GLOB_NOSORT);
        if (!is_array($matches)) {
            return [];
        }
        return array_values(array_filter($matches, 'is_file'));
    }

    /**
     * 搜尋 .env 檔案的實際位置
     */
    public static function findEnvFile(): ?string
    {
        // 優先使用 ENV_PATH（若已定義）
        if (defined('ENV_PATH') && file_exists(ENV_PATH . '/.env')) {
            return ENV_PATH . '/.env';
        }

        // 搜尋候選位置
        $candidates = [
            dirname(BASE_PATH) . '/.env',
            BASE_PATH . '/.env',
        ];

        foreach ($candidates as $path) {
            self::recoverInterruptedReplace($path);
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 將 installer 的時區輸入正規化成 PHP 可重建的 canonical 名稱。
     */
    public static function normalizeAppTimeZone(mixed $timezone): string
    {
        $timezone = trim((string) ($timezone ?? ''));
        if ($timezone === '') {
            $timezone = 'Asia/Taipei';
        }

        try {
            return (new \DateTimeZone($timezone))->getName();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('無效時區，請重新選擇。');
        }
    }

    /**
     * 產生 .env 預設內容（含隨機 APP_KEY）。
     *
     * APP_TIMEZONE 在 Step 2 就固定，確保隨後 migrations、seeders 與管理員建立
     * 使用的 DB/PHP 時鐘從第一筆資料起一致。
     */
    public static function generateDefaults(
        array $dbConfig,
        string $appUrl,
        string $appTimezone = 'Asia/Taipei'
    ): array
    {
        $appUrl = rtrim(trim($appUrl), '/');
        if (preg_match('#^https?://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$#i', $appUrl) !== 1) {
            throw new \InvalidArgumentException('無法從可信伺服器設定取得 APP_URL');
        }
        $appTimezone = self::normalizeAppTimeZone($appTimezone);

        return [
            'APP_ENV'       => 'production',
            'APP_DEBUG'     => 'false',
            'APP_KEY'       => Encryption::generateKey(),
            'APP_URL'       => $appUrl,
            'APP_TIMEZONE'  => $appTimezone,
            // 上傳目錄名。全新安裝產生隨機尾碼，讓 uploads 路徑不可被猜到；
            // provision() 會沿用磁碟上既有的目錄，所以重裝不會製造孤兒檔。
            // 既有安裝（.env 沒這一行）自動退回 'uploads'，路徑完全不變。
            'UPLOADS_DIR'   => UploadPath::provision(),
            ''              => '', // 空行分隔
            'DB_HOST'       => $dbConfig['host'] ?? '127.0.0.1',
            'DB_PORT'       => $dbConfig['port'] ?? '3306',
            'DB_DATABASE'   => $dbConfig['database'] ?? 'ys_crm',
            'DB_USERNAME'   => $dbConfig['username'] ?? 'root',
            'DB_PASSWORD'   => $dbConfig['password'] ?? '',
            'DB_CHARSET'    => $dbConfig['charset'] ?? 'utf8mb4',
            'DB_PREFIX'     => $dbConfig['prefix'] ?? 'ys_crm_',
            // Turnstile（稍後設定）
            'TURNSTILE_SITE_KEY'   => '',
            'TURNSTILE_SECRET_KEY' => '',
            // SMTP（稍後設定）
            'SMTP_HOST'         => '',
            'SMTP_PORT'         => '587',
            'SMTP_USERNAME'     => '',
            'SMTP_PASSWORD'     => '',
            'SMTP_FROM_ADDRESS' => '',
            'SMTP_FROM_NAME'    => '',
        ];
    }
}
