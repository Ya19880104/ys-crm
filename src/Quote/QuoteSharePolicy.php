<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

/**
 * 報價匿名分享期限政策（Q2；純函式，時間一律由呼叫端注入，邊界可用固定時鐘測試）。
 *
 * 只規範 public／password 兩種「知道網址即可存取」的匿名分享：
 *   - 到期是固定時間點：now >= share_expires_at 即拒絕（到期前 1 秒仍可用）。
 *   - 起算點於第一次儲存生效時決定；之後瀏覽、重寄、編輯、public↔password 切換都不重算、不滑動。
 *   - share_auto_expire 為 NULL＝未初始化，一律視為不可用（fail-closed），不得當成永久可看。
 *   - 關閉或過期後，改天數、關掉自動關閉都不會讓舊連結復活；只能明確「重新公開」並換新 token。
 *
 * customer_only（靠客戶登入）與 private（本就拒絕）不套用本政策；valid_until 是業務有效期，與此無關。
 */
final class QuoteSharePolicy
{
    public const DEFAULT_DAYS    = 30;
    public const MIN_DAYS        = 1;
    public const MAX_DAYS        = 365;
    public const SECONDS_PER_DAY = 86400;

    public const STATE_NOT_SHARED    = 'not_shared';
    public const STATE_UNINITIALIZED = 'uninitialized';
    public const STATE_ACTIVE        = 'active';
    public const STATE_EXPIRED       = 'expired';
    public const STATE_CLOSED        = 'closed';

    public const EVENT_NONE     = 'none';
    public const EVENT_OPENED   = 'opened';
    public const EVENT_REOPENED = 'reopened';
    public const EVENT_CHANGED  = 'changed';
    public const EVENT_CLOSED   = 'closed';

    /** 參與「分享授權版本」的欄位：任一變動，進行中的簽署／付款都必須重新確認。 */
    public const REVISION_FIELDS = [
        'visibility', 'access_token', 'access_password_hash',
        'share_auto_expire', 'share_duration_days', 'share_enabled_at',
        'share_expires_at', 'share_closed_at', 'share_unlimited_ack_at',
    ];

    public static function isAnonymous(string $visibility): bool
    {
        return $visibility === 'public' || $visibility === 'password';
    }

    /** 目前可見性下的分享狀態（非匿名分享一律回 not_shared）。 */
    public static function state(array $quote, int $now): string
    {
        if (!self::isAnonymous((string) ($quote['visibility'] ?? ''))) {
            return self::STATE_NOT_SHARED;
        }
        return self::shareState($quote, $now);
    }

    /** 只看分享欄位本身（不看可見性），供「切換可見性前，舊分享處於什麼狀態」判斷。 */
    public static function shareState(array $quote, int $now): string
    {
        if (self::intOrNull($quote['share_closed_at'] ?? null) !== null) {
            return self::STATE_CLOSED;
        }
        $auto    = self::intOrNull($quote['share_auto_expire'] ?? null);
        $enabled = self::intOrNull($quote['share_enabled_at'] ?? null);
        if ($auto === null || $enabled === null) {
            return self::STATE_UNINITIALIZED;
        }
        if ($auto === 0) {
            return self::STATE_ACTIVE; // 無期限（已明確確認風險）
        }
        $expires = self::intOrNull($quote['share_expires_at'] ?? null);
        if ($expires === null) {
            return self::STATE_UNINITIALIZED; // 自動關閉卻沒有到期點：資料不一致，fail-closed
        }
        return $now < $expires ? self::STATE_ACTIVE : self::STATE_EXPIRED;
    }

    public static function isActive(array $quote, int $now): bool
    {
        return self::state($quote, $now) === self::STATE_ACTIVE;
    }

