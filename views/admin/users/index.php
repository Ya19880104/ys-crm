<?php
/**
 * 使用者列表
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array $_flash
 * @var array $users
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var int $totalPages
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<!-- 頂部操作列 -->
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-gray-500 text-sm">共 <?= e((string) $total) ?> 位使用者</p>
    </div>
    <a href="/admin/users/create"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        新增使用者
    </a>
</div>

<!-- 使用者表格 -->
<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left px-6 py-3 font-medium text-gray-500">ID</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">帳號</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">顯示名稱</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">Email</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">角色</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">狀態</th>
                <th class="text-left px-6 py-3 font-medium text-gray-500">最後登入</th>
                <th class="text-right px-6 py-3 font-medium text-gray-500">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($users)): ?>
            <tr>
                <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                    尚無使用者資料
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4 text-gray-500"><?= e((string) $user['id']) ?></td>
                    <td class="px-6 py-4 font-medium text-gray-900"><?= e($user['username']) ?></td>
                    <td class="px-6 py-4 text-gray-700"><?= e($user['display_name']) ?></td>
                    <td class="px-6 py-4 text-gray-500"><?= e($user['email']) ?></td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <?= e($user['role_name'] ?? '無角色') ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($user['status'] === 'active'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                啟用
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                停用
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-gray-500 text-xs">
                        <?= e($user['last_login_at'] ?? '-') ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <a href="/admin/users/<?= e((string) $user['id']) ?>/edit"
                               class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                編輯
                            </a>
                            <?php if ($user['status'] === 'active'): ?>
                            <form method="POST"
                                  action="/admin/users/<?= e((string) $user['id']) ?>/destroy"
                                  onsubmit="return confirm('確定要停用此使用者？')">
                                <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 text-xs font-medium">
                                    停用
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- 分頁 -->
<?php if ($totalPages > 1): ?>
    <?php $view->partial('pagination', [
        'page'       => $page,
        'totalPages' => $totalPages,
        'baseUrl'    => '/admin/users',
    ]); ?>
<?php endif; ?>
