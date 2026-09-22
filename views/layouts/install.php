<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= \YangSheep\CRM\Core\e($title ?? '系統安裝') ?> - YS CRM</title>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-900 text-gray-200">
    <div class="max-w-3xl mx-auto py-10 px-4">
        <!-- Logo / 標題 -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-white">YS CRM</h1>
            <p class="text-gray-400 mt-2">系統安裝精靈</p>
        </div>

        <!-- 步驟進度條 -->
        <?php
        $steps = [
            1 => '環境檢測',
            2 => '資料庫',
            3 => '建立資料表',
            4 => '初始資料',
            5 => '管理員',
            6 => '基本設定',
            7 => '完成',
        ];
        $currentStep = $currentStep ?? 1;
        ?>
        <div class="flex items-center justify-center mb-10 gap-1">
            <?php foreach ($steps as $num => $label): ?>
                <?php
                $class = 'bg-slate-700 text-slate-400';
                if ($num < $currentStep) $class = 'bg-cyan-400 text-slate-900';
                if ($num === $currentStep) $class = 'bg-blue-500 text-white';
                ?>
                <div class="flex items-center">
                    <div class="flex flex-col items-center">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold <?= $class ?>">
                            <?php if ($num < $currentStep): ?>
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <?php else: ?>
                                <?= $num ?>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs mt-1 <?= $num === $currentStep ? 'text-blue-400' : 'text-gray-500' ?>"><?= $label ?></span>
                    </div>
                    <?php if ($num < 7): ?>
                        <div class="w-8 h-0.5 mx-1 <?= $num < $currentStep ? 'bg-cyan-400' : 'bg-gray-600' ?>"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 主要內容 -->
        <div class="bg-slate-800 rounded-xl shadow-xl border border-slate-700 p-8">
            <?= $content ?>
        </div>

        <!-- 頁尾 -->
        <div class="text-center mt-6 text-gray-500 text-sm">
            &copy; <?= date('Y') ?> YANGSHEEP DESIGN
        </div>
    </div>
</body>
</html>
