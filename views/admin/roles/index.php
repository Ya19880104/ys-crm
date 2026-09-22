<?php
/**
 * 角色列表 + 權限矩陣
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array $_flash
 * @var array $roles
 * @var array $permissions
 * @var array $rolePermissions  [roleId => [permissionId, ...]]
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;

// 將權限依 group_name 分組
$permissionGroups = [];
foreach ($permissions as $perm) {
    $group = $perm['group_name'] ?? '其他';
    $permissionGroups[$group][] = $perm;
}

// 群組配色（分散在色環上，深色模式下可讀）
$groupColors = [
    'system'        => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    'user'          => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
    'role'          => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
    'board'         => 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800',
    'stage'         => 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800',
    'card'          => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600',
    'customer'      => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
    'customer_user' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
    'asset'         => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
    'job'           => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600',
    'quote'         => 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800',
    'payment'       => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    'recurring'     => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
    'invoice'       => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    'wish'          => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600',
    'api'           => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
    'audit'         => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600',
];
$defaultBadge = 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600';
?>

<div class="mb-6">
    <p class="text-gray-500 text-sm">管理角色及其對應的權限設定</p>
</div>

<!-- 角色卡片列表 -->
<div class="grid gap-6 mb-8">
    <?php foreach ($roles as $role): ?>
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-800"><?= e($role['name']) ?></h3>
                <?php if (!empty($role['description'])): ?>
                    <p class="text-sm text-gray-500 mt-1"><?= e($role['description']) ?></p>
                <?php endif; ?>
            </div>
            <a href="/admin/roles/<?= e((string) $role['id']) ?>/edit"
               class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                編輯權限
            </a>
        </div>

        <!-- 此角色的權限標籤 -->
        <div class="flex flex-wrap gap-2">
            <?php
            $rolePermIds = $rolePermissions[$role['id']] ?? [];
            $hasAny = false;
            foreach ($permissions as $perm):
                if (in_array($perm['id'], $rolePermIds)):
                    $hasAny = true;
            ?>
                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium border <?= $groupColors[$perm['group_name'] ?? ''] ?? $defaultBadge ?>">
                    <?= e($perm['name']) ?>
                </span>
            <?php
                endif;
            endforeach;
            if (!$hasAny):
            ?>
                <span class="text-sm text-gray-400">尚未設定權限</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 完整權限矩陣 -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="px-6 py-4 border-b bg-gray-50">
        <h3 class="font-semibold text-gray-800">權限矩陣總覽</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-6 py-3 font-medium text-gray-500 min-w-[200px]">權限</th>
                    <?php foreach ($roles as $role): ?>
                    <th class="text-center px-4 py-3 font-medium text-gray-500"><?= e($role['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($permissionGroups as $groupName => $groupPerms): ?>
                    <!-- 群組標題 -->
                    <tr class="bg-gray-50">
                        <td colspan="<?= count($roles) + 1 ?>" class="px-6 py-2 font-medium text-gray-600 text-xs uppercase tracking-wider">
                            <?= e($groupName) ?>
                        </td>
                    </tr>
                    <?php foreach ($groupPerms as $perm): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <div class="font-medium text-gray-700"><?= e($perm['name']) ?></div>
                            <div class="text-xs text-gray-400"><?= e($perm['code']) ?></div>
                        </td>
                        <?php foreach ($roles as $role): ?>
                        <td class="text-center px-4 py-3">
                            <?php if (in_array($perm['id'], $rolePermissions[$role['id']] ?? [])): ?>
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-slate-400 dark:bg-slate-700/40 dark:text-slate-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
