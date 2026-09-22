<?php
/** @var \YangSheep\CRM\Core\View $view */
/** @var array $card */
/** @var array $logs */
/** @var array $comments */
$view->layout('admin');

use function YangSheep\CRM\Core\e;
use YangSheep\CRM\Core\BrandColorPolicy;

$priorityLabels = [
    'critical' => ['label' => '緊急', 'class' => 'bg-red-100 text-red-700'],
    'high'     => ['label' => '高',   'class' => 'bg-orange-100 text-orange-700'],
    'medium'   => ['label' => '中',   'class' => 'bg-blue-100 text-blue-700'],
    'low'      => ['label' => '低',   'class' => 'bg-gray-100 text-gray-500'],
];
$p = $priorityLabels[$card['priority']] ?? $priorityLabels['medium'];

$actionLabels = [
    'created'       => '建立了此卡片',
    'moved'         => '移動了卡片',
    'field_changed' => '更新了欄位',
];
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-6 flex items-center justify-between">
        <a href="/admin/cards" class="text-sm text-gray-500 hover:text-gray-700">&larr; 返回卡片列表</a>
        <div class="flex items-center gap-2">
            <a href="/admin/boards/<?= (int) $card['board_id'] ?>"
               class="px-3 py-1.5 text-sm border rounded-lg text-gray-600 hover:bg-gray-50">
                前往看板
            </a>
            <a href="/admin/cards/<?= (int) $card['id'] ?>/edit"
               class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                編輯
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 主要資訊 -->
        <div class="lg:col-span-2 space-y-6">
            <!-- 標題與描述 -->
            <div class="bg-white rounded-xl shadow-sm border p-6">
                <div class="flex items-start justify-between mb-4">
                    <h3 class="text-xl font-bold text-gray-800"><?= e($card['title']) ?></h3>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $p['class'] ?>">
                        <?= $p['label'] ?>
                    </span>
                </div>

                <?php if (!empty($card['description'])): ?>
                    <div class="prose prose-sm max-w-none text-gray-600">
                        <?= nl2br(e($card['description'])) ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic">無描述</p>
                <?php endif; ?>
            </div>

            <!-- 工作備註 -->
            <div class="bg-white rounded-xl shadow-sm border p-6">
                <h4 class="text-lg font-semibold text-gray-800 mb-4">工作備註</h4>

                <!-- 新增備註表單 -->
                <form method="POST" action="/admin/cards/<?= (int) $card['id'] ?>/comments"
                      enctype="multipart/form-data" class="mb-6 border rounded-lg p-4 bg-gray-50">
                    <?= \YangSheep\CRM\Core\Csrf::field() ?>
                    <div class="space-y-3">
                        <textarea name="content" rows="3" required
                                  class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm px-4 py-3"
                                  placeholder="輸入工作備註..."></textarea>
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">備註日期</label>
                                <input type="datetime-local" name="noted_at"
                                       value="<?= date('Y-m-d\TH:i') ?>"
                                       class="rounded-lg border-gray-300 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">附件（圖片或影片）</label>
                                <input type="file" name="media" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mov,.mkv,.avi,.wmv"
                                       class="text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="text-xs text-gray-400 mt-1">圖片 5MB / 影片 1GB</p>
                            </div>
                            <button type="submit"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                新增備註
                            </button>
                        </div>
                    </div>
                </form>

                <!-- 備註列表 -->
                <?php if (empty($comments)): ?>
                    <p class="text-sm text-gray-400">尚無工作備註</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($comments as $comment): ?>
                        <div class="border rounded-lg p-4 bg-white">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-700"><?= e($comment['user_name'] ?? '匿名') ?></span>
                                    <span class="text-xs text-gray-400"><?= e($comment['noted_at']) ?></span>
                                </div>
                                <form method="POST"
                                      action="/admin/cards/<?= (int) $card['id'] ?>/comments/<?= (int) $comment['id'] ?>/delete"
                                      onsubmit="return confirm('確定要刪除此備註？')">
                                    <?= \YangSheep\CRM\Core\Csrf::field() ?>
                                    <button type="submit" class="text-xs text-gray-400 hover:text-red-600 dark:hover:text-red-400">刪除</button>
                                </form>
                            </div>
                            <div class="text-sm text-gray-600 whitespace-pre-wrap"><?= e($comment['content']) ?></div>
                            <?php if (!empty($comment['image_path'])):
                                $isVideo = ($comment['media_type'] ?? '') === 'video'
                                    || preg_match('/\.(mp4|webm|mov)$/i', $comment['image_path']);
                            ?>
                            <div class="mt-3">
                                <?php if ($isVideo): ?>
                                <video controls preload="metadata" class="max-w-md max-h-64 rounded-lg border shadow-sm">
                                    <source src="<?= e($comment['image_path']) ?>" type="<?= e($comment['media_type'] === 'video' ? 'video/mp4' : '') ?>">
                                </video>
                                <?php else: ?>
                                <a href="<?= e($comment['image_path']) ?>" target="_blank">
                                    <img src="<?= e($comment['image_path']) ?>" alt="附件"
                                         class="max-w-xs max-h-48 rounded-lg border shadow-sm hover:shadow-md transition">
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 歷程時間軸 -->
            <div class="bg-white rounded-xl shadow-sm border p-6">
                <h4 class="text-lg font-semibold text-gray-800 mb-4">變更歷程</h4>

                <?php if (empty($logs)): ?>
                    <p class="text-sm text-gray-400">尚無歷程記錄</p>
                <?php else: ?>
                    <div class="relative">
                        <!-- 時間軸線 -->
                        <div class="absolute left-4 top-0 bottom-0 w-px bg-gray-200"></div>

                        <div class="space-y-4">
                            <?php foreach ($logs as $log): ?>
                            <div class="relative flex gap-4 pl-10">
                                <!-- 時間軸圓點 -->
                                <div class="absolute left-2.5 top-1.5 w-3 h-3 rounded-full border-2 border-white
                                    <?= $log['action_type'] === 'created' ? 'bg-green-500' : ($log['action_type'] === 'moved' ? 'bg-blue-500' : 'bg-gray-400') ?>">
                                </div>

                                <div class="flex-1 pb-4">
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="font-medium text-gray-700"><?= e($log['user_name'] ?? '系統') ?></span>
                                        <span class="text-gray-500">
                                            <?= $actionLabels[$log['action_type']] ?? $log['action_type'] ?>
                                        </span>
                                    </div>

                                    <?php if ($log['field_name']): ?>
                                    <div class="mt-1 text-xs text-gray-400">
                                        <span class="font-mono"><?= e($log['field_name']) ?></span>:
                                        <?php if ($log['old_value'] !== null): ?>
                                            <span class="line-through text-red-600 dark:text-red-400"><?= e($log['old_value']) ?></span>
                                            <span class="mx-1">&rarr;</span>
                                        <?php endif; ?>
                                        <?php if ($log['new_value'] !== null): ?>
                                            <span class="text-green-600"><?= e($log['new_value']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="mt-1 text-xs text-slate-400"><?= e($log['created_at']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 側邊資訊 -->
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border p-6">
                <h4 class="text-sm font-semibold text-gray-500 uppercase mb-4">詳細資訊</h4>
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs text-gray-400">看板</dt>
                        <dd class="mt-0.5">
                            <a href="/admin/boards/<?= (int) $card['board_id'] ?>" class="text-sm text-blue-600 hover:underline">
                                <?= e($card['board_name']) ?>
                            </a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">狀態</dt>
                        <dd class="mt-0.5 flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full" style="background-color: <?= e(BrandColorPolicy::forDisplay($card['stage_color'])) ?>"></span>
                            <span class="text-sm"><?= e($card['stage_name']) ?></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">優先級</dt>
                        <dd class="mt-0.5">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $p['class'] ?>">
                                <?= $p['label'] ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">負責人</dt>
                        <dd class="mt-0.5 text-sm"><?= e($card['assignee_name'] ?? '未指定') ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">建立者</dt>
                        <dd class="mt-0.5 text-sm"><?= e($card['creator_name'] ?? '-') ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">來源</dt>
                        <dd class="mt-0.5 text-sm"><?= e($card['source'] ?? 'manual') ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">公開</dt>
                        <dd class="mt-0.5 text-sm"><?= ($card['is_public'] ?? 1) ? '是' : '否' ?></dd>
                    </div>
                    <div class="pt-3 border-t">
                        <dt class="text-xs text-gray-400">建立時間</dt>
                        <dd class="mt-0.5 text-sm text-gray-500"><?= e($card['created_at']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-400">更新時間</dt>
                        <dd class="mt-0.5 text-sm text-gray-500"><?= e($card['updated_at']) ?></dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
