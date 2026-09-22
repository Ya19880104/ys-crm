<?php
declare(strict_types=1);
namespace YangSheep\CRM\Install;

use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Session;

/** Native/file-session endpoint, before installed application routing or DB sessions. */
final class InstallationResume
{
    public static function respond(InstallationMarkers $markers): never
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=UTF-8');
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrf = $_POST['_csrf_token'] ?? null;
            $code = $_POST['recovery_code'] ?? null;
            if (!is_string($csrf) || !Csrf::validate($csrf)
                || !is_string($code)) {
                http_response_code(403);
                exit('Forbidden');
            }
            try {
                if (!session_regenerate_id(true)) { throw new \RuntimeException('session unavailable'); }
                $token = $markers->resumeFromCode(trim($code));
                $_SESSION = [InstallationMarkers::SESSION_KEY => $token, 'install_step' => 1];
                if (!session_write_close()) { throw new \RuntimeException('session unavailable'); }
                header('Location: /install', true, 303);
                exit;
            } catch (\Throwable) {
                // Never echo submitted proof or filesystem/DB errors into the response.
                http_response_code(400);
                $error = '復原碼無效、已過期或無法保存。請由本機重新執行 recovery。';
            }
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');
            exit('Method Not Allowed');
        }
        $field = Csrf::field();
        echo '<!doctype html><html lang="zh-Hant"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>恢復安裝</title><main><h1>恢復安裝</h1><p>由本機部署者取得一次性復原碼，請勿將復原碼放入網址或交接紀錄。</p>'
            . '<p role="alert">' . $error . '</p><form method="post" action="/install/recover">' . $field
            . '<label for="recovery-code">復原碼</label> <input id="recovery-code" name="recovery_code" type="password" autocomplete="off" minlength="64" maxlength="64" required>'
            . ' <button type="submit">恢復安裝</button></form></main></html>';
        exit;
    }
}
