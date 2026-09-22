<?php
/**
 * uploads/ 執行封鎖樁（由 uploads/.user.ini 的 auto_prepend_file 觸發）。
 *
 * 【為什麼需要這個檔】
 * uploads/.htaccess 只在 Apache／LiteSpeed 生效；nginx 完全不讀 .htaccess，
 * 而一般虛擬主機用戶碰不到 vhost 設定。PHP 的 auto_prepend_file 是 PHP_INI_PERDIR，
 * 可以寫在 .user.ini 裡，PHP-FPM／CGI 會從被執行腳本的目錄往上掃描 .user.ini。
 * 因此只要 uploads/.user.ini 指向本檔，任何落在 uploads/ 之下的 .php 都會
 * 先執行本檔並中止 —— 不需要任何 server 設定權限。
 *
 * 【本檔刻意極簡】它在框架載入之前執行，不得依賴 autoloader、常數或設定。
 *
 * 🔴 本檔請勿刪除或改名。auto_prepend_file 指向的檔案若開不起來，
 * PHP 8.5 會發出 Warning + Fatal 並中止原腳本；仍須修復失效的設定。
 * UploadGuard::describe() 會檢查本檔是否仍存在，安裝檢測頁會顯示結果。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
}

exit('Forbidden');
