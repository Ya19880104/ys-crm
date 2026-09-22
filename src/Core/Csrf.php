<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class Csrf
{
    /**
     * 產生 CSRF Token 並存入 Session
     */
    public static function generate(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('_csrf_token', $token);
        Session::set('_csrf_token_time', time());
        return $token;
    }

    /**
     * 取得現有 Token（不存在則產生新的）
     */
    public static function token(): string
    {
        $token = Session::get('_csrf_token');
        if ($token === null) {
            return self::generate();
        }

        // 檢查是否過期（預設 1 小時）
        $tokenTime = Session::get('_csrf_token_time', 0);
        $lifetime = (int) ($_ENV['CSRF_LIFETIME'] ?? 3600);
        if (time() - $tokenTime > $lifetime) {
            return self::generate();
        }

        return $token;
    }

    /**
     * 驗證 Token
     */
    /**
     * 驗證 Token
     *
     * @param bool $regenerate 驗證成功後是否重新產生 token（表單提交建議 true，AJAX 建議 false）
     */
    public static function validate(?string $token, bool $regenerate = false): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $stored = Session::get('_csrf_token');
        if ($stored === null) {
            return false;
        }

        $valid = hash_equals($stored, $token);

        // 驗證成功後重新產生 token（防止重放攻擊）
        if ($valid && $regenerate) {
            self::generate();
        }

        return $valid;
    }

    /**
     * 輸出隱藏表單欄位 HTML
     */
    public static function field(): string
    {
        $token = self::token();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
