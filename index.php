<?php

declare(strict_types=1);

/**
 * YS CRM — 單一入口點
 *
 * 支援兩種目錄結構（自動偵測）：
 *
 * 結構 A（剛上傳，所有檔案在同一目錄）：
 *   public_html/
 *   ├── index.php, migrate.php, composer.*, cli/, src/, config/, views/, routes/, database/, storage/
 *   └── assets/, uploads/
 *
 * 結構 B（安裝後搬移，或手動部署）：
 *   /home/
 *   ├── migrate.php, composer.*, cli/, src/, config/, views/, routes/, database/, storage/, .env
 *   └── public_html/
 *       ├── index.php, assets/, uploads/
 */

// 0. Shared native recovery must run before layout detection or autoloading.
// The helper stays beside this entrypoint in both supported layouts.
require_once __DIR__ . '/installer-move.php';
if (YangSheep\CRM\Install\InstallerMoveJournal::startup(__DIR__) !== true) {
    http_response_code(503);
    exit('Service Unavailable');
}

// 1. 偵測應用程式根目錄（src/ 在哪裡，BASE_PATH 就指向哪裡）
if (is_dir(__DIR__ . '/src')) {
    // 結構 A：所有檔案在同一目錄（剛上傳，或 Apache 環境）
    define('BASE_PATH', __DIR__);
} elseif (is_dir(dirname(__DIR__) . '/src')) {
    // 結構 B：src/ 在上一層（已搬移，最安全）
    define('BASE_PATH', dirname(__DIR__));
} else {
    http_response_code(500);
    exit('無法找到應用程式檔案（src/ 目錄）。請確認檔案已正確上傳。');
}

