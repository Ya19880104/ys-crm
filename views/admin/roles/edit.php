<?php
/**
 * 編輯角色權限
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array $_flash
 * @var array $role
 * @var array $permissions
 * @var array $rolePermissions  權限 ID 陣列
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

// 將權限依 group_name 分組
$permissionGroups = [];
foreach ($permissions as $perm) {
    $group = $perm['group_name'] ?? '其他';
    $permissionGroups[$group][] = $perm;
}
?>

<div class="max-w-3xl">
    <!-- 返回連結 -->
    <div class="mb-6">
        <a href="/admin/roles" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回角色列表
        </a>
    </div>

    <!-- 角色資訊 -->
    <div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800"><?= e($role['name'] ?? '') ?></h3>
        <?php if (!empty($role['description'])): ?>
            <p class="text-sm text-gray-500 mt-1"><?= e($role['description']) ?></p>
        <?php endif; ?>
    </div>

    <!-- 權限設定表單 -->
    <form method="POST" action="/admin/roles/<?= e((string) $role['id']) ?>">
        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

        <div class="space-y-6">
            <?php foreach ($permissionGroups as $groupName => $groupPerms): ?>
            <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
                <div class="px-6 py-4 border-b bg-gray-50">
                    <div class="flex items-center justify-between">
                        <h4 class="font-medium text-gray-700"><?= e($groupName) ?></h4>
                        <label class="flex items-center gap-2 text-sm text-gray-500 cursor-pointer"
                               x-data="{ toggleAll(group) { const boxes = document.querySelectorAll(`[data-group='${group}']`); const allChecked = Array.from(boxes).every(b => b.checked); boxes.forEach(b => b.checked = !allChecked); } }">
                            <button type="button"
                                    @click="toggleAll('<?= e($groupName) ?>')"
                                    class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                全選/取消
                            </button>
                        </label>
                    </div>
                </div>
                <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($groupPerms as $perm): ?>
                    <label class="flex items-start gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer transition">
                        <input type="checkbox"
                               name="permissions[]"
                               value="<?= e((string) $perm['id']) ?>"
                               data-group="<?= e($groupName) ?>"
                               <?= in_array($perm['id'], $rolePermissions) ? 'checked' : '' ?>
                               class="mt-0.5 w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <div>
                            <div class="text-sm font-medium text-gray-700"><?= e($perm['name']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($perm['code']) ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 按鈕 -->
        <div class="flex items-center gap-3 mt-6">
            <button type="submit"
                    class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                儲存權限設定
            </button>
            <a href="/admin/roles"
               class="px-6 py-2.5 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-100 transition">
                取消
            </a>
        </div>
    </form>
</div>
