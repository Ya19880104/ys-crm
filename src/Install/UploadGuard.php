<?php
declare(strict_types=1);

namespace YangSheep\CRM\Install;

/**
 * uploads/ 執行防護 —— 針對「沒有 server 設定權限」的主機。
 *
 * 【問題】uploads/ 必須留在 web root 內（圖片由 web server 直接服務），
 * 但一旦有任何寫檔路徑疏漏而落入 .php，就直接是 RCE。
 * uploads/.htaccess 只在 Apache／LiteSpeed 生效；**nginx 完全不讀 .htaccess**，
 * 而一般虛擬主機用戶碰不到 vhost —— 那正是最需要保護的族群。
 *
 * 【三層，依主機能力自動生效】
 *   1. uploads/.htaccess     Apache／LiteSpeed。每個 root 由 canonical template 產生。
 *   2. uploads/.user.ini     nginx + PHP-FPM／任何 CGI/FastCGI SAPI。**本類別產生**。
 *                            auto_prepend_file 是 PHP_INI_PERDIR，可在 .user.ini 設定；
 *                            PHP 會從腳本所在目錄往上掃描，故 uploads/ 之下遞迴覆蓋。
 *   3. 上傳端驗證             finfo 真實 MIME 白名單 + getimagesize() + 隨機檔名 +
 *                            副檔名由 MIME 反查（不信任使用者輸入）。已存在於 UserService／
 *                            SettingController，是目前實際擋住植入的那一層。
 *
 * 【誠實界線 —— 這些是本機制擋不到的】
 *   - .user.ini 只在 PHP 以 CGI/FastCGI 執行時有效（mod_php 環境改由 .htaccess 負責）。
 *   - 主機若改掉 user_ini.filename 或設 user_ini.cache_ttl 很長，生效會延遲或失效。
 *   - PHP 8.5 的 auto_prepend_file 檔案開不起來時會 Warning + Fatal，中止原腳本。
 *     故 ensure() 設計成可重複呼叫、會自我修復，describe() 會回報樁檔是否還在。
 *   - 本機制不阻止 .php 被當成**純文字**送出（原始碼外洩），只阻止被執行。
 */
final class UploadGuard
{
    public const USER_INI = '.user.ini';
    private const MARKER  = '; YS CRM uploads guard';
    private const APACHE_START = '# BEGIN YS CRM uploads guard';
    private const APACHE_END = '# END YS CRM uploads guard';
    // Exact pre-managed vendor policy, LF-normalized only. Unknown/custom
    // variants are never claimed by the upgrader (fixture preserves provenance).
    private const LEGACY_APACHE_SHA256 = 'bf55488f31e3ecbfe165ef33d714dc9c300fa799e0841b5fe40ce04e5cbc3511';

    /**
     * 主機實際會掃描的 per-directory ini 檔名。
     *
     * 🔴 這不是常數：`user_ini.filename` 可被主機改名，改成空字串則**整個機制停用**。
     * 硬寫 '.user.ini' 會讓我們在那些主機上寫出一個永遠不被讀取的檔案，
     * 然後還回報「已受保護」—— 那比沒有保護更糟，因為它會讓人不再追查。
     *
     * @return string|null null＝主機已停用 per-directory ini，本層不可能生效
     */
    public static function iniFilename(): ?string
    {
        $name = trim((string) ini_get('user_ini.filename'));
        return $name === '' ? null : $name;
    }

    /**
     * 樁檔路徑（config/ 會被 installer-move 搬到 web root 外，攻擊者改不到）。
     */
    public static function stubPath(): string
    {
        return (defined('BASE_PATH') ? BASE_PATH : \dirname(__DIR__, 2)) . '/config/uploads-deny.php';
    }

