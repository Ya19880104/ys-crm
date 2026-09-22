<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

// Flat layout resolves from src; split layout resolves from the unchanged web root.
require_once is_file(dirname(__DIR__, 2) . '/installer-move.php')
    ? dirname(__DIR__, 2) . '/installer-move.php'
    : WEB_ROOT . '/installer-move.php';

class EnvironmentChecker
{
    /** 需要搬移到 web root 外的目錄 */
    private const PRIVATE_DIRS = InstallerMoveJournal::PRIVATE_DIRS;

    /** 需要搬移的檔案 */
    private const PRIVATE_FILES = InstallerMoveJournal::PRIVATE_FILES;

    /** @var null|callable(string, string): bool 測試可注入單次 move failure。 */
    private $movePath;

    public function __construct(?callable $movePath = null)
    {
        $this->movePath = $movePath;
    }

    /**
     * 執行所有環境檢測
     */
    public function check(): array
    {
        $results = [
            'php_version'      => $this->checkPhpVersion(),
            'pdo_mysql'        => $this->checkExtension('pdo_mysql', 'PDO MySQL 擴充'),
            'mbstring'         => $this->checkExtension('mbstring', 'mbstring 擴充'),
            'openssl'          => $this->checkExtension('openssl', 'OpenSSL 擴充'),
            'json'             => $this->checkExtension('json', 'JSON 擴充'),
            'dir_structure'    => $this->checkDirectoryStructure(),
            'storage_writable' => $this->checkWritable(STORAGE_PATH, 'storage/ 目錄'),
            'env_writable'     => $this->checkEnvWritable(),
            'autoloader'       => $this->checkAutoloader(),
            'uploads_guard'    => $this->checkUploadsGuard(),
            'secret_files'     => $this->checkSecretFiles(),
        ];

        return $results;
    }

    /**
     * 掃描 web root 內「不該被公開下載」的機密檔。
     *
     * 【為什麼只偵測、不自動搬移】
     * InstallerMoveJournal 的 readPlan() 以 PRIVATE_DIRS/PRIVATE_FILES 做**精確白名單**
     * 驗證搬移計畫 —— 「搬移器永遠只碰這 16 個已知路徑」是一個有價值的安全不變量。
     * 要動態納入 glob 到的檔案，就得把該白名單改成 pattern 比對，等於為了次要目標
     * 削弱主要防線。而且使用者的 deploy.env / 憑證檔可能正被他自己的部署工具讀取，
     * 未經同意搬走會把對方的流程弄壞。故此處明確回報並給出處置，由人決定。
     *
     * 【為什麼這件事在 nginx 上特別嚴重】
     * 這類檔案是**靜態檔**：.htaccess 在 nginx 無效，.user.ini 只管 PHP 執行不管靜態服務，
     * index.php 也攔不到（檔案存在時 rewrite 條件不成立，web server 直接送出）。
     * 也就是說在 nginx 虛擬主機上，應用層**沒有任何手段**能擋住它們 —— 只能不要放進來。
     */
    private function checkSecretFiles(): array
    {
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $found   = self::scanSecretFiles($webRoot);

        if ($found === []) {
            // 措辭必須貼合實際檢查範圍：只掃根層一層，不遞迴。
            // 寫成「web root 內未發現」會超出證據範圍（storage/logs/*.log、
            // database/**/*.sql 在扁平結構下同樣可能被靜態送出）。
            return [
                'pass'   => true,
                'label'  => '機密檔外洩',
                'detail' => 'web root 根層未發現機密檔（未遞迴掃描子目錄）',
            ];
        }

        // detail 顯示在右側單行，只放摘要；檔名與指令交給 manual_commands 區塊，
        // 那裡是等寬字型的 <pre>，可直接複製執行。
        $parent = dirname($webRoot);
        $cmds   = ['# 這些檔案在 Nginx 上會被當靜態檔直接下載，應用層攔不到。'];
        foreach ($found as $name) {
            // 🔴 檔名來自 scandir()，而這段文字的用途是「請管理者貼進 root shell 執行」。
            // 含空白或 shell metacharacter 的檔名不 quote 會產生語意錯誤的指令，
            // 最壞情況是把可控字串餵進特權 shell。這個功能存在的前提正是
            // 「假設有非預期檔案落進樹裡」，所以不能假設檔名乾淨。
            $cmds[] = 'mv ' . escapeshellarg($webRoot . '/' . $name)
                . ' ' . escapeshellarg($parent . '/' . $name);
        }

        return [
            'pass'    => true, // 不阻擋安裝，但必須顯眼
            'label'   => '機密檔外洩',
            'detail'  => sprintf('發現 %d 個機密檔在 web root 內（見下方指令）', count($found)),
            'warning' => true,
            'manual_commands' => implode("\n", $cmds),
        ];
    }

