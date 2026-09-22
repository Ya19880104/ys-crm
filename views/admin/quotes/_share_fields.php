<?php
/**
 * 公開連結自動關閉（Q3）欄位區——報價編輯表單與報價詳情頁共用同一份。
 *
 * 須置於提供 Alpine `visibility` 變數的祖先 x-data 之內（編輯表單的 quoteEditor；詳情頁自帶）。
 * 只輸出欄位、不含 <form>：由呼叫端決定送往 /admin/quotes/{id}（整張報價）
 * 或 /admin/quotes/{id}/share（只改分享設定，已簽署／已付款的報價也能延長或關閉）。
 *
 * @var array  $shareView   QuoteService::shareView()
 * @var ?array $shareInput  驗證失敗重新顯示時，使用者剛才送出的值
 * @var ?array $shareError  {field, message}
 * @var string $visibility  目前選取（或已儲存）的可見性
 */

use function YangSheep\CRM\Core\e;

$selVis = (string) ($visibility ?? 'private');

// ── 公開連結自動關閉（Q3）：已儲存狀態 vs 本次表單值 ──
$shareView  = is_array($shareView ?? null) ? $shareView : [];
$shareInput = is_array($shareInput ?? null) ? $shareInput : null;
$shareError = is_array($shareError ?? null) ? $shareError : null;

$shareSavedActive = ($shareView['state'] ?? '') === 'active';
$shareSavedAuto   = (bool) ($shareView['auto_expire'] ?? true);
$shareSavedDays   = (int) ($shareView['days'] ?? 30);
$shareNeedsReopen = (bool) ($shareView['needs_reopen'] ?? false);
$shareAcked       = (bool) ($shareView['unlimited_acked'] ?? false);
$shareTz          = (string) ($shareView['timezone'] ?? date_default_timezone_get());
$shareNow         = (int) ($shareView['now'] ?? time());
$shareEnabledAt   = isset($shareView['enabled_at']) ? (int) $shareView['enabled_at'] : null;

$shareAuto     = $shareInput !== null ? (bool) $shareInput['auto_expire'] : $shareSavedAuto;
$shareDays     = $shareInput !== null ? (string) $shareInput['days'] : (string) $shareSavedDays;
$shareReopen   = (bool) ($shareInput['reopen_confirm'] ?? false);
$shareErrField = (string) ($shareError['field'] ?? '');
$shareVisible  = in_array($selVis, ['public', 'password'], true);

// 警語文案只定義這一次：伺服器端用它算無 JS 的初始畫面，Alpine 用同一份模板即時更新。
$shareTexts = [
    'who_public'   => '任何知道此網址的人',
    'who_password' => '取得網址並知道密碼的人',
    'saved'        => '{who}都可以查看這份報價單，連結也可能被轉寄。已設定於公開後 {days} 天自動關閉（{expires}，{tz}）。你可以在此修改自動關閉設定。',
    'unsaved'      => '{who}都可以查看這份報價單。儲存啟用後，連結將於 {days} 天後自動關閉。設定尚未儲存。',
    'changed'      => '{who}都可以查看這份報價單。儲存後改為於公開後 {days} 天自動關閉（以原公開時間起算，不會延後起點）。設定尚未儲存。',
    'danger'       => '危險：未啟用自動關閉。{who}都可以持續查看、轉寄這份報價單，直到你手動關閉連結。報價內容及客戶資訊可能外洩，建議啟用自動關閉，或改用密碼／指定客戶登入。',
    'keep_closed'  => '連結目前已關閉；未勾選「重新公開」前，儲存不會重新開啟連結。',
    'preview'      => '儲存後到期時間：{expires}（{tz}）',
    'bad_days'     => '天數須為 1–365 的整數。',
];

