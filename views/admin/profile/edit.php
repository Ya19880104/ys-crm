<?php
/**
 * 個人資料頁 — 編輯自己的顯示名稱、Email、頭像與密碼。
 *
 * 角色與帳號狀態不在此頁：那是管理操作，需 user.manage 權限並走使用者管理頁。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var array  $profile
 * @var string $_csrf
 */

$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-2xl">
    <div class="bg-white dark:bg-surface-card rounded-lg shadow-sm p-6">
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-slate-100">個人資料</h3>
            <p class="text-sm text-gray-500 dark:text-slate-400 mt-1">
                <?= e($profile['role_name'] ?? '') ?>
            </p>
        </div>

        <form method="POST" action="/admin/profile" class="space-y-5"
              enctype="multipart/form-data"
              x-data="{ preview: '', removing: false }">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

            <!-- 頭像 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">頭像</label>
                <div class="flex items-center gap-4">
                    <div class="flex-shrink-0">
                        <template x-if="preview">
                            <img :src="preview" alt="預覽" class="ys-avatar-img" style="width:64px;height:64px;">
                        </template>
                        <div x-show="!preview" :class="removing ? 'opacity-40' : ''">
                            <?php
                            $avatarPath = (string) ($profile['avatar_path'] ?? '');
                            $size       = 64;
                            $alt        = (string) ($profile['display_name'] ?? '');
                            require VIEWS_PATH . '/partials/avatar.php';
                            ?>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <input type="file" id="avatar" name="avatar"
                               accept="image/jpeg,image/png,image/webp"
                               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''; removing = false"
                               class="block w-full text-sm text-gray-600 dark:text-slate-400
                                      file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0
                                      file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100 file:cursor-pointer">
                        <p class="text-xs text-gray-400 mt-1.5">JPG / PNG / WebP，2MB 以內。建議正方形，會自動裁切為圓形。</p>

                        <?php if (trim((string) ($profile['avatar_path'] ?? '')) !== ''): ?>
                        <label class="inline-flex items-center gap-1.5 mt-2 text-xs text-gray-500 dark:text-slate-400 cursor-pointer">
                            <input type="checkbox" name="remove_avatar" value="1" x-model="removing"
                                   class="rounded border-gray-300">
                            移除頭像，改用預設圖案
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 帳號（可變更，含即時可用性檢查） -->
            <div x-data="usernameCheck('<?= e($profile['username'] ?? '') ?>')">
                <label for="username" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    帳號 <span class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input type="text" id="username" name="username" required
                       maxlength="50"
                       autocomplete="username"
                       x-model="value"
                       @input.debounce.400ms="check()"
                       :aria-invalid="state === 'taken' || state === 'invalid' ? 'true' : 'false'"
                       aria-describedby="username-hint"
                       class="w-full px-4 py-2.5 border rounded-lg
                              bg-white dark:bg-navy-topbar text-gray-800 dark:text-slate-100
                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       :class="state === 'taken' || state === 'invalid'
                               ? 'border-red-600 dark:border-red-600'
                               : 'border-gray-300 dark:border-surface-border'">

                <p id="username-hint" class="text-xs mt-1 min-h-[1rem]"
                   :class="{
                       'text-slate-500 dark:text-slate-400': state === 'idle' || state === 'checking' || state === 'same',
                       'text-blue-700 dark:text-blue-300': state === 'ok',
                       'text-red-700 dark:text-red-300': state === 'taken' || state === 'invalid'
                   }"
                   x-text="message"
                   aria-live="polite"></p>
            </div>

            <!-- 顯示名稱 -->
            <div>
                <label for="display_name" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    顯示名稱 <span class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input type="text" id="display_name" name="display_name" required
                       value="<?= e($profile['display_name'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-gray-300 dark:border-surface-border rounded-lg
                              bg-white dark:bg-navy-topbar text-gray-800 dark:text-slate-100
                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    Email <span class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input type="email" id="email" name="email" required
                       value="<?= e($profile['email'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-gray-300 dark:border-surface-border rounded-lg
                              bg-white dark:bg-navy-topbar text-gray-800 dark:text-slate-100
                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <hr class="border-gray-200 dark:border-surface-border">

            <!-- 密碼 -->
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">新密碼</label>
                <input type="password" id="password" name="password" autocomplete="new-password"
                       placeholder="留空表示不修改密碼"
                       class="w-full px-4 py-2.5 border border-gray-300 dark:border-surface-border rounded-lg
                              bg-white dark:bg-navy-topbar text-gray-800 dark:text-slate-100
                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                <p class="text-xs text-gray-400 mt-1.5">至少 10 個字元。變更密碼後所有裝置都需重新登入。</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">確認新密碼</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"
                       placeholder="留空表示不修改密碼"
                       class="w-full px-4 py-2.5 border border-gray-300 dark:border-surface-border rounded-lg
                              bg-white dark:bg-navy-topbar text-gray-800 dark:text-slate-100
                              focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    儲存變更
                </button>
                <a href="/admin/dashboard" class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200">取消</a>
            </div>
        </form>
    </div>
