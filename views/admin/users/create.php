<?php
/**
 * 新增使用者表單
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array $_flash
 * @var array $roles
 */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
?>

<div class="max-w-2xl">
    <!-- 返回連結 -->
    <div class="mb-6">
        <a href="/admin/users" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            返回使用者列表
        </a>
    </div>

    <!-- 表單卡片 -->
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <form method="POST" action="/admin/users" class="space-y-5">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

            <!-- 帳號 -->
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">帳號 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="username" name="username" required
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入帳號（至少 3 個字元）">
            </div>

            <!-- 顯示名稱 -->
            <div>
                <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">顯示名稱 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="display_name" name="display_name" required
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入顯示名稱">
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="email" id="email" name="email" required
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入 Email">
            </div>

            <!-- 密碼 -->
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">密碼 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="password" id="password" name="password" required
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請輸入密碼（至少 6 個字元）">
            </div>

            <!-- 確認密碼 -->
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">確認密碼 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="請再次輸入密碼">
            </div>

            <!-- 角色 -->
            <div>
                <label for="role_id" class="block text-sm font-medium text-gray-700 mb-1">角色 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="role_id" name="role_id" required
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">請選擇角色</option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e((string) $role['id']) ?>">
                        <?= e($role['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 按鈕 -->
            <div class="flex items-center gap-3 pt-4 border-t">
                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    建立使用者
                </button>
                <a href="/admin/users"
                   class="px-6 py-2.5 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-100 transition">
                    取消
                </a>
            </div>
        </form>
    </div>
</div>
