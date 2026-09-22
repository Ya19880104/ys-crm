<?php

declare(strict_types=1);

/**
 * CLI 資料庫遷移執行器（部署後於主機端執行）。
 *
 * 用法（於 app 根目錄，src/ 同層）：php migrate.php
 * 讀取同層或上一層的 .env，執行所有尚未套用的 migration。
 * 置於 web root 之外、僅供 CLI；非 CLI 直接拒絕。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('BASE_PATH', __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config');

require BASE_PATH . '/src/Core/Autoloader.php';
Autoloader::register();
Autoloader::addNamespace('YangSheep\\CRM\\', BASE_PATH . '/src/');
Autoloader::addNamespace('YangSheep\\CRM\\Database\\', BASE_PATH . '/database/');

// 載入 .env（同層優先，否則上一層 — 對應 Structure B 部署）
$envDir = file_exists(BASE_PATH . '/.env') ? BASE_PATH : dirname(BASE_PATH);
\YangSheep\CRM\Core\DotEnv::load($envDir);

$cfg = require CONFIG_PATH . '/database.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
\YangSheep\CRM\Core\Database::syncPdoTimeZone($pdo);

$runner  = new \YangSheep\CRM\Install\MigrationRunner($pdo, $cfg['prefix']);
$results = $runner->runAll();

$ran = 0;
foreach ($results as $name => $ok) {
    echo ($ok ? '  OK   ' : ' FAIL  ') . $name . PHP_EOL;
    if ($ok) {
        $ran++;
    }
}
echo "Migration 完成（{$ran} 個成功）。\n";
