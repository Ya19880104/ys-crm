<?php
/**
 * 工作卡片表單（create / edit 共用）。
 * 含封面圖上傳，故 enctype=multipart/form-data。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var string $action          表單送出目標 URL
 * @var string $submitLabel     送出按鈕文字
 * @var array  $columns         [{id, name, color, ...}]
 * @var array  $customers       [{id, display_name}]
 * @var array  $assignees       [{id, display_name}]
 * @var array  $priorityLabels  priority => 中文
 * @var array  $job             編輯時的資料（新增時為空陣列）
 * @var int    $presetColumn    新增時預選欄位 id（0 = 不預選，採第一欄）
 * @var int    $presetCustomer  新增時預選客戶 id（0 = 非客戶工作）
 */

use function YangSheep\CRM\Core\e;

$job            = $job ?? [];
$presetColumn   = (int) ($presetColumn ?? 0);
$presetCustomer = (int) ($presetCustomer ?? 0);

$selColumn   = (int) ($job['column_id'] ?? $presetColumn);
$selCustomer = (int) ($job['customer_id'] ?? $presetCustomer);
$selAssignee = (int) ($job['assigned_to'] ?? 0);
$selPriority = (string) ($job['priority'] ?? 'medium');
$dueDate     = substr((string) ($job['due_date'] ?? ''), 0, 10);
$coverPath   = (string) ($job['cover_image_path'] ?? '');
?>

<style>[x-cloak]{display:none!important;}</style>

<form method="POST" action="<?= e($action) ?>" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

    <div class="bg-white dark:bg-surface-card rounded-xl shadow-sm border border-slate-200 dark:border-surface-border p-6">
        <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-5">工作資料</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 標題 -->
            <div class="md:col-span-2">
                <label for="title" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">標題 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="title" name="title" required maxlength="255"
                       value="<?= e($job['title'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="例如：官網改版第一階段">
            </div>

            <!-- 狀態欄位 -->
            <div>
                <label for="column_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">狀態欄位 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="column_id" name="column_id" required
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($columns as $col): ?>
                    <option value="<?= e((string) $col['id']) ?>" <?= $selColumn === (int) $col['id'] ? 'selected' : '' ?>>
                        <?= e($col['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 優先度 -->
            <div>
                <label for="priority" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">優先度</label>
                <select id="priority" name="priority"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <?php foreach ($priorityLabels as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $selPriority === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 客戶（可非客戶工作 + 快速新增客戶） -->
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="customer_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300">客戶</label>
                    <a href="/admin/customers/create" target="_blank" rel="noopener"
                       class="text-xs text-blue-600 dark:text-blue-400 hover:underline">＋ 快速新增客戶</a>
                </div>
                <select id="customer_id" name="customer_id"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">（非客戶工作 / 內部任務）</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= e((string) $c['id']) ?>" <?= $selCustomer === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-400 mt-1">新增客戶後請重新整理本頁以載入下拉選單。</p>
            </div>

            <!-- 負責人 -->
            <div>
                <label for="assigned_to" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">負責人</label>
                <select id="assigned_to" name="assigned_to"
                        class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">未指定</option>
                    <?php foreach ($assignees as $a): ?>
                    <option value="<?= e((string) $a['id']) ?>" <?= $selAssignee === (int) $a['id'] ? 'selected' : '' ?>>
                        <?= e($a['display_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 到期日 -->
            <div>
                <label for="due_date" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">到期日</label>
                <input type="date" id="due_date" name="due_date"
                       value="<?= e($dueDate) ?>"
                       class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 封面圖 -->
            <div class="md:col-span-2">
                <label for="cover_image" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">封面圖</label>
                <?php if ($coverPath !== ''): ?>
                <div class="mb-2">
                    <img src="<?= e($coverPath) ?>" alt="目前封面" class="h-24 rounded-lg object-cover border border-slate-200 dark:border-surface-border">
                    <p class="text-xs text-slate-400 mt-1">上傳新圖將取代目前封面。</p>
                </div>
                <?php endif; ?>
                <input type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/gif,image/webp"
                       class="w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-500/10 dark:file:text-blue-300 hover:file:bg-blue-100">
                <p class="text-xs text-slate-400 mt-1">支援 jpg / png / gif / webp，上限 5MB。</p>
            </div>

            <!-- 內容 -->
            <div class="md:col-span-2">
                <label for="description" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">內容</label>
                <textarea id="description" name="description" rows="4"
                          class="w-full px-4 py-2.5 border border-slate-300 dark:border-surface-border dark:bg-surface-dark dark:text-slate-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                          placeholder="工作說明 / 需求重點"><?= e($job['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
            <?= e($submitLabel ?? '儲存') ?>
        </button>
        <a href="/admin/jobs"
           class="px-6 py-2.5 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition">
            取消
        </a>
    </div>
</form>
