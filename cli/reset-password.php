<?php

declare(strict_types=1);

/**
 * 重設後台帳號密碼（CLI）。
 *
 * 用法：
 *   php cli/reset-password.php <username>            產生隨機密碼
 *   php cli/reset-password.php <username> --portal   重設客戶 Portal 帳號（以 login_email 指定）
 *
 * 設計重點：
 *   1. **不接受從參數傳入密碼** —— 參數會留在 shell history 與 process list，
 *      那等同於把密碼寫進另一個地方。一律由系統產生並只印出一次。
 *   2. 重設後撤銷該帳號所有既存 session（含其他裝置），避免舊 session 續用。
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

// 🔴 ENV_PATH 必須定義。Session::shouldUseDbHandler() 會檢查這個常數，
// 沒定義就一律回 false —— 於是 revokeAllForUser() 靜默變成 no-op，
// 而 CLI 還是會照樣印出「所有 session 已撤銷」。輪替憑證卻沒真的撤銷登入態，
// 是這支腳本最危險的失敗模式（本輪即由複審實測抓到）。
//
// DotEnv::load() 收的也是「目錄」而非檔案路徑；傳檔案路徑會靜默載入不到，
// 接著就會用預設值連 127.0.0.1 並得到 Connection refused。
// 同層優先、否則上一層 —— 對應 Structure B 部署（app 在家目錄、docroot 在其下）。
define('ENV_PATH', file_exists(BASE_PATH . '/.env') ? BASE_PATH : dirname(BASE_PATH));

require BASE_PATH . '/src/Core/Autoloader.php';
\Autoloader::register();
\Autoloader::addNamespace('YangSheep\CRM\\', BASE_PATH . '/src/');

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\DotEnv;
use YangSheep\CRM\Auth\CredentialRotationService;

DotEnv::load(ENV_PATH);

$identifier = $argv[1] ?? '';
$isPortal   = in_array('--portal', $argv, true);

if ($identifier === '') {
    fwrite(STDERR, "用法：php cli/reset-password.php <username>            重設後台帳號\n");
    fwrite(STDERR, "      php cli/reset-password.php <email> --portal      重設客戶 Portal 帳號\n");
    exit(2);
}

/**
 * 產生可讀但足夠強的隨機密碼（20 字元，含大小寫、數字、符號）。
 * 排除易混淆字元（0/O、1/l/I），因為這組密碼很可能需要人工轉達。
 */
function generate_password(int $length = 20): string
{
    $sets = [
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        'abcdefghijkmnpqrstuvwxyz',
        '23456789',
        '!@#$%^&*-_=+',
    ];

    $chars = implode('', $sets);
    $out   = '';

    // 先各取一個，確保四類都出現
    foreach ($sets as $set) {
        $out .= $set[random_int(0, strlen($set) - 1)];
    }
    for ($i = strlen($out); $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }

    // 洗牌，避免固定的「四類在前」樣式
    $arr = str_split($out);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }

    return implode('', $arr);
}

try {
    $db       = Database::getInstance();
    $password = generate_password();

    if ($isPortal) {
        $row = $db->fetch(
            "SELECT id, login_email FROM {prefix}customer_users WHERE login_email = :id LIMIT 1",
            ['id' => $identifier]
        );
        if ($row === null) {
            fwrite(STDERR, "找不到客戶 Portal 帳號：{$identifier}\n");
            exit(1);
        }

        // 🔴 欄位是 password_hash，不是 password（見 migration 042）。
        // 後台的 {prefix}users 才叫 password —— 兩個平面欄位名不同，
        // 抄過來會直接 Unknown column。
        $userType = 'customer';
        $userId   = (int) $row['id'];
        $label = '客戶 Portal';
    } else {
        $row = $db->fetch(
            "SELECT id, username FROM {prefix}users WHERE username = :id LIMIT 1",
            ['id' => $identifier]
        );
        if ($row === null) {
            fwrite(STDERR, "找不到後台帳號：{$identifier}\n");
            exit(1);
        }

        $userType = 'admin';
        $userId   = (int) $row['id'];
        $label = '後台';
    }

    $rotator = new CredentialRotationService();
    $revoked = $isPortal
        ? $rotator->rotateCustomer($userId, $password)
        : $rotator->rotateAdmin($userId, $password);

    echo PHP_EOL;
    echo "已重設 {$label} 帳號「{$identifier}」的密碼（已讀回驗證）。" . PHP_EOL;
    echo "新密碼（只顯示這一次，請立即保存到密碼管理器）：" . PHP_EOL . PHP_EOL;
    echo '    ' . $password . PHP_EOL . PHP_EOL;
    echo "已撤銷該帳號 {$revoked} 個既存 session（{$userType}/{$userId}），需以新密碼重新登入。" . PHP_EOL;
    echo PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '重設失敗：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
