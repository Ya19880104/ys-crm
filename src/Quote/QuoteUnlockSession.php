<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\Session;

/**
 * 密碼報價的 session 解鎖狀態（報價頁與付款頁共用唯一來源）。
 *
 * 解鎖綁定「分享授權版本＋token 指紋」：改密碼、換 token（關閉後重新公開）、
 * 調整期限都會使版本 +1，先前的解鎖自動失效，不會讓舊 session 繞過新的授權。
 * 舊格式（單純 true）一律視為未解鎖——升級後訪客需重新輸入一次密碼。
 *
 * session 只存 token 的 sha256 指紋，不存 token 本身。
 */
final class QuoteUnlockSession
{
    public static function isUnlocked(array $quote): bool
    {
        $stored = Session::get(self::key((int) ($quote['id'] ?? 0)));
        if (!is_array($stored)) {
            return false;
        }

        return (int) ($stored['rev'] ?? -1) === (int) ($quote['share_policy_revision'] ?? 0)
            && hash_equals((string) ($stored['tok'] ?? ''), self::fingerprint($quote));
    }

    public static function markUnlocked(array $quote): void
    {
        Session::set(self::key((int) $quote['id']), [
            'rev' => (int) ($quote['share_policy_revision'] ?? 0),
            'tok' => self::fingerprint($quote),
        ]);
    }

    private static function key(int $quoteId): string
    {
        return '_quote_unlocked_' . $quoteId;
    }

    private static function fingerprint(array $quote): string
    {
        return hash('sha256', (string) ($quote['access_token'] ?? ''));
    }
}
