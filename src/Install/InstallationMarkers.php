<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

/**
 * 安裝生命週期的持久 marker。
 *
 * pending marker 只保存 ownership proof 的雜湊；原始 proof 留在 installer 的
 * native session。如此建立第一位管理員後可以跨 request 繼續，其他匿名 session
 * 即使看得到 pending 檔案，也不能重用安裝視窗。
 */
final class InstallationMarkers
{
    public const SESSION_KEY = 'install_marker_token';
    public const PENDING_FILE = 'install.pending';
    public const COMPLETE_FILE = 'install.lock';

    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $resolved = $storagePath;
        if ($resolved === null && defined('STORAGE_PATH')) {
            $resolved = STORAGE_PATH;
        }

        $resolved = rtrim((string) $resolved, "/\\");
        if ($resolved === '') {
            throw new \InvalidArgumentException('安裝 marker 需要明確的 storage 路徑');
        }

        $this->storagePath = $resolved;
    }

    public function pendingExists(): bool
    {
        return is_file($this->pendingPath());
    }

    public function completeExists(): bool
    {
        return is_file($this->completePath());
    }

    public function recoveryPending(): bool
    {
        if ($this->completeExists() || !$this->pendingExists()) { return false; }
        $data = json_decode((string) @file_get_contents($this->pendingPath()), true);
        return is_array($data) && ($data['state'] ?? null) === 'recovery';
    }

    /**
     * 取得或建立目前 installer session 的 pending ownership proof。
     *
     * @throws \RuntimeException 已完成、另一個 session 已持有，或無法可靠寫入時
     */
    public function begin(?string $existingToken = null): string
    {
        if ($this->completeExists()) {
            throw new \RuntimeException('安裝已完成，不得重新建立 pending marker');
        }

        $existingToken = trim((string) $existingToken);
        if ($this->pendingExists()) {
            if ($existingToken !== '' && $this->ownsPending($existingToken)) {
                return $existingToken;
            }
            throw new \RuntimeException('安裝流程已由另一個 session 啟動');
        }

        $this->requireWritableStorage();
        $token = bin2hex(random_bytes(32));
        $payload = json_encode([
            'state' => 'pending',
            'started_at' => gmdate(DATE_ATOM),
            'token_sha256' => hash('sha256', $token),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

        // x 模式是 atomic create：兩個同時抵達的 bootstrap request 只有一個能取得 ownership。
        $handle = @fopen($this->pendingPath(), 'x+b');
        if ($handle === false) {
            if ($existingToken !== '' && $this->ownsPending($existingToken)) {
                return $existingToken;
            }
            throw new \RuntimeException('無法取得安裝流程 ownership');
        }

        try {
            $written = fwrite($handle, $payload);
            if ($written !== strlen($payload) || !fflush($handle)) {
                throw new \RuntimeException('無法完整寫入 pending marker');
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($this->pendingPath());
            throw $e;
        }

        fclose($handle);
        @chmod($this->pendingPath(), 0600);
        return $token;
    }

    public function ownsPending(?string $token): bool
    {
        $token = trim((string) $token);
        if ($token === '' || !$this->pendingExists()) {
            return false;
        }

        $raw = file_get_contents($this->pendingPath());
        if (!is_string($raw)) {
            return false;
        }

        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        $expected = is_array($payload) ? ($payload['token_sha256'] ?? null) : null;
        return ($payload['state'] ?? '') === 'pending'
            && is_string($expected)
            && preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, hash('sha256', $token));
    }

    /** Rotate lost ownership without opening an anonymous installer window. */
    public function prepareResumeFromCli(): string
    {
        $this->requireCli();
        return $this->changePending(static function (array $old): array {
            $code = bin2hex(random_bytes(32));
            return [[
                'state' => 'recovery',
                'expires_at' => time() + 900,
                'recovery_sha256' => hash('sha256', $code),
            ], $code];
        });
    }

    /** Exchange a 15-minute one-time code for new browser-only ownership. */
    public function resumeFromCode(string $code): string
    {
        return $this->changePending(static function (array $old) use ($code): array {
            if (($old['state'] ?? '') !== 'recovery'
                || !is_int($old['expires_at'] ?? null) || $old['expires_at'] < time()
                || !is_string($old['recovery_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $code) !== 1
                || !hash_equals($old['recovery_sha256'], hash('sha256', $code))) {
                throw new \RuntimeException('復原碼無效或已過期');
            }
            $token = bin2hex(random_bytes(32));
            return [[
                'state' => 'pending',
                'started_at' => gmdate(DATE_ATOM),
                'token_sha256' => hash('sha256', $token),
            ], $token];
        });
    }

    /** Stable inode + EX lock serializes concurrent code exchanges. Torn writes fail closed. */
    private function changePending(callable $change): string
    {
        if ($this->completeExists() || !$this->pendingExists() || is_link($this->pendingPath())) {
            throw new \RuntimeException('安裝狀態不允許復原碼交換');
        }
        $handle = @fopen($this->pendingPath(), 'r+b');
        if ($handle === false) { throw new \RuntimeException('無法開啟復原 marker'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('無法鎖定復原 marker'); }
            if ($this->completeExists()) { throw new \RuntimeException('安裝已完成'); }
            $raw = stream_get_contents($handle);
            $old = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($old)) { throw new \RuntimeException('復原 marker 損壞，請保留供人工檢查'); }
            [$next, $secret] = $change($old);
            $bytes = json_encode($next, JSON_THROW_ON_ERROR) . "\n";
            if (!rewind($handle) || !ftruncate($handle, 0)
                || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                || !fsync($handle) || !rewind($handle) || stream_get_contents($handle) !== $bytes) {
                throw new \RuntimeException('無法可靠保存復原 marker');
            }
            return $secret;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 將 owner 的 pending 狀態原子地封閉成 complete marker。 */
    public function complete(string $token): void
    {
        if ($this->completeExists()) {
            return;
        }
        if (!$this->ownsPending($token)) {
            throw new \RuntimeException('安裝完成 proof 無效');
        }

        $this->writeCompleteAtomically('installer');

        // complete marker 已成為權威；即使清除失敗，也不會重新開放 installer。
        if ($this->pendingExists() && !@unlink($this->pendingPath())) {
            error_log('YS CRM: install.pending could not be removed after completion');
        }
    }

    /** DB 已明確證明是既有站台時，補回遺失的 complete marker。 */
    public function restoreComplete(): void
    {
        if ($this->completeExists()) {
            return;
        }
        if ($this->pendingExists()) {
            throw new \RuntimeException('pending 安裝不可被既有站台 self-heal 覆寫');
        }

        $this->writeCompleteAtomically('database_probe');
    }

    /** .env 尚未成功寫入時，僅 owner 可放棄剛取得的 pending marker。 */
    public function abandon(string $token): void
    {
        if (!$this->ownsPending($token)) {
            return;
        }
        if (!@unlink($this->pendingPath())) {
            throw new \RuntimeException('無法清除未完成的 pending marker');
        }
    }

    /**
     * 僅供本機 CLI recovery：DB 已證明沒有任何 user 時，可清除失去 session proof
     * 的 pending marker，讓部署者重新開始。HTTP 不得呼叫這條路徑。
     */
    public function resetPendingFromCli(): void
    {
        $this->requireCli();
        if ($this->completeExists()) {
            throw new \RuntimeException('安裝已完成，不得重設 pending marker');
        }
        if ($this->pendingExists() && !@unlink($this->pendingPath())) {
            throw new \RuntimeException('無法清除 pending marker');
        }
    }

    /**
     * 僅供本機 CLI recovery：上層已驗證完整 schema 與 active super-admin 後，
     * 才能在失去原 session proof 的情況下封閉 installer。
     */
    public function completePendingFromCli(): void
    {
        $this->requireCli();
        if ($this->completeExists()) {
            return;
        }
        if (!$this->pendingExists()) {
            throw new \RuntimeException('找不到 pending marker');
        }

        $this->writeCompleteAtomically('operator_recovery');
        if ($this->pendingExists() && !@unlink($this->pendingPath())) {
            error_log('YS CRM: install.pending could not be removed after CLI recovery');
        }
    }

    private function writeCompleteAtomically(string $source): void
    {
        $this->requireWritableStorage();
        $payload = json_encode([
            'state' => 'complete',
            'installed_at' => gmdate(DATE_ATOM),
            'source' => $source,
            'hash' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $temporaryPath = $this->completePath() . '.tmp.' . bin2hex(random_bytes(8));

        try {
            $written = file_put_contents($temporaryPath, $payload, LOCK_EX);
            if ($written !== strlen($payload)) {
                throw new \RuntimeException('無法完整寫入安裝完成 marker');
            }
            @chmod($temporaryPath, 0600);

            if (!@rename($temporaryPath, $this->completePath())) {
                clearstatcache(true, $this->completePath());
                if (!$this->completeExists()) {
                    throw new \RuntimeException('無法原子建立安裝完成 marker');
                }
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function requireWritableStorage(): void
    {
        if (!is_dir($this->storagePath) || !is_writable($this->storagePath)) {
            throw new \RuntimeException('storage 目錄不存在或不可寫入安裝 marker');
        }
    }

    private function requireCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('安裝 recovery 僅限本機 CLI');
        }
    }

    private function pendingPath(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . self::PENDING_FILE;
    }

    private function completePath(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . self::COMPLETE_FILE;
    }
}
