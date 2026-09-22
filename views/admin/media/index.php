<?php
/**
 * 媒體檔案管理
 */
$view->layout('admin');
use function YangSheep\CRM\Core\e;
?>

<div class="mb-6">
    <p class="text-gray-500 text-sm">管理所有上傳的圖片與影片檔案</p>
</div>

<?php if (empty($files)): ?>
    <div class="bg-white rounded-xl shadow-sm border p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <p class="text-gray-500">目前沒有上傳的檔案</p>
    </div>
<?php else: ?>
    <div class="bg-white rounded-xl shadow-sm border overflow-hidden">
        <div class="px-6 py-3 bg-gray-50 border-b text-sm text-gray-500 flex items-center justify-between">
            <span>共 <?= count($files) ?> 個檔案</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 p-4">
            <?php foreach ($files as $file): ?>
            <div class="border rounded-lg overflow-hidden bg-gray-50 group">
                <!-- 預覽 -->
                <div class="aspect-video bg-gray-100 flex items-center justify-center overflow-hidden">
                    <?php if ($file['type'] === 'video'): ?>
                        <video preload="metadata" class="w-full h-full object-cover" muted>
                            <source src="<?= e($file['path']) ?>">
                        </video>
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div class="bg-black/50 rounded-full p-2">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                </svg>
                            </div>
                        </div>
                    <?php else: ?>
                        <img src="<?= e($file['path']) ?>" alt="<?= e($file['name']) ?>" class="w-full h-full object-cover">
                    <?php endif; ?>
                </div>

                <!-- 資訊 -->
                <div class="p-3">
                    <p class="text-xs text-gray-800 font-medium truncate" title="<?= e($file['name']) ?>"><?= e($file['name']) ?></p>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-xs text-gray-500">
                            <?= $file['type'] === 'video' ? '影片' : '圖片' ?>
                            &middot;
                            <?php
                                $size = $file['size'];
                                if ($size >= 1048576) echo round($size / 1048576, 1) . ' MB';
                                elseif ($size >= 1024) echo round($size / 1024, 1) . ' KB';
                                else echo $size . ' B';
                            ?>
                        </span>
                        <span class="text-xs text-gray-400"><?= date('m/d', $file['modified']) ?></span>
                    </div>

                    <?php if ($file['used_by']): ?>
                        <a href="/admin/cards/<?= (int) $file['used_by']['card_id'] ?>"
                           class="text-xs text-blue-600 hover:underline mt-1 block truncate">
                            <?= e($file['used_by']['card_title'] ?? '卡片 #' . $file['used_by']['card_id']) ?>
                        </a>
                    <?php else: ?>
                        <span class="text-xs text-gray-400 mt-1 block">未引用</span>
                    <?php endif; ?>

                    <!-- 操作 -->
                    <div class="flex items-center gap-2 mt-2 pt-2 border-t">
                        <a href="<?= e($file['path']) ?>" target="_blank" class="text-xs text-blue-600 hover:underline">開啟</a>
                        <form method="POST" action="/admin/media/delete" class="inline"
                              onsubmit="return confirm('確定要刪除此檔案嗎？')">
                            <?= \YangSheep\CRM\Core\Csrf::field() ?>
                            <input type="hidden" name="file_path" value="<?= e($file['path']) ?>">
                            <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">刪除</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