</div>

<?php
/*
 * 我的登入紀錄。
 *
 * 【為何個人也要看得到】「有人在猜我的密碼」是本人最容易察覺異常的資訊 ——
 * 陌生的時間、陌生的位置。把它鎖在管理員專屬的稽核頁裡，等於要所有人
 * 都倚賴管理員定期巡查；而實務上沒有人會定期巡查。
 *
 * 只列出這個人自己的紀錄（帳號與 Email 兩種識別字都比對），
 * 不會看到別人的。管理員要看全部請走「稽核紀錄 → 登入紀錄」。
 */
?>
<div class="max-w-2xl mt-6">
    <div class="bg-white dark:bg-surface-card rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-surface-border flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-slate-100">我的登入紀錄</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    最近 10 筆（共 <?= e((string) ($myAttemptsTotal ?? 0)) ?> 筆）。
                    看到不是自己的登入嘗試，請立即更改密碼。
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
        <table class="w-full text-sm whitespace-nowrap">
            <thead class="bg-slate-50 dark:bg-white/5 border-b border-slate-200 dark:border-surface-border">
                <tr>
                    <th class="text-left px-4 py-2.5 font-medium text-slate-500 dark:text-slate-400">時間</th>
                    <th class="text-left px-4 py-2.5 font-medium text-slate-500 dark:text-slate-400">結果</th>
                    <th class="text-left px-4 py-2.5 font-medium text-slate-500 dark:text-slate-400">使用的識別字</th>
                    <th class="text-left px-4 py-2.5 font-medium text-slate-500 dark:text-slate-400">來源位址</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-surface-border">
                <?php if (empty($myAttempts)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">尚無登入紀錄</td></tr>
                <?php else: ?>
                    <?php foreach ($myAttempts as $a): ?>
                    <?php $ok = (int) ($a['success'] ?? 0) === 1; ?>
                    <tr>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-700 dark:text-slate-200"><?= e(substr((string) ($a['attempted_at'] ?? ''), 0, 19)) ?></td>
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                <?= $ok
                                    ? 'bg-blue-600 text-white dark:bg-blue-600'
                                    : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300' ?>">
                                <?= $ok ? '成功' : '失敗' ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-700 dark:text-slate-200"><?= e((string) ($a['username'] ?? '—')) ?></td>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400"><?= e((string) ($a['ip_address'] ?? '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
/**
 * 帳號可用性即時檢查。
 *
 * 【這只是提示，不是防護】真正的把關在伺服器端（UserService::update() 的格式與
 * 唯一性檢查，以及資料庫的 UNIQUE 索引）。這裡壞掉或被繞過都不影響正確性 ——
 * 所以它可以放心失敗：網路錯誤時就回到中性狀態，不擋使用者送出。
 *
 * 【為何不擋 submit】看起來「防呆」，實際上會在檢查逾時或請求被擋時讓表單完全
 * 送不出去，而使用者不知道為什麼。伺服器端本來就會擋，讓它擋並回報明確訊息即可。
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('usernameCheck', (original) => ({
        original,
        value: original,
        state: 'idle',      // idle | checking | ok | same | taken | invalid
        message: '英數與 . _ - ，3–50 字元。變更後可用新帳號或 Email 登入。',
        seq: 0,

        async check() {
            const v = (this.value || '').trim();

            if (v === this.original) {
                this.state = 'same';
                this.message = '這是您目前的帳號。';
                return;
            }
            if (v === '') {
                this.state = 'invalid';
                this.message = '請輸入帳號。';
                return;
            }

            this.state = 'checking';
            this.message = '檢查中…';

            // 序號：慢的回應不可以覆蓋掉後來較新的結果
            const mySeq = ++this.seq;

            try {
                const body = new URLSearchParams();
                body.set('username', v);
                body.set('_csrf_token', document.querySelector('input[name="_csrf_token"]').value);

                const res = await fetch('/admin/profile/check-username', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                    credentials: 'same-origin',
                });

                if (mySeq !== this.seq) { return; }   // 已有更新的請求，丟棄本次結果
                if (!res.ok) { throw new Error('HTTP ' + res.status); }

                const data = await res.json();
                if (mySeq !== this.seq) { return; }

                this.state = data.available ? 'ok' : (data.message && data.message.includes('已被使用') ? 'taken' : 'invalid');
                this.message = data.message || '';
            } catch (e) {
                if (mySeq !== this.seq) { return; }
                // 檢查失敗不等於帳號不能用 —— 回到中性提示，交給伺服器端把關。
                this.state = 'idle';
                this.message = '無法即時檢查，儲存時仍會驗證。';
            }
        },
    }));
});
</script>
