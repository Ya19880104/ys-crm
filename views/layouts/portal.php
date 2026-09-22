<?php
/**
 * 客戶 Portal 版面（客戶專區）。
 *
 * 與後台 admin 版面分離：客戶導覽列（總覽/報價/付款/資產/聯絡人/團隊/卡片/個人資料/登出），
 * 不含任何後台功能或後台資料。深藍雙主題（與全站一致）；nav 依登入者 scope 動態顯示
 * （member 僅看到被授予的區塊；owner 全顯示，含團隊管理）。
 *
 * @var \YangSheep\CRM\Core\View $view
 * @var string   $content
 * @var string   $title
 * @var string   $_csrf
 * @var array    $_flash
 * @var array    $_customer   CustomerGuard::context()（id/customer_id/role/display_name/scopes）
 * @var callable $_can        fn(string $scope): bool — 後端授權判斷（nav 顯示用）
 */

use function YangSheep\CRM\Core\e;

$me = $_customer ?? [];
$can = $_can ?? static fn (string $s): bool => false;
$displayName = (string) ($me['display_name'] ?? '客戶');
$isOwner = (string) ($me['role'] ?? '') === 'owner';

$currentPath = $_SERVER['REQUEST_URI'] ?? '';
if (str_starts_with($currentPath, '/index.php')) {
    $currentPath = substr($currentPath, strlen('/index.php')) ?: '/';
}
$currentPath = parse_url($currentPath, PHP_URL_PATH) ?: '/';