    /**
     * @return list<string> 相對於 web root 的檔名（僅掃描根層，不遞迴進 vendor/uploads 等）
     */
    public static function scanSecretFiles(string $webRoot): array
    {
        // .env / .env.example 由 InstallerMoveJournal 搬移，不在此重複告警。
        $handledByMover = ['.env', '.env.example'];
        $patterns = [
            '/\.env$/i',                    // deploy.env、prod.env…
            // 🔴 .env.production / .env.local 這一族：搬移器只處理 .env 與 .env.example，
            // 而 nginx 沒有內建的 dotfile 拒絕規則（Apache 端才由 ^\. 擋住）。
            '/^\.env\./i',
            '/^credentials.*\.json$/i',
            '/^secrets?.*\.json$/i',
            '/^service-account.*\.json$/i',
            '/^auth\.json$/i',
            '/\.(pem|key|ppk|p12|pfx)$/i',
            '/^id_rsa/i',
            '/\.(sql|bak|orig|log|sh)$/i',  // 資料庫傾印／備份殘留／部署腳本
            '/\.sql\.gz$/i',
        ];

        $found = [];
        foreach ((array) @scandir($webRoot) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..'
                || in_array($name, $handledByMover, true)
                || !is_file($webRoot . '/' . $name)) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name) === 1) {
                    $found[] = $name;
                    break;
                }
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * uploads/ 執行防護。
     *
     * uploads/ 必須留在 web root 內（圖片由 web server 直接服務），所以「能不能執行
     * 落在裡面的 .php」是本系統少數無法靠搬移解決的風險。nginx 不讀 .htaccess，
     * 而一般虛擬主機用戶碰不到 vhost —— 故此處先嘗試寫入 .user.ini，再誠實回報結果。
     *
     * 不阻擋安裝（pass=true）：上傳端本身有 MIME 白名單 + getimagesize 驗證，
     * 這一項是縱深防禦。但無法生效時必須以 warning 讓管理者看見，不能靜默。
     */
    private function checkUploadsGuard(): array
    {
        $webRoot    = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $uploadsDir = \YangSheep\CRM\Core\UploadPath::absolute($webRoot);

        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            return [
                'pass'    => true,
                'label'   => 'uploads 防護',
                'detail'  => 'uploads/ 尚未建立，將於首次上傳時自動建立並套用防護',
                'warning' => true,
            ];
        }

        UploadGuard::ensure($uploadsDir);
        $state = UploadGuard::describe($uploadsDir);

        // summary 以 🔴 開頭代表沒有任何一層在這台主機上生效。
        $degraded = str_starts_with($state['summary'], '🔴');

        return [
            'pass'    => true,
            'label'   => 'uploads 防護',
            'detail'  => $state['summary'],
            'warning' => $degraded,
        ];
    }

    /**
     * 是否全部通過。
     *
     * $results 傳入已算好的結果以避免重複執行 —— check() 具副作用
     * （建立 uploads/、寫入 .user.ini），呼叫端通常已經跑過一次了。
     * 不做內部 memoize：那會讓「同一個 instance 重跑」變成靜默無效，
     * 測試要驗狀態變化時會踩到。
     */
    public function allPassed(?array $results = null): bool
    {
        foreach ($results ?? $this->check() as $item) {
            if (!$item['pass']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 檢測並處理目錄結構
     * 扁平結構 → 嘗試自動搬移到上一層
     */
    private function checkDirectoryStructure(): array
    {
        // 已是安全結構（src/ 在 web root 外）
        if (!IS_FLAT_STRUCTURE) {
            return [
                'pass'   => true,
                'label'  => '目錄結構',
                'detail' => '安全（應用程式檔案在 web root 外）',
            ];
        }

        // 扁平結構：僅偵測，不自動搬移（搬移由 POST 觸發）
        $parentDir = dirname(WEB_ROOT);
        if ($parentDir === WEB_ROOT) {
            return [
                'pass'   => true,
                'label'  => '目錄結構',
                'detail' => '扁平模式（根目錄環境，已啟用 PHP 層安全防護）',
            ];
        }

        $canMove = is_writable($parentDir) && is_writable(WEB_ROOT);

        return [
            'pass'    => true, // 不阻擋安裝
            'label'   => '目錄結構',
            'detail'  => $canMove
                ? '扁平模式（可搬移至安全位置）'
                : '扁平模式（網站根目錄或上層目錄不可寫入，請手動搬移）',
            'warning'         => true,
            'can_move'        => $canMove,
            'manual_commands' => $canMove ? '' : $this->getManualMoveCommands(),
        ];
    }

    /**
     * 執行搬移（僅由 POST 端點呼叫，不在 GET check() 中觸發）
     */
    public function performMove(): true|string
    {
        return InstallerMoveJournal::move(WEB_ROOT, $this->movePath);
    }

    public static function recoverInterruptedMove(): true|string
    {
        return InstallerMoveJournal::recover(WEB_ROOT);
    }

    /**
     * 產生手動搬移的 SSH 指令
     */
    public function getManualMoveCommands(): string
    {
        $webRoot = WEB_ROOT;
        $parentDir = dirname(WEB_ROOT);

        $cmds = [];
        foreach (self::PRIVATE_DIRS as $dir) {
            if (is_dir(WEB_ROOT . '/' . $dir)) {
                $cmds[] = "mv {$webRoot}/{$dir} {$parentDir}/{$dir}";
            }
        }
        foreach (self::PRIVATE_FILES as $file) {
            if (file_exists(WEB_ROOT . '/' . $file)) {
                $cmds[] = "mv {$webRoot}/{$file} {$parentDir}/{$file}";
            }
        }

        return implode("\n", $cmds);
    }

    /**
     * 檢查 .env 寫入能力
     */
    private function checkEnvWritable(): array
    {
        $parentDir = dirname(WEB_ROOT);

        // 優先檢查 web root 上一層（最安全位置）
        if ($parentDir !== WEB_ROOT && is_dir($parentDir) && is_writable($parentDir)) {
            return [
                'pass'   => true,
                'label'  => '.env 寫入',
                'detail' => '可寫入（安全位置：web root 外）',
            ];
        }

        // 退而求其次：BASE_PATH
        if (is_writable(BASE_PATH)) {
            return [
                'pass'   => true,
                'label'  => '.env 寫入',
                'detail' => '可寫入（安裝後建議搬移至 web root 外）',
            ];
        }

        return [
            'pass'   => false,
            'label'  => '.env 寫入',
            'detail' => '無法寫入，請檢查目錄權限',
        ];
    }

    private function checkPhpVersion(): array
    {
        $current = PHP_VERSION;
        $pass = version_compare($current, '8.5.0', '>=');
        return [
            'pass'   => $pass,
            'label'  => 'PHP 版本',
            'detail' => $pass ? "PHP {$current}" : "需要 PHP 8.5+，目前為 {$current}",
        ];
    }

    private function checkExtension(string $ext, string $label): array
    {
        $pass = extension_loaded($ext);
        return [
            'pass'   => $pass,
            'label'  => $label,
            'detail' => $pass ? '已啟用' : '未啟用，請安裝並啟用 ' . $ext,
        ];
    }

    private function checkWritable(string $path, string $label): array
    {
        $pass = is_dir($path) && is_writable($path);
        return [
            'pass'   => $pass,
            'label'  => $label,
            'detail' => $pass ? '可寫入' : '無法寫入，請檢查目錄權限',
        ];
    }

    private function checkAutoloader(): array
    {
        $autoloaderPath = BASE_PATH . '/src/Core/Autoloader.php';
        $pass = file_exists($autoloaderPath) && class_exists('Autoloader');
        return [
            'pass'   => $pass,
            'label'  => 'Autoloader',
            'detail' => $pass ? '已就緒（內建）' : '缺少 Autoloader.php',
        ];
    }
}
