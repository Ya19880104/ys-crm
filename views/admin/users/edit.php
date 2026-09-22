<?php
/**
 * 編輯使用者表單
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string $_csrf
 * @var array $_flash
 * @var array $editUser 被編輯的使用者
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
        <div class="mb-6 pb-4 border-b">
            <h3 class="text-lg font-semibold text-gray-800">編輯使用者：<?= e($editUser['username'] ?? '') ?></h3>
            <p class="text-sm text-gray-500 mt-1">建立於 <?= e($editUser['created_at'] ?? '') ?></p>
        </div>

        <!-- enctype 不可省：沒有它瀏覽器不會送出檔案，且不會有任何錯誤提示 -->
        <form method="POST" action="/admin/users/<?= e((string) $editUser['id']) ?>" class="space-y-5"
              enctype="multipart/form-data"
              x-data="{ preview: '', removing: false }">
            <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">

            <!-- 頭像 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">頭像</label>
                <div class="flex items-center gap-4">
                    <!-- 目前頭像 / 即時預覽 -->
                    <div class="flex-shrink-0">
                        <template x-if="preview">
                            <img :src="preview" alt="預覽" class="ys-avatar-img" style="width:64px;height:64px;">
                        </template>
                        <div x-show="!preview" :class="removing ? 'opacity-40' : ''">
                            <?php
                            $avatarPath = (string) ($editUser['avatar_path'] ?? '');
                            $size       = 64;
                            $alt        = (string) ($editUser['display_name'] ?? '');
                            require VIEWS_PATH . '/partials/avatar.php';
                            ?>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <input type="file" id="avatar" name="avatar"
                               accept="image/jpeg,image/png,image/webp"
                               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''; removing = false"
                               class="block w-full text-sm text-gray-600
                                      file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0
                                      file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100 file:cursor-pointer">
                        <p class="text-xs text-gray-400 mt-1.5">JPG / PNG / WebP，2MB 以內。建議正方形，會自動裁切為圓形。</p>

                        <?php if (trim((string) ($editUser['avatar_path'] ?? '')) !== ''): ?>
                        <label class="inline-flex items-center gap-1.5 mt-2 text-xs text-gray-500 cursor-pointer">
                            <input type="checkbox" name="remove_avatar" value="1" x-model="removing"
                                   class="rounded border-gray-300">
                            移除頭像，改用預設圖案
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 帳號（唯讀） -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">帳號</label>
                <input type="text" value="<?= e($editUser['username'] ?? '') ?>" disabled
                       class="w-full px-4 py-2.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 text-sm">
            </div>

            <!-- 顯示名稱 -->
            <div>
                <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">顯示名稱 <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="text" id="display_name" name="display_name" required
                       value="<?= e($editUser['display_name'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- Email -->
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-600 dark:text-red-400">*</span></label>
                <input type="email" id="email" name="email" required
                       value="<?= e($editUser['email'] ?? '') ?>"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>

            <!-- 密碼（選填） -->
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">新密碼</label>
                <input type="password" id="password" name="password"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="留空表示不修改密碼">
            </div>

            <!-- 確認密碼 -->
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">確認新密碼</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                       placeholder="留空表示不修改密碼">
            </div>

            <!-- 角色 -->
            <div>
                <label for="role_id" class="block text-sm font-medium text-gray-700 mb-1">角色 <span class="text-red-600 dark:text-red-400">*</span></label>
                <select id="role_id" name="role_id" required
                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">請選擇角色</option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e((string) $role['id']) ?>"
                            <?= ((int) ($editUser['role_id'] ?? 0) === (int) $role['id']) ? 'selected' : '' ?>>
                        <?= e($role['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 狀態資訊 -->
            <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600 space-y-1">
                <p>狀態：
                    <?php if (($editUser['status'] ?? '') === 'active'): ?>
                        <span class="text-green-600 font-medium">啟用中</span>
                    <?php else: ?>
                        <span class="text-gray-500 font-medium">已停用</span>
                    <?php endif; ?>
                </p>
                <p>最後登入：<?= e($editUser['last_login_at'] ?? '尚未登入') ?></p>
            </div>

            <!-- 按鈕 -->
            <div class="flex items-center gap-3 pt-4 border-t">
                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                    儲存變更
                </button>
                <a href="/admin/users"
                   class="px-6 py-2.5 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-100 transition">
                    取消
                </a>
            </div>
        </form>
    </div>
</div>
