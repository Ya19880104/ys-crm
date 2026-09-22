<?php

declare(strict_types=1);

/**
 * YS CRM 統一排程入口（CLI）— 對應架構設計 §7.10、§9 Phase 11。
 *
 * 用法（於 app 根目錄，src/ 同層）：
 *   php cli/cron.php tick         **建議：主機只掛這一條，每 5 分鐘一次**
 *   php cli/cron.php run          執行全部子命令
 *   php cli/cron.php maintenance  僅高頻維護（電子發票 + 寄信佇列）
 *   php cli/cron.php expiry       僅到期掃描（翻 status + 到期前提醒）
 *   php cli/cron.php recurring    僅週期帳單（產生 + 自動扣款/待人工）
 *   php cli/cron.php reminders    僅提醒（報價待簽 / 款項待收）
 *   php cli/cron.php invoice      僅電子發票維護（回收殭屍 claim + 重試失敗發票）
 *   php cli/cron.php mail         僅處理寄信佇列（claim → 寄送 → 標記）
 *   php cli/cron.php run 2026-06-30   指定「今日」（測試/補跑用，YYYY-MM-DD）
 *
 * 全部子命令皆冪等、可重複執行不出錯，並輸出每步處理筆數。
 *
 * 主機面板 cron 建議設定（Cron Jobs）：**只要一條**
 *   每 5 分鐘 → php /home/<user>/ys-crm/cli/cron.php tick
 *
 * tick 每次被叫到時：一定處理電子發票維護與寄信佇列；若「今天過了設定的執行時刻
 * 卻還沒成功跑過每日工作」，則改跑一次完整批次（其本身各包含 invoice/mail 一次）。因此：
 *   - 不會有兩條排程在同一分鐘搶鎖（原本 08:00 那一格必撞，當天的帳可能整天沒出）
 *   - 每日的時刻由本站設定與本站時區決定，不受主機時區影響
 *    （這台主機是 UTC，crontab 寫 `0 8` 其實是台北時間 16:00）
 *   - 錯過的那次會在下一個 tick 補上，最多晚 5 分鐘，而不是晚一天
 *
 *   （每日時刻在後台「排程設定」頁可改；完整說明與 URL 觸發替代方案見 cli/README-cron.md。）
 *
 * 置於 web root 之外、僅供 CLI；非 CLI 直接拒絕。
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

require BASE_PATH . '/src/Core/Autoloader.php';
\Autoloader::register();
\Autoloader::addNamespace('YangSheep\\CRM\\', BASE_PATH . '/src/');
\Autoloader::addNamespace('YangSheep\\CRM\\Database\\', BASE_PATH . '/database/');

// 載入 .env（同層優先，否則上一層 — 對應 Structure A/B 部署）。
$envDir = file_exists(BASE_PATH . '/.env') ? BASE_PATH : dirname(BASE_PATH);
\YangSheep\CRM\Core\DotEnv::load($envDir);

// 設定時區（與 App 啟動一致）。
$appCfg = require CONFIG_PATH . '/app.php';
date_default_timezone_set($appCfg['timezone'] ?? 'Asia/Taipei');

// 初始化 DB singleton（Repository/Service 透過 Database::getInstance() 取用）。
\YangSheep\CRM\Core\Database::getInstance();

// ── 解析子命令與選用的「今日」參數 ──
$command = $argv[1] ?? 'run';
$today   = isset($argv[2]) ? (string) $argv[2] : null;

// ── tick：單一入口，自行決定這一輪該做什麼（見 CronScheduler）──
// 它不是 CronRunner 的子命令，而是「排程器」：委派給 CronRunner 去跑真正的命令，
// 所以 cron_runs 記到的仍然是 maintenance / run，後台的歷史與「上次成功時間」不受影響。
if ($command === 'tick') {
    echo "[YS CRM cron] command=tick 開始於 " . date('Y-m-d H:i:s') . PHP_EOL;

    $scheduler = new \YangSheep\CRM\Console\CronScheduler();
    $tick      = $scheduler->tick();
    $result    = $tick['result'];

    echo '  ' . ($tick['daily']
        ? '每日工作到期（設定時刻 ' . $scheduler->dailyAt() . '），執行完整批次'
        : '每日工作今天已完成，本輪處理電子發票維護與寄信佇列') . PHP_EOL;

    if ($result['output'] !== '') {
        foreach (explode("\n", $result['output']) as $line) {
            echo '  ' . $line . PHP_EOL;
        }
    }

    // busy 不是錯誤：另一輪正在跑，下一個 tick 會再判斷一次。
    if (!$result['ok'] && $result['status'] !== 'busy') {
        fwrite(STDERR, '[YS CRM cron] ' . $result['message'] . PHP_EOL);
        exit(1);
    }

    echo '[YS CRM cron] ' . $result['message'] . PHP_EOL;
    exit(0);
}

// 一律經 CronRunner：它負責併發鎖與執行紀錄。
// 三種觸發來源（cli / http / admin）走同一條路，後台的「排程設定」頁才看得到
// 完整的執行歷史 —— 週期帳單停擺兩個月沒被發現，就是因為當時沒有這份紀錄。
$runner = new \YangSheep\CRM\Console\CronRunner();

if (!\YangSheep\CRM\Console\CronRunner::isValidCommand($command)) {
    fwrite(STDERR, "未知子命令：{$command}\n");
    fwrite(STDERR, '可用：' . implode(' | ', \YangSheep\CRM\Console\CronRunner::COMMANDS) . "\n");
    exit(2);
}

echo "[YS CRM cron] command={$command}" . ($today !== null ? " today={$today}" : '') . " 開始於 " . date('Y-m-d H:i:s') . PHP_EOL;

$result = $runner->run($command, 'cli', null, $today);

if ($result['output'] !== '') {
    foreach (explode("\n", $result['output']) as $line) {
        echo '  ' . $line . PHP_EOL;
    }
}

if (!$result['ok']) {
    // busy 不是錯誤：代表另一份正在跑，這次刻意略過。
    if ($result['status'] === 'busy') {
        echo "[YS CRM cron] {$result['message']}" . PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo "[YS CRM cron] {$result['message']}" . PHP_EOL;
exit(0);