define('WEB_ROOT', __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config');
define('VIEWS_PATH', BASE_PATH . '/views');
define('ROUTES_PATH', BASE_PATH . '/routes');

// 是否為扁平結構（敏感檔案仍在 web root 內）
define('IS_FLAT_STRUCTURE', BASE_PATH === WEB_ROOT);

// 2. 載入內建 Autoloader（取代 Composer）
require BASE_PATH . '/src/Core/Autoloader.php';
Autoloader::register();
Autoloader::addNamespace('YangSheep\CRM\\', BASE_PATH . '/src/');
Autoloader::addNamespace('YangSheep\CRM\\Database\\', BASE_PATH . '/database/');

// 3. 偵測 .env 路徑（優先上一層目錄，更安全）
$envSearchPaths = [
    dirname(WEB_ROOT),  // public_html 上一層（最安全）
    BASE_PATH,          // 應用程式根目錄
];
if (WEB_ROOT !== BASE_PATH) {
    $envSearchPaths[] = WEB_ROOT;
}
$envPath = BASE_PATH; // 預設 fallback
foreach ($envSearchPaths as $candidate) {
    $candidateEnv = $candidate . '/.env';
    try {
        // Windows 不能以 rename 直接覆寫既有檔案。若上次 process 在 backup 與
        // promote 之間終止，必須先完成/復原固定的 pending replacement，否則會
        // 把既有站台誤判成 fresh install。
        YangSheep\CRM\Install\EnvWriter::recoverInterruptedReplace($candidateEnv);
    } catch (\Throwable) {
        http_response_code(503);
        exit('Service Unavailable');
    }
    if (file_exists($candidateEnv)) {
        $envPath = $candidate;
        break;
    }
}
define('ENV_PATH', $envPath);

// 4. 載入 .env（若存在）
YangSheep\CRM\Core\DotEnv::load(ENV_PATH);

// 5. 載入 View 的 e() 全域函式
require_once BASE_PATH . '/src/Core/View.php';

// 6. 錯誤處理
$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// 6.5 強制 HTTPS
//
// 【為何應用層也要做】稽核（2026-08-16）實測測試站以 http:// 開啟 /login 回 200，
// 登入表單可在明文連線下送出帳密。HSTS 只保護「已經安全造訪過一次」的瀏覽器，
// 對首次連線無效。
//
// 理想的解法是在 Nginx 層做 301（見 docs/GO-LIVE.md，需面板操作）；這裡是應用層保險：
// 使用者最多在明文下取得一個 301 回應，**表單與帳密不會在明文連線上出現**。
//
// 🔴 scheme 與 host 一律經 RequestOrigin 判定。原本這裡直接相信
// X-Forwarded-Proto，實測 `curl -H 'X-Forwarded-Proto: https' http://…/login`
// 會拿到完整的登入表單（200、12KB）—— 一個 header 就把整道閘門繞掉了。
// Host 也只做了語法檢查而非白名單，任何語法合法的網域都能被拼進 Location。
//
// 以 FORCE_HTTPS 控制，未設定時只在 production 生效——本機開發與測試環境通常沒有 TLS，
// 若無條件啟用會直接把開發環境導到一個連不上的位址。
$forceHttpsSetting = $_ENV['FORCE_HTTPS'] ?? null;
$forceHttps = $forceHttpsSetting !== null
    ? ($forceHttpsSetting === 'true' || $forceHttpsSetting === '1')
    : (($_ENV['APP_ENV'] ?? 'production') === 'production');
$environmentExists = file_exists(ENV_PATH . '/.env');

// Host allowlist 對 HTTP 與 HTTPS 都是同一道入站閘門；不能只在 redirect 分支檢查。
// 完全尚未建立 .env 的 fresh bootstrap，也只能採部署者在 PHP process environment
// 明示的 INSTALL_HOST；HTTP_HOST 與 SERVER_NAME 常來自同一個 request，不能互相背書。
if (PHP_SAPI !== 'cli'
    && !YangSheep\CRM\Core\RequestOrigin::requestHostAllowed(!$environmentExists)) {
    http_response_code(400);
    exit('Bad Request');
}

if ($forceHttps && PHP_SAPI !== 'cli' && !YangSheep\CRM\Core\RequestOrigin::isSecure()) {
    $canonicalHost = YangSheep\CRM\Core\RequestOrigin::redirectHost(!$environmentExists);

    if ($canonicalHost !== '') {
        header('Location: https://' . $canonicalHost . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    // 連伺服器端都給不出可信的主機名稱 → 不猜、也不拿請求帶進來的 Host 拼網址。
    // 這裡若「放行」就等於在明文連線上輸出完整頁面，正是要防的事。
    http_response_code(400);
    exit('Bad Request');
}

// 7. 路徑安全防護（扁平結構下阻擋敏感路徑，Nginx 不吃 .htaccess）
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
// Nginx rewrite 可能將 REQUEST_URI 設為 /index.php 或 /index.php/path
if (str_starts_with($requestPath, '/index.php')) {
    $requestPath = substr($requestPath, strlen('/index.php')) ?: '/';
}

if (IS_FLAT_STRUCTURE) {
    $blockedPrefixes = ['/src/', '/config/', '/views/', '/routes/', '/database/', '/storage/', '/vendor/'];
    foreach ($blockedPrefixes as $blocked) {
        if (str_starts_with($requestPath, $blocked)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}
// 任何結構都阻擋 .env 直接存取
if ($requestPath === '/.env' || $requestPath === '/.env.example') {
    http_response_code(403);
    exit('Forbidden');
}

// 8. 靜態檔案直接回傳（robots.txt, favicon 等）
$staticFiles = ['/robots.txt', '/favicon.ico', '/sitemap.xml'];
if (in_array($requestPath, $staticFiles, true)) {
    $filePath = WEB_ROOT . $requestPath;
    if (file_exists($filePath)) {
        $mimeMap = ['.txt' => 'text/plain', '.ico' => 'image/x-icon', '.xml' => 'application/xml'];
        $ext = strrchr($requestPath, '.');
        header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
        readfile($filePath);
        exit;
    }
}

// 9. 安裝狀態自動偵測
$isAsset = str_starts_with($requestPath, '/assets/') || str_starts_with($requestPath, '/uploads/');
$isInstallRoute = str_starts_with($requestPath, '/install');
$installState = null;

if (!$isAsset) {
    $installationMarkers = new YangSheep\CRM\Install\InstallationMarkers(STORAGE_PATH);
    $installLockExists = $installationMarkers->completeExists();
    $installPendingExists = $installationMarkers->pendingExists();
    $installPendingOwned = false;

    // pending 狀態只能由建立它的 native/file session 繼續。必須在 classify 前讀取
    // ownership proof；App::run() 稍後再次 start() 會沿用同一個已啟動 session。
    if (!$installLockExists && $installPendingExists) {
        YangSheep\CRM\Core\Session::start(YangSheep\CRM\Install\InstallationState::Uninstalled);
        $installPendingOwned = $installationMarkers->ownsPending(
            (string) YangSheep\CRM\Core\Session::get(
                YangSheep\CRM\Install\InstallationMarkers::SESSION_KEY,
                ''
            )
        );
    }

    $installState = YangSheep\CRM\Install\InstallationGate::classify(
        $environmentExists,
        $installLockExists,
        $installPendingExists,
        $installPendingOwned,
        static function (): bool {
            $dbConfig = require CONFIG_PATH . '/database.php';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'], $dbConfig['port'],
                $dbConfig['database'], $dbConfig['charset']
            );
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            $prefix = $dbConfig['prefix'] ?? 'ys_crm_';
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$prefix . 'users']);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$prefix}users`");
            return (int) $stmt->fetchColumn() > 0;
        }
    );

    if ($installState === YangSheep\CRM\Install\InstallationState::Indeterminate) {
        if ($requestPath === '/install/recover' && $installationMarkers->recoveryPending()) {
            YangSheep\CRM\Install\InstallationResume::respond($installationMarkers);
        }
        http_response_code(503);
        exit('Service Unavailable');
    }

    if (YangSheep\CRM\Install\InstallationGate::mayServeInstaller($installState) && !$isInstallRoute) {
        header('Location: /install');
        exit;
    }

    // 相容既有入口判讀：indeterminate 已在上方 503 結束，故這裡的 false 只代表 Installed。
    $needsInstall = $installState === YangSheep\CRM\Install\InstallationState::Uninstalled;

    // 🔴 封鎖 installer 的條件是「已安裝」，不是「install.lock 還在」。
    //
    // 原本寫成 `!$needsInstall && $installLockExists && $isInstallRoute`：
    // lock 一旦遺失，上面那段 DB 偵測會正確判定「已安裝」（$needsInstall = false），
    // 於是不導去 /install —— 但這裡也因為 lock 不在而不擋，結果是**匿名使用者
    // 可以直接進入安裝流程**，而 EnvWriter 有覆寫既有 .env 的能力。
    //
    // install.lock 在 .gitignore 內，搬機、重建 storage、清暫存都可能讓它消失；
    // 「已安裝」是不可逆的事實，不能託付給一個不隨部署保存的檔案。
    if (!$needsInstall) {
        // 順手把遺失的 lock 補回來（自癒）。寫不進去也不影響上面的判定，
        // 因為封鎖已經不依賴這個檔案了。
        if (!$installLockExists && !$installPendingExists) {
            try {
                $installationMarkers->restoreComplete();
            } catch (Throwable $e) {
                error_log('YS CRM: failed to restore install.lock: ' . $e->getMessage());
            }
        }

        if ($isInstallRoute) {
            // 登入路由是 /login，不是 /admin/login（後者不存在，會走到 404）。
            header('Location: /login');
            exit;
        }
    }
}

// 9. 啟動應用
$app = new YangSheep\CRM\Core\App($installState);
$app->run();
