<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

/** Native-PHP bootstrap dependency: this file must remain beside index.php. */
final class InstallerMoveJournal
{
    public const PRIVATE_DIRS = ['config', 'views', 'routes', 'database', 'storage', 'cli', 'vendor', 'tests', 'docs', 'src'];
    public const PRIVATE_FILES = ['.env', '.env.example', 'composer.json', 'composer.lock', 'README.md', 'migrate.php'];
    private const JOURNAL = '/.ys_move_manifest.json';
    private const LOCK = '/.ys_move_operation.lock';
    /** Resources live until PHP request teardown, including later shutdown callbacks. */
    private static array $requestLeases = [];

    public static function startup(string $webRoot): true|string
    {
        $root = self::validatedRoot($webRoot);
        if ($root === null) {
            return '搬移根目錄無效';
        }
        if (isset(self::$requestLeases[$root])) {
            return true;
        }
        $lock = self::openLock($root);
        if (is_string($lock)) {
            // Compatibility with an existing deployment whose worker cannot create a lock.
            // A generic failure on a writable root is never treated as read-only mode.
            if (!is_writable($root) && !self::exists($root . self::JOURNAL)
                && !self::exists($root . self::LOCK)) {
                return true;
            }
            return $lock;
        }
        $retained = false;
        try {
            if (!flock($lock, LOCK_SH | LOCK_NB)) {
                return '搬移或回復正在執行，請稍後重試';
            }
            if (self::exists($root . self::JOURNAL)) {
                // Never upgrade competing readers in place. Re-read under exclusive ownership.
                flock($lock, LOCK_UN);
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    return '搬移或回復正在執行，請稍後重試';
                }
                $result = self::rollback($root);
                if ($result !== true) {
                    return $result;
                }
                if (!flock($lock, LOCK_SH | LOCK_NB)) {
                    return '無法保留啟動讀取鎖定';
                }
            }
            self::$requestLeases[$root] = $lock;
            $retained = true;
            return true;
        } finally {
            if (!$retained) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function recover(string $webRoot): true|string
    {
        return self::locked($webRoot, static fn(string $root): true|string => self::rollback($root));
    }

    public static function move(string $webRoot, ?callable $mover = null): true|string
    {
        if (!is_writable($webRoot)) {
            return '應用程式根目錄唯讀，無法搬移';
        }
        return self::locked($webRoot, static function (string $root) use ($mover): true|string {
            $recovery = self::rollback($root);
            if ($recovery !== true) {
                return $recovery;
            }
            $parent = dirname($root);
            if (!is_writable($parent)) {
                return '上層目錄不可寫入';
            }
            $plan = [];
            // src is the layout anchor and always moves last.
            $names = array_merge(array_diff(self::PRIVATE_DIRS, ['src']), self::PRIVATE_FILES, ['src']);
            foreach ($names as $name) {
                $source = $root . '/' . $name;
                if (!self::exists($source)) {
                    continue;
                }
                if (!self::validItem($source, $name)) {
                    return '搬移來源不是預期的檔案或目錄：' . $name;
                }
                if (self::exists($parent . '/' . $name)) {
                    return '目標已存在：' . $name . '；未執行任何搬移';
                }
                $plan[] = $name;
            }
            if ($plan === [] || !in_array('src', $plan, true)) {
                return '找不到可搬移的 private files；請重新確認目前目錄結構';
            }

            $journal = $root . self::JOURNAL;
            $raw = json_encode(['version' => 1, 'plan' => $plan], JSON_THROW_ON_ERROR) . "\n";
            if (!self::write($journal, $raw, false, '')) {
                return '無法持久化搬移計畫；保留標記，請停止安裝並檢查';
            }

            foreach ($plan as $name) {
                $source = $root . '/' . $name;
                $target = $parent . '/' . $name;
                // Recheck immediately before each rename; never overwrite a newly appeared target.
                if (!self::validItem($source, $name) || self::exists($target)) {
                    return '搬移路徑已變更：' . $name . '；保留標記，請停止安裝並檢查';
                }
                // Exceptions deliberately leave the write-ahead journal for startup recovery.
                $ok = $mover !== null ? (bool) $mover($source, $target) : @rename($source, $target);
                clearstatcache();
                if (!$ok || self::exists($source) || !self::validItem($target, $name)) {
                    $result = self::rollback($root, $mover);
                    return '無法搬移 ' . $name . ($result === true
                        ? '；已復原，未切換目錄結構'
                        : '；自動復原失敗：' . $result);
                }
                $checkpoint = json_encode(['completed' => $name], JSON_THROW_ON_ERROR) . "\n";
                if (!self::write($journal, $checkpoint, true, $raw)) {
                    return '無法持久化搬移 checkpoint；保留標記，請停止安裝並檢查';
                }
                $raw .= $checkpoint;
            }
            return self::removeJournal($journal);
        });
    }

    /** CLI recovery drains all native HTTP leases before revalidating DB/ownership. */
    public static function maintenance(string $webRoot, callable $operation): true|string
    {
        if (PHP_SAPI !== 'cli') { return '僅限本機 CLI'; }
        return self::locked($webRoot, static function (string $root) use ($operation): true|string {
            if (self::exists($root . self::JOURNAL)) { return '請先完成搬移復原'; }
            $operation();
            return true;
        });
    }

    private static function validatedRoot(string $webRoot): ?string
    {
        $root = realpath($webRoot);
        $normalize = static fn(string $path): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', rtrim($path, '/\\')))
            : rtrim($path, '/');
        if ($root === false || !is_dir($root) || is_link($webRoot)
            || $normalize($root) !== $normalize($webRoot) || dirname($root) === $root) {
            return null;
        }
        return $root;
    }

    /** @return resource|string */
    private static function openLock(string $root): mixed
    {
        $path = $root . self::LOCK;
        if (self::exists($path) && (is_link($path) || !is_file($path))) {
            return '搬移鎖定檔案無效';
        }
        $lock = self::exists($path) ? @fopen($path, 'rb') : @fopen($path, 'x+b');
        // Another process can win creation; both must still lock the same persistent file.
        if ($lock === false && is_file($path) && !is_link($path)) {
            $lock = @fopen($path, 'rb');
        }
        if ($lock === false) {
            return '無法開啟搬移鎖定檔案';
        }
        return $lock;
    }

    /** Move/recovery owns EX; a startup caller retains ownership until its redirect exits. */
    private static function locked(string $webRoot, callable $operation): true|string
    {
        $root = self::validatedRoot($webRoot);
        if ($root === null) {
            return '搬移根目錄無效';
        }
        $hadLease = isset(self::$requestLeases[$root]);
        $flatBefore = is_dir($root . '/src');
        $lock = $hadLease ? self::$requestLeases[$root] : self::openLock($root);
        if (is_string($lock)) {
            return $lock;
        }
        if ($hadLease) {
            unset(self::$requestLeases[$root]);
            flock($lock, LOCK_UN);
        }
        $retained = false;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                if ($hadLease) {
                    // If a competing writer won, do not resume an unprotected loaded layout.
                    if (!flock($lock, LOCK_SH | LOCK_NB)
                        || self::exists($root . self::JOURNAL) || is_dir($root . '/src') !== $flatBefore) {
                        http_response_code(503);
                        exit('Service Unavailable');
                    }
                    self::$requestLeases[$root] = $lock;
                    $retained = true;
                }
                return '搬移或回復正在執行，請稍後重試';
            }
            if ($hadLease) {
                if (self::exists($root . self::JOURNAL) || is_dir($root . '/src') !== $flatBefore) {
                    http_response_code(503);
                    exit('Service Unavailable');
                }
                self::$requestLeases[$root] = $lock;
                $retained = true;
            }
            return $operation($root);
        } finally {
            if (!$retained) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return list<string>|string A complete, strictly validated plan, or an error. */
    private static function readPlan(string $journal): array|string
    {
        if (!is_file($journal) || is_link($journal)) {
            return '搬移標記不是一般檔案';
        }
        $raw = @file_get_contents($journal);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 16384 || !str_ends_with($raw, "\n")) {
            return '搬移標記損壞或不完整';
        }
        $lines = explode("\n", substr($raw, 0, -1));
        try {
            $header = json_decode(array_shift($lines), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($header) || array_keys($header) !== ['version', 'plan']
                || $header['version'] !== 1 || !is_array($header['plan'])
                || !array_is_list($header['plan']) || $header['plan'] === []) {
                return '搬移標記格式無效';
            }
            $plan = $header['plan'];
            $allowed = array_merge(self::PRIVATE_DIRS, self::PRIVATE_FILES);
            $seen = [];
            foreach ($plan as $name) {
                if (!is_string($name) || !in_array($name, $allowed, true) || isset($seen[$name])) {
                    return '搬移標記路徑無效';
                }
                $seen[$name] = true;
            }
            if (end($plan) !== 'src' || count($lines) > count($plan)) {
                return '搬移標記計畫不完整';
            }
            foreach ($lines as $index => $line) {
                $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if ($record !== ['completed' => $plan[$index]]) {
                    return '搬移 checkpoint 無效';
                }
            }
            return $plan;
        } catch (\JsonException) {
            return '搬移標記損壞或不完整';
        }
    }

