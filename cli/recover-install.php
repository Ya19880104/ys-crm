<?php

declare(strict_types=1);

/**
 * installer session/cookie 遺失後的本機 recovery。
 *
 * 用法：
 *   php cli/recover-install.php reset-empty --confirm
 *   php cli/recover-install.php promote-complete --confirm
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config');
define('VIEWS_PATH', BASE_PATH . '/views');
define('ROUTES_PATH', BASE_PATH . '/routes');
define('ENV_PATH', file_exists(BASE_PATH . '/.env') ? BASE_PATH : dirname(BASE_PATH));

require BASE_PATH . '/src/Core/Autoloader.php';
\Autoloader::register();
\Autoloader::addNamespace('YangSheep\CRM\\', BASE_PATH . '/src/');

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\DotEnv;
use YangSheep\CRM\Install\InstallationMarkers;
use YangSheep\CRM\Install\InstallationRecovery;

$command = (string) ($argv[1] ?? '');
$confirmed = in_array('--confirm', $argv, true);
if (!in_array($command, ['reset-empty', 'promote-complete'], true) || !$confirmed) {
    fwrite(STDERR, "用法：php cli/recover-install.php reset-empty --confirm\n");
    fwrite(STDERR, "      php cli/recover-install.php promote-complete --confirm\n");
    exit(2);
}

if (!file_exists(ENV_PATH . '/.env')) {
    fwrite(STDERR, "Recovery 拒絕：找不到 .env，請先由部署者還原環境設定。\n");
    exit(1);
}

try {
    // Flat layout is unambiguous. Split deployments must name the actual document root.
    $webRoot = is_file(BASE_PATH . '/index.php') ? BASE_PATH : '';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--web-root=')) { $webRoot = substr($argument, 11); }
    }
    $webRoot = $webRoot !== '' ? realpath($webRoot) : false;
    if ($webRoot === false || !is_file($webRoot . '/index.php')
        || !is_file($webRoot . '/installer-move.php')
        || (realpath($webRoot) !== realpath(BASE_PATH) && realpath(dirname($webRoot)) !== realpath(BASE_PATH))) {
        throw new RuntimeException('請指定正確的 --web-root=實際公開目錄，內含 index.php 與 installer-move.php');
    }
    require_once $webRoot . '/installer-move.php';
    $maintenanceResult = \YangSheep\CRM\Install\InstallerMoveJournal::maintenance($webRoot, static function () use ($command): void {
    DotEnv::load(ENV_PATH);
    $database = Database::getInstance();
    $prefix = (string) ($_ENV['DB_PREFIX'] ?? 'ys_crm_');
    $recovery = new InstallationRecovery(
        $database->getPdo(),
        $prefix,
        new InstallationMarkers(STORAGE_PATH)
    );
    $result = $recovery->recover($command === 'promote-complete');

    if ($result === 'reset') {
        fwrite(STDOUT, "Recovery 完成：DB 尚無使用者，舊 ownership 已撤銷，.env 保留。\n");
        fwrite(STDOUT, "請於 15 分鐘內開啟原部署網域 /install/recover，手動輸入以下一次性復原碼。\n");
        fwrite(STDOUT, "不要將復原碼放入網址、截圖或交接紀錄：\n" . $recovery->recoveryCode() . "\n");
    } else {
        fwrite(STDOUT, "Recovery 完成：完整 schema 與 active super-admin 已驗證，installer 已封閉。\n");
        fwrite(STDOUT, "請登入後檢查站台設定；Step 6 的 site/Turnstile 設定可能尚未保存。\n");
    }
    });
    if ($maintenanceResult !== true) { throw new RuntimeException($maintenanceResult); }
} catch (Throwable $e) {
    fwrite(STDERR, "Recovery 拒絕：{$e->getMessage()}\n");
    exit(1);
}