// 導覽項目：[url, label, icon(path d), scope(null=總是顯示)]
$nav = [
    ['url' => '/portal',                 'label' => '總覽',       'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'scope' => null, 'exact' => true],
    ['url' => '/portal/quotes',          'label' => '我的報價單', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'scope' => 'quotes'],
    ['url' => '/portal/payments',        'label' => '付款記錄',   'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z', 'scope' => 'payments'],
    ['url' => '/portal/assets',          'label' => '主機與網站', 'icon' => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01', 'scope' => 'assets'],
    ['url' => '/portal/contacts',        'label' => '聯絡人',     'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-3-6.7', 'scope' => 'contacts'],
    ['url' => '/portal/payment-methods', 'label' => '付款卡片',   'icon' => 'M3 10h18M7 15h1m-4 4h16a1 1 0 001-1V6a1 1 0 00-1-1H4a1 1 0 00-1 1v12a1 1 0 001 1z', 'scope' => 'payment_methods'],
    ['url' => '/portal/team',            'label' => '團隊與子帳號','icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'scope' => 'team'],
    ['url' => '/portal/profile',         'label' => '個人資料',   'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'scope' => null],
];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($_csrf ?? '') ?>">
    <title><?= e($title ?? '客戶專區') ?> · 客戶專區</title>
    <script>
        (function () {
            try {
                var m = document.cookie.match(/(?:^|;\s*)ys_theme=(dark|light)/);
                var t = m ? m[1] : (localStorage.getItem('ys_theme') || 'light');
                if (t === 'dark') { document.documentElement.classList.add('dark'); }
            } catch (e) {}
        })();
    </script>
    <?php require VIEWS_PATH . '/partials/tailwind-config.php'; ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/ys-tokens.css') ?>">
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/app.css') ?>">
    <style>[x-cloak]{display:none!important;}</style>
</head>
<body class="ys-control-scope bg-slate-50 dark:bg-surface-dark min-h-screen text-slate-800 dark:text-slate-100 transition-colors"
      x-data="{
          sidebarOpen: window.innerWidth >= 768,
          get isMobile() { return window.innerWidth < 768 },
          get drawerHidden() { return this.isMobile && !this.sidebarOpen },
          openSidebar() { this.sidebarOpen = true; this.$nextTick(() => this.$refs.sidebarClose?.focus()); },
          closeSidebar() { this.sidebarOpen = false; this.$nextTick(() => this.$refs.sidebarToggle?.focus()); },
          dark: document.documentElement.classList.contains('dark')
      }"
      @keydown.escape.window="if (isMobile && sidebarOpen) closeSidebar()"
      @resize.window="if (window.innerWidth >= 768 && !sidebarOpen) sidebarOpen = true">
    <div class="flex min-h-screen relative">

        <!-- 手機遮罩 -->
        <div x-show="sidebarOpen" @click="closeSidebar()"
             x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/50 z-20 md:hidden" style="display:none;"></div>

        <!-- 側邊欄 -->
        <?php /* 抽屜關閉時用 inert 讓離屏連結退出 tab 順序（理由見 layouts/admin.php）。 */ ?>
        <aside id="ys-portal-sidebar"
               class="w-64 bg-navy-900 dark:bg-navy-950 text-white flex-shrink-0 flex flex-col fixed md:sticky top-0 h-screen z-30 border-r border-white/5 transition-transform duration-200 ease-in-out"
               :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0 md:-ml-64'"
               :inert="drawerHidden"
               :aria-hidden="drawerHidden ? 'true' : 'false'">
            <div class="h-16 px-5 border-b border-white/10 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-light to-brand-accent flex items-center justify-center text-white font-bold text-sm flex-shrink-0">羊</span>
                    <h1 class="text-base font-bold tracking-wide truncate">客戶專區</h1>
                </div>
                <button type="button" x-ref="sidebarClose" @click="closeSidebar()"
                        class="md:hidden ys-touch-icon text-slate-400 hover:text-white" aria-label="關閉側邊選單">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <nav class="p-4 space-y-1 flex-1 overflow-y-auto">
                <?php foreach ($nav as $item):
                    // scope 把關：null=總是顯示；'team'=僅 owner；其餘依 $can。
                    if ($item['scope'] !== null && !$can($item['scope'])) {
                        continue;
                    }
                    $active = !empty($item['exact'])
                        ? ($currentPath === $item['url'])
                        : str_starts_with($currentPath, $item['url']);
                ?>
                <a href="<?= e($item['url']) ?>"
                   class="relative flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                          before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-5 before:w-1 before:rounded-r before:bg-brand-accent
                          <?= $active ? 'bg-brand-light/90 text-white shadow-sm before:opacity-100' : 'text-slate-300 hover:bg-white/10 hover:text-white before:opacity-0' ?>">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= e($item['icon']) ?>"/></svg>
                    <?= e($item['label']) ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="p-4 border-t border-white/10 flex-shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center text-xs font-semibold text-slate-200 flex-shrink-0">
                            <?= e(mb_substr($displayName, 0, 1)) ?>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm text-slate-200 truncate"><?= e($displayName) ?></p>
                            <?php /* 這裡是「淺色主題下的深色側欄」，不能用主題感知的 slate-400（淺色時會取深值）。 */ ?>
                            <p class="text-[11px] text-slate-300"><?= $isOwner ? '主帳號' : '子帳號' ?></p>
                        </div>
                    </div>
                    <form method="POST" action="/portal/logout">
                        <input type="hidden" name="_csrf_token" value="<?= e($_csrf ?? '') ?>">
                        <button type="submit" class="text-slate-400 hover:text-red-600 dark:hover:text-red-400 text-sm whitespace-nowrap ml-2">登出</button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- 主內容 -->
        <main class="flex-1 min-w-0 overflow-auto">
            <header class="bg-white dark:bg-navy-topbar shadow-sm border-b border-slate-200 dark:border-surface-border px-4 sm:px-6 h-16 flex items-center justify-between sticky top-0 z-10 transition-colors">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" x-ref="sidebarToggle"
                            @click="sidebarOpen ? closeSidebar() : openSidebar()"
                            class="ys-touch-icon text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
                            aria-label="切換側邊選單"
                            aria-controls="ys-portal-sidebar"
                            :aria-expanded="sidebarOpen ? 'true' : 'false'">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h2 class="text-lg sm:text-xl font-semibold text-slate-800 dark:text-slate-100 truncate"><?= e($title ?? '客戶專區') ?></h2>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button"
                            @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); document.cookie = 'ys_theme=' + (dark ? 'dark' : 'light') + '; path=/; max-age=31536000; samesite=lax'; try { localStorage.setItem('ys_theme', dark ? 'dark' : 'light'); } catch (e) {}"
                            class="ys-touch-icon rounded-lg border border-slate-200 dark:border-surface-border text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition"
                            aria-label="切換主題">
                        <svg x-show="dark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        <svg x-show="!dark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    </button>
                </div>
            </header>

            <?php if (!empty($_flash['success'])): ?>
            <div class="mx-4 sm:mx-6 mt-4 bg-blue-50 dark:bg-blue-500/10 border border-blue-200 dark:border-blue-500/30 text-blue-700 dark:text-blue-300 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
                <div class="flex justify-between items-center"><span><?= e($_flash['success']) ?></span><button @click="show = false" class="text-blue-500 hover:text-blue-700">&times;</button></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($_flash['info'])): ?>
            <div class="mx-4 sm:mx-6 mt-4 bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-surface-border text-slate-700 dark:text-slate-300 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
                <div class="flex justify-between items-center"><span><?= e($_flash['info']) ?></span><button @click="show = false" class="text-slate-400 hover:text-slate-600">&times;</button></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($_flash['error'])): ?>
            <div class="mx-4 sm:mx-6 mt-4 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg" x-data="{ show: true }" x-show="show">
                <div class="flex justify-between items-center"><span><?= e($_flash['error']) ?></span><button @click="show = false" class="text-red-600 dark:text-red-400 hover:text-red-700">&times;</button></div>
            </div>
            <?php endif; ?>

            <div class="p-4 sm:p-6">
                <?= $content ?>
            </div>
        </main>
    </div>
</body>
</html>