    /**
     * 確保 uploads/ 具備不依賴 server 設定的執行防護。
     *
     * 可重複呼叫（冪等）；上傳端必須檢查 false 並中止寫入。
     *
     * @return bool Apache 與 per-directory ini 設定是否皆已備妥（HTTP 生效另驗）
     */
    public static function ensure(string $uploadsDir, ?string $stubPath = null): bool
    {
        $stub    = $stubPath ?? self::stubPath();
        $iniName = self::iniFilename();
        if ($iniName === null || !is_dir($uploadsDir) || !is_file($stub)) {
            return false; // 主機停用 per-directory ini：寫了也不會被讀
        }

        $expected = self::userIniContent($stub);
        if ($expected === null) {
            return false; // 路徑含引號等無法安全寫入 ini 的字元
        }

        if (!self::ensureApache($uploadsDir)) return false;

        // 寫進主機實際會掃描的檔名，而不是硬寫 .user.ini。
        $target = $uploadsDir . '/' . $iniName;
        $current = is_file($target) ? @file_get_contents($target) : false;
        if ($current === $expected) {
            return true;
        }

        // 只覆寫本機制自己產生的檔案。主機商或使用者另外放的 .user.ini 不動，
        // 避免把對方的設定洗掉（describe() 會把這種情況回報為未受保護）。
        if ($current !== false && !str_contains($current, self::MARKER)) {
            return false;
        }

        return @file_put_contents($target, $expected, LOCK_EX) !== false;
    }

    private static function apacheTemplate(): string|false
    {
        return @file_get_contents(dirname(self::stubPath()) . '/uploads-apache.htaccess');
    }

    private static function ensureApache(string $uploadsDir): bool
    {
        $policy = self::apacheTemplate();
        if ($policy === false || trim($policy) === '') return false;
        $target = $uploadsDir . '/.htaccess';
        if (is_link($target) || (file_exists($target) && !is_file($target))) return false;
        $current = is_file($target) ? @file_get_contents($target) : '';
        if ($current === false) return false;
        $expected = self::apacheContent($current, $policy);
        if ($expected === null) return false;
        return $current === $expected || @file_put_contents($target, $expected, LOCK_EX) === strlen($expected);
    }

