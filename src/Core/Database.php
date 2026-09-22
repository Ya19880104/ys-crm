<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private string $prefix;
    /** Database name is part of MySQL named-lock identity (locks are server-global). */
    private string $databaseName = '';

    /**
     * 交易巢狀深度。支援 transaction() 巢狀呼叫（如 RecurringService 包住 QuoteService/PaymentService，
     * 而後者本身也開 transaction）。最外層用真實 BEGIN/COMMIT，內層以 SAVEPOINT 實作，
     * 避免 PDO「There is already an active transaction」。
     */
    private int $txLevel = 0;

    private function __construct(array $config)
    {
        $this->databaseName = (string) ($config['database'] ?? '');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // PHP 8.5+: PDO::MYSQL_ATTR_INIT_COMMAND deprecated, charset 已由 DSN 指定
        ]);

        $this->prefix = $config['prefix'] ?? 'ys_crm_';

        $this->syncTimeZone();
    }

    /**
     * 把本站時區同步到這條連線，讓 MySQL 的 NOW() 與 PHP 的 date() 指同一個牆上時刻。
     *
     * 🔴🔴 【為什麼一定要做】這台主機是 UTC，而 App 啟動時會
     * `date_default_timezone_set('Asia/Taipei')`。連線不設時區時：
     *
     *     PHP   date('Y-m-d H:i:s') → 2026-09-02 22:21:50  （台北）
     *     MySQL NOW()               → 2026-09-02 14:21:50  （UTC）
     *
     * 差 8 小時，而欄位是 DATETIME（不帶時區），存進去之後**看不出來是哪個時鐘寫的**。
     * 任何「PHP 算時刻 → 寫入 → 用 NOW() 比較」的程式碼都會整整錯 8 小時，
     * 沒有任何錯誤訊息，後台顯示的每一個時間也都比實際早 8 小時。
     *
     * 實際踩到的：發票開立失敗後 markFailed() 用 PHP 時鐘寫 next_retry_at，
     * 而 getDueRetryIds() 用 `next_retry_at <= NOW()` 撈 —— 排定 22:26 重試的發票，
     * 要等 DB 自己的 22:26（台北時間隔天 06:26）才撈得到。
     *
     * 【為什麼傳數值偏移而不是 'Asia/Taipei'】具名時區需要 MySQL 載入 tz 資料表，
     * 多數安裝沒有載，`SET time_zone = 'Asia/Taipei'` 會直接報錯。數值偏移一定支援。
     *
     * 【為什麼失敗不丟錯】託管環境可能不允許 SET time_zone。設不起來時行為與
     * 修正前相同（不會更糟），而會出問題的比較都已另外改成由 SQL 端產生時刻 ——
     * 這裡是把地基補平，不是唯一的防線。
     */
    private function syncTimeZone(): void
    {
        self::syncPdoTimeZone($this->pdo);
    }

    /**
     * 對任何繞過 singleton 的 PDO 套用同一 App 時鐘契約。
     *
     * Installer、獨立 migrate CLI 都會直接建立 PDO；若漏掉這一步，初始資料會以
     * MySQL server 時區寫入，而後續 runtime 連線改用 App 時區，形成混合 timestamp。
     */
    public static function syncPdoTimeZone(PDO $pdo, ?string $timezone = null): void
    {
        try {
            $stmt = $pdo->prepare('SET time_zone = ?');
            $stmt->execute([self::appTimeZoneOffset($timezone)]);
        } catch (\Throwable) {
            // 見上：設不起來時退回原本行為，不讓連線本身失敗。
        }
    }

    /**
     * 本站時區相對 UTC 的偏移（例如 +08:00）。
     *
     * 🔴 【為什麼讀設定檔而不是 date_default_timezone_get()】
     * 連線可能在 `date_default_timezone_set()` 之前就被建立 —— 實際發生過：
     * App::run() 先呼叫 Session::start()，而 session handler 會建 DB 連線，
     * 那時 PHP 還停在 php.ini 的預設時區（本機是 UTC），於是連線被設成 +00:00，
     * 整個 web 路徑的 NOW() 差 8 小時；CLI 因為順序相反反而是對的。
     *
     * 啟動順序已經修好了，但「正確性依賴呼叫順序」本身就是個陷阱：
     * 下一個人加一行初始化程式就可能再踩一次，而症狀只是時間欄位悄悄差 8 小時。
     * 直接讀設定檔，答案就與順序無關。
     */
    private static function appTimeZoneOffset(?string $timezone = null): string
    {
        $tz = $timezone;

        if (($tz === null || $tz === '') && defined('CONFIG_PATH') && is_file(CONFIG_PATH . '/app.php')) {
            $cfg = require CONFIG_PATH . '/app.php';
            $tz  = is_array($cfg) ? ($cfg['timezone'] ?? null) : null;
        }

        try {
            $zone = new \DateTimeZone(is_string($tz) && $tz !== '' ? $tz : 'Asia/Taipei');
        } catch (\Throwable) {
            $zone = new \DateTimeZone('Asia/Taipei');
        }

        return (new \DateTimeImmutable('now', $zone))->format('P');
    }

    /**
     * 取得 singleton 實例
     */
    public static function getInstance(?array $config = null): static
    {
        if (self::$instance === null) {
            if ($config === null) {
                $config = require CONFIG_PATH . '/database.php';
            }
            self::$instance = new static($config);
        }
        return self::$instance;
    }

    /**
     * 重設 singleton（用於安裝流程切換 DB）
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * 建立新連線（不影響 singleton，用於安裝測試連線）
     */
    public static function testConnection(array $config, ?string $timezone = null): PDO
    {
        // Identifiers cannot be parameter-bound; reject before opening a connection.
        $dbName = $config['database'] ?? null;
        if (!is_string($dbName) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $dbName) !== 1) {
            throw new \InvalidArgumentException('資料庫名稱須為 1–64 個英文字母、數字或底線。');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['charset'] ?? 'utf8mb4'
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // 嘗試選擇或建立資料庫
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
        self::syncPdoTimeZone($pdo, $timezone);

        return $pdo;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Build a MySQL named-lock name shared by every caller in this application.
     *
     * MySQL named locks are server-local but server-global across schemas, so the
     * database name, table prefix, and logical resource must all participate in
     * the identity. The digest keeps arbitrary deployment prefixes and resource
     * identifiers below MySQL's 64-byte limit without exposing them in diagnostics.
     */
    public static function buildNamedLockName(string $databaseName, string $tablePrefix, string $resource): string
    {
        $material = implode("\0", ['ys-crm-lock-v1', $databaseName, $tablePrefix, $resource]);

        // "yscrm:v1:" is 9 ASCII bytes; 9 + 55 hex bytes = MySQL's 64-byte maximum.
        return 'yscrm:v1:' . substr(hash('sha256', $material), 0, 55);
    }

    /** Build a stable MySQL named-lock name for this database connection. */
    public function namedLockName(string $resource): string
    {
        return self::buildNamedLockName($this->databaseName, $this->getPrefix(), $resource);
    }

    /**
     * 在 SQL 中替換 {prefix} 佔位符
     */
    public function applyPrefix(string $sql): string
    {
        return str_replace('{prefix}', $this->prefix, $sql);
    }

    /**
     * 執行查詢並回傳 PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $sql = $this->applyPrefix($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * 執行寫入操作，回傳影響行數
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 取得單筆
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * 取得多筆
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * 取得單一欄位值
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn($column);
    }

    /**
     * 取得最後插入的 ID
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * 交易操作
     */
    public function beginTransaction(): void
    {
        if ($this->txLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            // 內層：建立 SAVEPOINT（MySQL/SQLite 皆支援）。
            $this->pdo->exec('SAVEPOINT ys_sp_' . $this->txLevel);
        }
        $this->txLevel++;
    }

    public function commit(): void
    {
        if ($this->txLevel <= 0) {
            return;
        }
        $nextLevel = $this->txLevel - 1;
        if ($nextLevel === 0) {
            $this->pdo->commit();
        } else {
            // 釋放內層 SAVEPOINT（變更併入外層交易，外層 commit 時才真正落地）。
            $this->pdo->exec('RELEASE SAVEPOINT ys_sp_' . $nextLevel);
        }
        // A failed COMMIT may still leave the real transaction open for rollback.
        $this->txLevel = $nextLevel;
    }

    public function rollBack(): void
    {
        if ($this->txLevel <= 0) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            // COMMIT may have succeeded before the connection reported an error.
            $this->txLevel = 0;
            return;
        }
        $nextLevel = $this->txLevel - 1;
        if ($nextLevel === 0) {
            $this->pdo->rollBack();
        } else {
            // 回滾到內層 SAVEPOINT（僅撤銷內層變更，外層交易仍續行）。
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ys_sp_' . $nextLevel);
            $this->pdo->exec('RELEASE SAVEPOINT ys_sp_' . $nextLevel);
        }
        $this->txLevel = $nextLevel;
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollBack();
            } catch (\Throwable) {
                // Preserve the original failure/uncertain outcome, not cleanup noise.
            }
            throw $e;
        }
    }
}