    /** 天數只接受 1–365 的整數；空值、0、負數、小數、過大值一律回 null（由呼叫端決定是否報錯）。 */
    public static function normalizeDays(mixed $value): ?int
    {
        if (is_int($value)) {
            $days = $value;
        } elseif (is_string($value) && preg_match('/^\s*\d{1,4}\s*$/', $value) === 1) {
            $days = (int) trim($value);
        } else {
            return null;
        }
        return ($days >= self::MIN_DAYS && $days <= self::MAX_DAYS) ? $days : null;
    }

    public static function expiresAtFor(int $enabledAt, int $days): int
    {
        return $enabledAt + $days * self::SECONDS_PER_DAY;
    }

    /**
     * 依「現有列＋新可見性＋表單輸入」算出要寫入的分享欄位。
     *
     * $input：
     *   present            表單是否送出分享區（share_form=1）；缺席代表呼叫端沒提供期限欄位
     *   auto_expire        是否勾選自動關閉
     *   days               天數原始輸入
     *   unlimited_ack      勾選「我了解此連結不會自動過期」
     *   reopen_confirm     勾選重新公開（換新連結，舊連結永久失效）
     *   close_now_confirm  勾選「縮短後將立即關閉」
     *
     * @param array<string, mixed> $existing 現有列（新增報價時為空陣列）
     * @param array<string, mixed> $input
     * @return array{fields: array<string, mixed>, rotate_token: bool, event: string}
     * @throws QuoteShareValidationException
     */
    public static function planChange(array $existing, string $newVisibility, array $input, int $now): array
    {
        $none = ['fields' => [], 'rotate_token' => false, 'event' => self::EVENT_NONE];

        $oldVisibility = (string) ($existing['visibility'] ?? 'private');
        $shareState    = $existing === [] ? self::STATE_UNINITIALIZED : self::shareState($existing, $now);
        $everShared    = self::intOrNull($existing['share_enabled_at'] ?? null) !== null
            || self::intOrNull($existing['share_closed_at'] ?? null) !== null;

        // ── 改為非匿名分享：原本對外有效的分享一律關閉，之後要再公開就必須明確重開 ──
        if (!self::isAnonymous($newVisibility)) {
            if (self::isAnonymous($oldVisibility) && $shareState !== self::STATE_CLOSED) {
                return [
                    'fields'       => ['share_closed_at' => $now],
                    'rotate_token' => false,
                    'event'        => self::EVENT_CLOSED,
                ];
            }
            return $none;
        }

        $present = !empty($input['present']);

        // ── 第一次公開（從未分享過）：預設啟用＋30 天，draft 也從現在起算 ──
        if (!$everShared) {
            return [
                'fields'       => self::openFields($input, $present, $now),
                'rotate_token' => false,
                'event'        => self::EVENT_OPENED,
            ];
        }

        // ── 曾分享過但目前無效（已關閉、已過期，或欄位不一致）：任何期限修改都不得讓舊連結復活，
        //    只能明確重新公開，並換新 token ──
        if ($shareState !== self::STATE_ACTIVE) {
            if (!empty($input['reopen_confirm'])) {
                return [
                    'fields'       => self::openFields($input, $present, $now),
                    'rotate_token' => true,
                    'event'        => self::EVENT_REOPENED,
                ];
            }
            $switchingIn = !self::isAnonymous($oldVisibility);
            if ($switchingIn || ($present && self::requestDiffers($existing, $input))) {
                throw new QuoteShareValidationException(
                    'reopen',
                    '此報價的公開連結已關閉或過期。修改期限不會重新開啟舊連結；若要重新公開，請勾選「重新公開」（會產生新連結，舊連結永久失效）。'
                );
            }
            return $none; // 只改其他欄位：分享維持關閉
        }

        // ── 分享進行中：起點不變，只調整期限 ──
        if (!$present) {
            return $none;
        }

        $enabledAt   = (int) $existing['share_enabled_at'];
        $currentAuto = (int) $existing['share_auto_expire'] === 1;
        $wantAuto    = !empty($input['auto_expire']);
        $fields      = [];

        if (!$wantAuto) {
            if ($currentAuto) {
                if (empty($input['unlimited_ack'])) {
                    throw new QuoteShareValidationException('ack', '改為不自動關閉前，請勾選「我了解此連結不會自動過期」。');
                }
                $fields = [
                    'share_auto_expire'      => 0,
                    'share_expires_at'       => null,
                    'share_unlimited_ack_at' => $now,
                ];
            }
            // 原本就是已確認的無期限：一般編輯不反覆要求確認。
        } else {
            $days = self::normalizeDays($input['days'] ?? null);
            if ($days === null) {
                throw new QuoteShareValidationException('days', sprintf('自動關閉天數須為 %d–%d 的整數。', self::MIN_DAYS, self::MAX_DAYS));
            }
            $newExpires = self::expiresAtFor($enabledAt, $days);
            $changed    = !$currentAuto || $days !== (int) ($existing['share_duration_days'] ?? 0);
            if ($changed) {
                if ($newExpires <= $now && empty($input['close_now_confirm'])) {
                    throw new QuoteShareValidationException(
                        'close_now',
                        '以原公開時間計算，新的到期時間已經過去，儲存後連結會立即關閉。確定要這樣做，請勾選「我了解儲存後連結將立即關閉」。'
                    );
                }
                $fields = [
                    'share_auto_expire'      => 1,
                    'share_duration_days'    => $days,
                    'share_expires_at'       => $newExpires,
                    'share_unlimited_ack_at' => null,
                ];
            }
        }

        return [
            'fields'       => $fields,
            'rotate_token' => false,
            'event'        => $fields === [] ? self::EVENT_NONE : self::EVENT_CHANGED,
        ];
    }

