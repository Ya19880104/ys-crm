<?php
/**
 * 客戶 Portal — 團隊與子帳號（僅 owner）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array  $members      含 role / is_active / permissions_json
 * @var array  $allScopes
 * @var array  $scopeLabels  scope => 中文
 */

use function YangSheep\CRM\Core\e;

$decodeScopes = static function ($json): array {
    if (is_string($json) && $json !== '') {
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }
    return is_array($json) ? $json : [];
};
?>

<div x-data="{ showAdd: false }">
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-slate-500 dark:text-slate-400">主帳號可建立子帳號並指定其可存取的功能。</p>
        <button type="button" @click="showAdd = !showAdd"
                class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            新增子帳號
        </button>
    </div>

    <!-- 新增子帳號 -->
    <div x-show="showAdd" x-cloak x-transition class="bg-white dark:bg-surface-card border border-blue-200 dark:border-blue-500/30 rounded-xl p-5 mb-5">
        <form method="POST" action="/portal/team" class="space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Email <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="email" name="email" required maxlength="255" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">顯示名稱</label>
                    <input type="text" name="display_name" maxlength="100" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">初始密碼 <span class="text-red-600 dark:text-red-400">*</span></label>
                    <input type="text" name="password" required minlength="8" maxlength="200" placeholder="至少 8 字元" class="w-full px-3 py-2 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-2">可存取功能</p>
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($scopeLabels as $code => $label): ?>
                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="permissions[]" value="<?= e($code) ?>" class="rounded text-blue-600 focus:ring-blue-500">
                        <?= e($label) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] text-slate-400 mt-1">「團隊與子帳號管理」僅限主帳號，無法授予子帳號。</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">建立子帳號</button>
                <button type="button" @click="showAdd = false" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">取消</button>
            </div>
        </form>
    </div>

    <!-- 成員列表 -->
    <div class="space-y-3">
        <?php foreach ($members as $m):
            $isOwner = (string) $m['role'] === 'owner';
            $active = (int) ($m['is_active'] ?? 0) === 1;
            $scopes = $decodeScopes($m['permissions_json'] ?? null);
        ?>
        <div class="bg-white dark:bg-surface-card border border-slate-200 dark:border-surface-border rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-slate-900 dark:text-slate-100"><?= e($m['display_name'] ?: $m['login_email']) ?></span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $isOwner ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300' ?>"><?= $isOwner ? '主帳號' : '子帳號' ?></span>
                        <?php if (!$active): ?><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300">已停用</span><?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5 break-all"><?= e($m['login_email']) ?></p>
                    <?php if (!$isOwner): ?>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <?php if ($scopes === []): ?>
                            <span class="text-xs text-slate-400">未授予任何功能</span>
                        <?php else: foreach ($scopes as $sc): ?>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><?= e($scopeLabels[$sc] ?? $sc) ?></span>
                        <?php endforeach; endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!$isOwner): ?>
                <div class="flex flex-col items-end gap-2 flex-shrink-0">
                    <!-- 停用 / 啟用 -->
                    <form method="POST" action="/portal/team/<?= (int) $m['id'] ?>/toggle">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                        <button type="submit" class="text-xs font-medium <?= $active ? 'text-red-600 dark:text-red-400 hover:text-red-800' : 'text-blue-600 hover:text-blue-800' ?>">
                            <?= $active ? '停用' : '啟用' ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- 改權限（子帳號） -->
            <?php if (!$isOwner): ?>
            <details class="mt-3 pt-3 border-t border-slate-100 dark:border-surface-border">
                <summary class="text-xs text-slate-500 dark:text-slate-400 cursor-pointer hover:text-slate-700">調整可存取功能</summary>
                <form method="POST" action="/portal/team/<?= (int) $m['id'] ?>/permissions" class="mt-3 space-y-3">
                    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ($scopeLabels as $code => $label): ?>
                        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="permissions[]" value="<?= e($code) ?>" <?= in_array($code, $scopes, true) ? 'checked' : '' ?> class="rounded text-blue-600 focus:ring-blue-500">
                            <?= e($label) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="bg-blue-600 text-white px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-700 transition">更新權限</button>
                </form>
            </details>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
