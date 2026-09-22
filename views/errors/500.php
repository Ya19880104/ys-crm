<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - 伺服器錯誤</title>
    <?php /* 共用色票：錯誤頁原本自帶裸 CDN，不吃全站禁綠與對比設定。 */ ?>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="text-center px-6">
        <p class="text-6xl font-bold text-orange-400 mb-4">500</p>
        <h1 class="text-2xl font-semibold text-gray-800 mb-2">伺服器錯誤</h1>
        <p class="text-gray-500 mb-8">系統發生非預期的錯誤，請稍後再試。<br>若問題持續發生，請聯絡系統管理員。</p>
        <div class="flex items-center justify-center gap-4">
            <a href="/"
               class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 transition">
                返回首頁
            </a>
            <button onclick="location.reload()"
                    class="bg-white text-gray-700 px-5 py-2.5 rounded-lg text-sm font-medium border hover:bg-gray-50 transition">
                重新整理
            </button>
        </div>
    </div>
</body>
</html>