    /** Complete plan is durable BEFORE rename; checkpoints are append-only diagnostics. */
    private static function write(string $path, string $bytes, bool $append, string $previous): bool
    {
        if ($append && (!is_file($path) || is_link($path) || @file_get_contents($path) !== $previous)) {
            return false;
        }
        $stream = @fopen($path, $append ? 'ab' : 'x+b');
        if ($stream === false) {
            return false;
        }
        try {
            if (@fwrite($stream, $bytes) !== strlen($bytes) || !@fflush($stream) || !@fsync($stream)) {
                return false;
            }
        } finally {
            fclose($stream);
        }
        clearstatcache(true, $path);
        return @file_get_contents($path) === $previous . $bytes;
    }

    private static function rollback(string $root, ?callable $mover = null): true|string
    {
        $journal = $root . self::JOURNAL;
        if (!self::exists($journal)) {
            return true;
        }
        $plan = self::readPlan($journal);
        if (is_string($plan)) {
            return $plan . '；保留標記，請停止安裝並檢查';
        }
        $parent = dirname($root);
        // Refuse ambiguity before moving anything; never skip collisions or missing copies.
        foreach ($plan as $name) {
            $source = $root . '/' . $name;
            $target = $parent . '/' . $name;
            $hasSource = self::exists($source);
            $hasTarget = self::exists($target);
            if ($hasSource === $hasTarget || !self::validItem($hasSource ? $source : $target, $name)) {
                return '回復路徑衝突或遺失：' . $name . '；保留標記，請停止安裝並檢查';
            }
        }
        foreach (array_reverse($plan) as $name) {
            $source = $root . '/' . $name;
            $target = $parent . '/' . $name;
            if (self::exists($source)) {
                if (self::exists($target) || !self::validItem($source, $name)) {
                    return '回復路徑已變更：' . $name;
                }
                continue;
            }
            if (!self::validItem($target, $name)) {
                return '回復來源已變更：' . $name;
            }
            $ok = $mover !== null ? (bool) $mover($target, $source) : @rename($target, $source);
            clearstatcache();
            if (!$ok || self::exists($target) || !self::validItem($source, $name)) {
                return '中斷搬移回復失敗：' . $name . '；保留標記，請停止安裝並檢查';
            }
        }
        return self::removeJournal($journal);
    }

    private static function removeJournal(string $path): true|string
    {
        if (!@unlink($path)) {
            return '無法移除已完成的搬移標記；請停止安裝並檢查';
        }
        clearstatcache(true, $path);
        return !self::exists($path) ? true : '搬移標記仍存在';
    }

    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);
        return file_exists($path) || is_link($path);
    }

    private static function validItem(string $path, string $name): bool
    {
        return !is_link($path) && (in_array($name, self::PRIVATE_DIRS, true) ? is_dir($path) : is_file($path));
    }
}