    /** Null means external rules cannot safely be combined with our policy. */
    private static function apacheContent(string $current, string $policy): ?string
    {
        // The shipped legacy guard is generated from the same template.
        if ($current === $policy) return $policy;
        if (hash('sha256', str_replace("\r\n", "\n", $current)) === self::LEGACY_APACHE_SHA256) {
            $current = '';
        }
        $outside = $current;
        if (str_contains($current, self::APACHE_START) || str_contains($current, self::APACHE_END)) {
            if (substr_count($current, self::APACHE_START) !== 1 || substr_count($current, self::APACHE_END) !== 1) return null;
            $outside = preg_replace('/^' . preg_quote(self::APACHE_START, '/') . '\\R.*?^' . preg_quote(self::APACHE_END, '/') . '(?:\\R|$)/ms', '', $current, 1, $count);
            if ($outside === null || $count !== 1) return null;
        }
        // Unknown directives can override auth/handlers. Preserve them and fail
        // provisioning; only comments, blank lines and these nonconflicting
        // Options are safe to compose without interpreting Apache configuration.
        foreach (preg_split('/\\R/', $outside) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')
                && preg_match('/^Options\\s+-(?:Indexes|MultiViews)(?:\\s+-(?:Indexes|MultiViews))*$/iD', $line) !== 1) return null;
        }
        return $outside . ($outside !== '' && !str_ends_with($outside, "\n") ? "\n" : '')
            . self::APACHE_START . "\n" . rtrim($policy) . "\n" . self::APACHE_END . "\n";
    }

    /**
     * 回報設定是否備妥；HTTP 與實際 SAPI 生效仍須獨立驗證。
     *
     * @return array{htaccess: bool, user_ini: bool, stub: bool, cgi_sapi: bool, summary: string}
     */
    public static function describe(string $uploadsDir, ?string $stubPath = null): array
    {
        $stub     = $stubPath ?? self::stubPath();
        $apache = @file_get_contents($uploadsDir . '/.htaccess');
        $policy = self::apacheTemplate();
        $htaccess = $apache !== false && $policy !== false
            && !is_link($uploadsDir . '/.htaccess') && self::apacheContent($apache, $policy) === $apache;
        $stubOk   = is_file($stub);
        $iniName  = self::iniFilename();

        // 主機停用 per-directory ini 時，檔案存在與否都不重要 —— 它不會被讀。
        $userIni = false;
        if ($iniName !== null) {
            $target  = $uploadsDir . '/' . $iniName;
            $content = is_file($target) ? @file_get_contents($target) : false;

            // 🔴 必須驗證檔案裡**實際記錄的路徑**，不能只看 MARKER 存在。
            // 情境：ensure() 在扁平結構下寫入路徑 → 安裝精靈把 config/ 搬到 web root 外
            // → stubPath() 改變，但若後續 ensure() 寫不進去（權限／唯讀），檔案裡留的
            // 仍是舊路徑。PHP 8.5 會 Warning + Fatal；這是失效的設定，不是可用防護。
            if ($content !== false && str_contains($content, self::MARKER)
                && preg_match('/auto_prepend_file\s*=\s*"([^"]+)"/', $content, $m) === 1) {
                $recorded = $m[1];
                $userIni  = is_file($recorded)
                    && (realpath($recorded) ?: $recorded) === (realpath($stub) ?: $stub);
            }
        }

        // mod_php 不讀 .user.ini；其餘（fpm-fcgi / cgi-fcgi / cgi / litespeed）會讀。
        $cgiSapi = !str_starts_with(PHP_SAPI, 'apache2handler');

        return [
            'htaccess'  => $htaccess,
            'user_ini'  => $userIni,
            'stub'      => $stubOk,
            'cgi_sapi'  => $cgiSapi,
            'ini_name'  => $iniName,
            'cache_ttl' => (int) ini_get('user_ini.cache_ttl'),
            'summary'   => self::summarize($htaccess, $userIni, $stubOk, $cgiSapi, $iniName),
        ];
    }

    private static function summarize(
        bool $htaccess,
        bool $userIni,
        bool $stub,
        bool $cgiSapi,
        ?string $iniName
    ): string {
        // 有效性取決於 SAPI：mod_php 靠 .htaccess，FastCGI 靠 per-directory ini。
        $effective = $cgiSapi ? ($userIni && $stub) : $htaccess;

        if ($effective) {
            if (!$cgiSapi) {
                return '.htaccess 設定已備妥（Apache mod_php；實際 HTTP 阻擋仍須驗證）';
            }
            $ttl  = (int) ini_get('user_ini.cache_ttl');
            $note = $ttl > 0 ? sprintf('，ini 快取更新可能需 %d 秒', $ttl) : '';
            return $iniName . ' + auto_prepend_file 設定已備妥（實際 HTTP 與 SAPI 仍須驗證）' . $note;
        }

        // 🔴 這一支是最重要的：主機停用 per-directory ini 時，我們寫出來的檔案
        // 永遠不會被讀取。若這裡回報成受保護，安裝頁會亮綠燈而防護其實是空的。
        if ($cgiSapi && $iniName === null) {
            return '🔴 本主機已停用 per-directory ini（user_ini.filename 為空），'
                . '.user.ini 防護不可能生效；且不讀 .htaccess —— '
                . '請於 server 設定阻擋 uploads 下的 .php，或改用支援 .htaccess 的主機';
        }
        if ($cgiSapi && $userIni && !$stub) {
            return '🔴 ' . $iniName . ' 指向的封鎖樁不存在 —— PHP 8.5 會 Warning + Fatal，請還原 config/uploads-deny.php';
        }
        return $cgiSapi
            ? '🔴 uploads/ 無執行防護：本主機不讀 .htaccess 且未能寫入 ' . ($iniName ?? 'per-directory ini')
                . '，請改於 server 設定阻擋 uploads 下的 .php'
            : '🔴 uploads/.htaccess 遺失';
    }

    /** @return string|null null＝樁檔路徑無法安全寫進 ini */
    private static function userIniContent(string $stub): ?string
    {
        $real = realpath($stub) ?: $stub;
        // ini 的雙引號字串裡沒有跳脫機制；含引號的路徑一律放棄而不是寫出壞設定。
        if (str_contains($real, '"')) {
            return null;
        }
        // Windows 反斜線在 ini 值中不可靠，統一用正斜線（PHP 於 Windows 亦接受）。
        $real = str_replace('\\', '/', $real);

        return self::MARKER . " —— 請勿手動編輯，內容由 UploadGuard::ensure() 維護。\n"
            . "; nginx 不讀 .htaccess，本檔是沒有 server 設定權限時唯一的執行防護。\n"
            . "; 任何位於 uploads/ 之下的 .php 都會先執行下列樁檔並中止。\n"
            . 'auto_prepend_file = "' . $real . "\"\n";
    }
}