// 伺服器端初始狀態（無 JS 時的畫面；與下方 quoteShare() 的判斷一一對應）。
$shareBase      = ($shareSavedActive && $shareEnabledAt !== null) ? $shareEnabledAt : $shareNow;
$shareDaysValid = preg_match('/^\d{1,3}$/', $shareDays) === 1 && (int) $shareDays >= 1 && (int) $shareDays <= 365;
$shareChanged   = !$shareSavedActive || $shareAuto !== $shareSavedAuto || ($shareAuto && (int) $shareDays !== $shareSavedDays);
$shareNeedsAck  = !$shareAuto && !($shareSavedActive && !$shareSavedAuto && $shareAcked);
$shareVars      = [
    '{who}'     => $selVis === 'password' ? $shareTexts['who_password'] : $shareTexts['who_public'],
    '{tz}'      => $shareTz,
    '{expires}' => (string) ($shareView['expires_at_label'] ?? ''),
    '{days}'    => (string) $shareSavedDays,
];
$shareKeepClosed = $shareNeedsReopen && !$shareReopen; // 已關閉／過期且未勾選重新公開：儲存不會開啟連結
if ($shareKeepClosed) {
    $shareAutoText = $shareTexts['keep_closed'];
} elseif ($shareSavedActive && $shareSavedAuto && !$shareChanged) {
    $shareAutoText = strtr($shareTexts['saved'], $shareVars);
} else {
    // 進行中的分享改期限：以原公開時間起算；新公開／重新公開：從儲存當下起算。
    $shareAutoText = strtr(
        $shareTexts[$shareSavedActive ? 'changed' : 'unsaved'],
        ['{days}' => $shareDaysValid ? (string) (int) $shareDays : '—'] + $shareVars
    );
}
$shareDangerText = $shareKeepClosed ? $shareTexts['keep_closed'] : strtr($shareTexts['danger'], $shareVars);
if ($shareKeepClosed || ($shareSavedActive && !$shareChanged)) {
    $sharePreviewText = ''; // 不會產生新的到期時間（維持關閉，或沿用上方「目前到期時間」）
} elseif (!$shareDaysValid) {
    $sharePreviewText = $shareTexts['bad_days'];
} else {
    $sharePreviewText = strtr($shareTexts['preview'], [
        '{expires}' => date('Y-m-d H:i', $shareBase + (int) $shareDays * 86400),
        '{tz}'      => $shareTz,
    ]);
}
$shareClosedWhen = (string) ($shareView['closed_at_label'] ?? '') !== ''
    ? '已於 ' . $shareView['closed_at_label'] . ' 關閉'
    : '已於 ' . (string) ($shareView['expires_at_label'] ?? '') . ' 到期';