    /**
     * 開啟（或重新開啟）一次匿名分享的欄位。缺少表單欄位時採預設：啟用自動關閉、30 天。
     *
     * @return array<string, mixed>
     * @throws QuoteShareValidationException
     */
    private static function openFields(array $input, bool $present, int $now): array
    {
        $wantAuto = $present ? !empty($input['auto_expire']) : true;

        if (!$wantAuto) {
            if (empty($input['unlimited_ack'])) {
                throw new QuoteShareValidationException('ack', '選擇不自動關閉前，請勾選「我了解此連結不會自動過期」。');
            }
            $days = self::normalizeDays($input['days'] ?? null) ?? self::DEFAULT_DAYS;
            return [
                'share_auto_expire'      => 0,
                'share_duration_days'    => $days,
                'share_enabled_at'       => $now,
                'share_expires_at'       => null,
                'share_closed_at'        => null,
                'share_unlimited_ack_at' => $now,
            ];
        }

        $days = $present ? self::normalizeDays($input['days'] ?? null) : self::DEFAULT_DAYS;
        if ($days === null) {
            throw new QuoteShareValidationException('days', sprintf('自動關閉天數須為 %d–%d 的整數。', self::MIN_DAYS, self::MAX_DAYS));
        }

        return [
            'share_auto_expire'      => 1,
            'share_duration_days'    => $days,
            'share_enabled_at'       => $now,
            'share_expires_at'       => self::expiresAtFor($now, $days),
            'share_closed_at'        => null,
            'share_unlimited_ack_at' => null,
        ];
    }

    /** 表單要求的期限是否與已儲存的不同（供「關閉後只改期限」的判斷）。 */
    private static function requestDiffers(array $existing, array $input): bool
    {
        $wantAuto    = !empty($input['auto_expire']);
        $currentAuto = (int) ($existing['share_auto_expire'] ?? 1) === 1;
        if ($wantAuto !== $currentAuto) {
            return true;
        }
        if (!$wantAuto) {
            return false;
        }
        return self::normalizeDays($input['days'] ?? null) !== (int) ($existing['share_duration_days'] ?? 0);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }
}