$shareCfgJson = json_encode([
    'auto'              => $shareAuto,
    'days'              => $shareDays,
    'savedActive'       => $shareSavedActive,
    'savedAuto'         => $shareSavedAuto,
    'savedDays'         => $shareSavedDays,
    'savedExpiresLabel' => (string) ($shareView['expires_at_label'] ?? ''),
    'enabledAt'         => $shareEnabledAt,
    'acked'             => $shareAcked,
    'needsReopen'       => $shareNeedsReopen,
    'reopen'            => $shareReopen,
    'tz'                => $shareTz,
    'now'               => $shareNow,
    'texts'             => $shareTexts,
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<?php /* 公開連結自動關閉（Q3）：就地設定＋動態警語＋無期限／重新公開／縮短即關閉的明確確認。
          share_form=1 讓伺服器知道本區有送出；所有確認都由伺服器再驗，不依賴 JS。
          選 private／customer_only 時整區隱藏；無 JS 時依伺服器端初始可見性決定顯示與否。 */ ?>
<div id="share-settings" class="mb-5 space-y-3"
     x-data="quoteShare(<?= e($shareCfgJson) ?>)"
     x-show="visibility === 'public' || visibility === 'password'"
     <?= $shareVisible ? '' : 'style="display:none"' ?>>
    <input type="hidden" name="share_form" value="1">

    <?php if ($shareError !== null): ?>
    <p id="share-error" role="alert"
       class="rounded-lg border border-red-300 dark:border-red-500/40 bg-red-50 dark:bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-300 leading-relaxed">
        <span aria-hidden="true">⚠</span> <?= e((string) $shareError['message']) ?>
    </p>
    <?php endif; ?>

    <fieldset class="rounded-lg border border-slate-200 dark:border-surface-border p-3.5 space-y-3">
        <legend class="px-1 text-sm font-medium text-slate-700 dark:text-slate-300">公開連結自動關閉</legend>

        <?php if ($shareNeedsReopen): ?>
        <div class="rounded-lg border border-slate-300 dark:border-surface-border bg-slate-50 dark:bg-white/5 px-3 py-2.5">
            <p class="text-xs text-slate-700 dark:text-slate-200 leading-relaxed">
                此報價先前的公開連結<?= e($shareClosedWhen) ?>。重新公開會產生<strong>新連結</strong>，舊連結將永久失效。
            </p>
            <label class="mt-2 flex items-start gap-2 text-xs text-slate-800 dark:text-slate-100 cursor-pointer">
                <input type="checkbox" name="share_reopen_confirm" value="1" x-model="reopen"
                       <?= $shareReopen ? 'checked' : '' ?>
                       <?= $shareErrField === 'reopen' ? 'aria-invalid="true" aria-describedby="share-error"' : '' ?>
                       class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                <span>重新公開（產生新連結，舊連結永久失效）</span>
            </label>
        </div>
        <?php endif; ?>

        <label class="flex items-start gap-2 text-sm text-slate-800 dark:text-slate-100 cursor-pointer">
            <input type="checkbox" name="share_auto_expire" value="1" x-model="auto"
                   <?= $shareAuto ? 'checked' : '' ?>
                   class="mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
            <span>自動關閉公開連結</span>
        </label>

        <div x-show="auto" <?= $shareAuto ? '' : 'style="display:none"' ?>>
            <label for="share_duration_days" class="flex flex-wrap items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                <span>啟用後</span>
                <input type="number" id="share_duration_days" name="share_duration_days"
                       min="1" max="365" step="1" inputmode="numeric" x-model="days"
                       value="<?= e($shareDays) ?>"
                       <?= $shareErrField === 'days' ? 'aria-invalid="true" aria-describedby="share-error"' : '' ?>
                       class="w-20 px-2 py-1 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded text-sm text-right tabular-nums focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                <span>天關閉</span>
            </label>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 leading-relaxed">
                <?php if ($shareSavedActive && $shareSavedAuto): ?>
                <span class="block">目前到期時間：<span class="font-medium tabular-nums"><?= e((string) ($shareView['expires_at_label'] ?? '')) ?></span>（<?= e($shareTz) ?>）</span>
                <?php endif; ?>
                <span class="block tabular-nums" x-text="previewText()"><?= e($sharePreviewText) ?></span>
            </p>
        </div>

        <?php /* 縮短到已過期：伺服器要求明確確認。JS 即時判斷；無 JS 時只在伺服器回報此錯誤後顯示。 */ ?>
        <label x-show="willCloseNow()" <?= $shareErrField === 'close_now' ? '' : 'style="display:none"' ?>
               class="flex items-start gap-2 text-xs text-red-700 dark:text-red-300 cursor-pointer">
            <input type="checkbox" name="share_close_now_confirm" value="1"
                   <?= !empty($shareInput['close_now_confirm']) ? 'checked' : '' ?>
                   <?= $shareErrField === 'close_now' ? 'aria-invalid="true" aria-describedby="share-error"' : '' ?>
                   class="mt-0.5 rounded border-red-300 text-red-600 dark:text-red-400 focus:ring-red-500">
            <span>我了解以原公開時間計算已經到期，儲存後連結將立即關閉</span>
        </label>
    </fieldset>

    <!-- 已啟用自動關閉：提示「任何人可看」＋實際期限 -->
    <div x-show="auto" <?= $shareAuto ? '' : 'style="display:none"' ?>
         class="rounded-lg border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 px-3.5 py-3">
        <p class="text-xs text-amber-900 dark:text-amber-100 leading-relaxed">
            <span class="font-semibold">⚠ 注意：</span><span x-text="autoText(visibility)"><?= e($shareAutoText) ?></span>
        </p>
    </div>

    <!-- 未啟用自動關閉：危險警示（文字＋圖示，不只靠顏色）＋未預勾的風險確認 -->
    <div x-show="!auto" <?= $shareAuto ? 'style="display:none"' : '' ?>
         class="rounded-lg border-2 border-red-400 dark:border-red-500/60 bg-red-50 dark:bg-red-500/10 px-3.5 py-3 space-y-2">
        <p class="text-xs text-red-800 dark:text-red-200 leading-relaxed">
            <span class="font-semibold">⛔</span> <span x-text="dangerText(visibility)"><?= e($shareDangerText) ?></span>
        </p>
        <label x-show="needsAck()" <?= $shareNeedsAck ? '' : 'style="display:none"' ?>
               class="flex items-start gap-2 text-xs font-medium text-red-800 dark:text-red-200 cursor-pointer">
            <input type="checkbox" name="share_unlimited_ack" value="1"
                   <?= !empty($shareInput['unlimited_ack']) ? 'checked' : '' ?>
                   <?= $shareErrField === 'ack' ? 'aria-invalid="true" aria-describedby="share-error"' : '' ?>
                   class="mt-0.5 rounded border-red-300 text-red-600 dark:text-red-400 focus:ring-red-500">
            <span>我了解此連結不會自動過期</span>
        </label>
    </div>
</div>

<script>
/**
 * 公開連結自動關閉（Q3）的即時預覽。判斷與本檔開頭的伺服器端初始狀態一一對應，
 * 文案模板由伺服器傳入（cfg.texts），兩邊共用同一份。這裡只負責「顯示」：
 * 是否需要確認、期限是否合法，最終一律由伺服器 QuoteSharePolicy 判斷。
 */
window.quoteShare = window.quoteShare || function (cfg) {
    return Object.assign({}, cfg, {
        fill(tpl, vars) {
            return Object.keys(vars).reduce((s, k) => s.split(k).join(vars[k]), tpl);
        },
        daysValid() {
            const d = Number(this.days);
            return Number.isInteger(d) && d >= 1 && d <= 365;
        },
        changed() {
            return !this.savedActive || this.auto !== this.savedAuto
                || (this.auto && Number(this.days) !== this.savedDays);
        },
        // 進行中的分享以原公開時間起算（不滑動）；新公開／重新公開從現在起算。
        base() {
            return (this.savedActive && this.enabledAt) ? this.enabledAt : this.now;
        },
        fmt(ts) {
            try {
                // sv-SE 的格式即 YYYY-MM-DD HH:mm，與伺服器端 date('Y-m-d H:i') 一致；時區固定用網站時區。
                return new Intl.DateTimeFormat('sv-SE', {
                    timeZone: this.tz, year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hour12: false,
                }).format(new Date(ts * 1000));
            } catch (e) {
                return '';
            }
        },
        who(visibility) {
            return visibility === 'password' ? this.texts.who_password : this.texts.who_public;
        },
        keepClosed() {
            return this.needsReopen && !this.reopen;
        },
        previewText() {
            // 維持關閉，或沿用上方「目前到期時間」：不會產生新的到期時間。
            if (this.keepClosed() || (this.savedActive && !this.changed())) {
                return '';
            }
            if (!this.daysValid()) {
                return this.texts.bad_days;
            }
            return this.fill(this.texts.preview, {
                '{expires}': this.fmt(this.base() + Number(this.days) * 86400),
                '{tz}': this.tz,
            });
        },
        autoText(visibility) {
            if (this.keepClosed()) {
                return this.texts.keep_closed;
            }
            const vars = { '{who}': this.who(visibility), '{tz}': this.tz, '{expires}': this.savedExpiresLabel };
            if (this.savedActive && this.savedAuto && !this.changed()) {
                return this.fill(this.texts.saved, Object.assign({ '{days}': String(this.savedDays) }, vars));
            }
            // 進行中的分享改期限：以原公開時間起算；新公開／重新公開：從儲存當下起算。
            return this.fill(this.savedActive ? this.texts.changed : this.texts.unsaved,
                Object.assign({ '{days}': this.daysValid() ? String(Number(this.days)) : '—' }, vars));
        },
        dangerText(visibility) {
            return this.keepClosed() ? this.texts.keep_closed : this.fill(this.texts.danger, { '{who}': this.who(visibility) });
        },
        // 既有、已確認過的無期限分享不再反覆要求確認。
        needsAck() {
            return !this.auto && !(this.savedActive && !this.savedAuto && this.acked);
        },
        willCloseNow() {
            return this.savedActive && this.auto && this.daysValid() && this.changed()
                && (this.base() + Number(this.days) * 86400) <= this.now;
        },
    });
};
</script>
