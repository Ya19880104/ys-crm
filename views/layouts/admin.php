<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= \YangSheep\CRM\Core\e($_csrf ?? '') ?>">
    <title><?= \YangSheep\CRM\Core\e($title ?? '後台管理') ?> - <?= \YangSheep\CRM\Core\e($_site_name ?? 'CRM') ?></title>
    <!-- 主題初始化（在繪製前套用，避免深色閃爍 FOUC） -->
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
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/kanban.css') ?>">
    <!-- 元件層置於最後：需覆寫部分 Tailwind utility 預設值 -->
    <link rel="stylesheet" href="<?= \YangSheep\CRM\Core\asset('/assets/css/ys-spike.css') ?>">
</head>
<?php
/*
 * 【行動版抽屜的鍵盤行為】
 * 側欄用 translate 移到畫面外，但「看不見」不等於「不存在」——
 * 離屏的連結仍留在 tab 順序裡，鍵盤使用者按 Tab 會掉進一個看不到的選單。
 * 因此在小螢幕且抽屜關閉時，用 inert 讓整個 <aside> 退出無障礙樹與焦點順序。
 * （inert 在現代瀏覽器皆已支援；不支援者行為與過去相同，不會更糟。）
 *
 * 另外補上 Escape 關閉與焦點歸還：抽屜是模態行為，打開後焦點要能回到觸發鈕。
 */
?>
<body class="min-h-screen transition-colors"
      x-data="{
          sidebarOpen: window.innerWidth >= 768,
          get isMobile() { return window.innerWidth < 768 },
          get drawerHidden() { return this.isMobile && !this.sidebarOpen },
          openSidebar() {
              this.sidebarOpen = true;
              this.$nextTick(() => this.$refs.sidebarClose?.focus());
          },
          closeSidebar() {
              this.sidebarOpen = false;
              this.$nextTick(() => this.$refs.sidebarToggle?.focus());
          },
          dark: document.documentElement.classList.contains('dark')
      }"
      @keydown.escape.window="if (isMobile && sidebarOpen) closeSidebar()"
      @resize.window="if (window.innerWidth >= 768 && !sidebarOpen) sidebarOpen = true">
    <div class="flex min-h-screen relative">

        <!-- 手機遮罩 -->
        <div x-show="sidebarOpen"
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="closeSidebar()"
             class="fixed inset-0 bg-black/50 z-20 md:hidden"
             style="display: none;"></div>

        <!-- 側邊欄（Spike：淺色主題白底、深色主題深底，由 ys-tokens 變數驅動） -->
        <aside id="ys-admin-sidebar"
               class="ys-sidebar flex-shrink-0 flex flex-col
                      fixed md:sticky top-0 h-screen z-30
                      transition-transform duration-200 ease-in-out"
               :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0 md:-ml-[270px]'"
               :inert="drawerHidden"
               :aria-hidden="drawerHidden ? 'true' : 'false'">
            <!-- 品牌區 -->
            <div class="ys-sidebar-brand justify-between flex-shrink-0">
                <div class="flex items-center gap-2.5 min-w-0">
                    <?php if (!empty($_site_logo)): ?>
                        <img src="<?= \YangSheep\CRM\Core\e($_site_logo) ?>" alt="<?= \YangSheep\CRM\Core\e($_site_name ?? 'CRM') ?> logo" class="h-8 max-w-[140px] object-contain flex-shrink-0">
                    <?php endif; ?>
                    <h1 class="text-base font-bold tracking-wide truncate ys-brand-name"><?= \YangSheep\CRM\Core\e($_site_name ?? 'CRM') ?></h1>
                </div>
                <button type="button" x-ref="sidebarClose" @click="closeSidebar()"
                        class="md:hidden ys-touch-icon ys-icon-btn" aria-label="關閉側邊選單">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <nav class="ys-sidebar-nav">
                <?php
                $currentPath = $_SERVER['REQUEST_URI'] ?? '';
                if (str_starts_with($currentPath, '/index.php')) {
                    $currentPath = substr($currentPath, strlen('/index.php')) ?: '/';
                }
                $dashboardActive = str_starts_with($currentPath, '/admin/dashboard');
                ?>
                <!-- 儀表板 -->
                <a href="/admin/dashboard" class="ys-nav-link<?= $dashboardActive ? ' is-active' : '' ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    儀表板
                </a>

                <?php
                $navSections = [
                    '業務' => [
                        ['url' => '/admin/customers',  'label' => '客戶管理', 'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-3-6.7'],
                        ['url' => '/admin/jobs',       'label' => '工作看板', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
                        ['url' => '/admin/quotes',     'label' => '報價單',   'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                        ['url' => '/admin/websites',   'label' => '網站與主機', 'icon' => 'M3 5a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 01-2 2H5a2 2 0 01-2-2V5zm0 8a2 2 0 012-2h14a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6zm4 3h.01M7 6h.01', 'active_paths' => ['/admin/websites', '/admin/hosting']],
                    ],
                    '帳務' => [
                        ['url' => '/admin/payments',   'label' => '付款記錄', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                        ['url' => '/admin/invoices',   'label' => '電子發票', 'icon' => 'M9 14h6m-6-4h6m4 8V6a2 2 0 00-2-2H7a2 2 0 00-2 2v12l3-2 2 2 2-2 2 2 3-2z'],
                        ['url' => '/admin/recurring',  'label' => '週期帳務', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                        ['url' => '/admin/notifications', 'label' => '通知記錄', 'icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
                    ],
                    '系統' => [
                        ['url' => '/admin/users',      'label' => '使用者',   'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                        ['url' => '/admin/roles',      'label' => '角色權限', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                        ['url' => '/admin/settings',   'label' => '系統設定', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
                        ['url' => '/admin/media',      'label' => '媒體檔案', 'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
                        ['url' => '/admin/audit-logs', 'label' => '稽核紀錄', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                        ['url' => '/admin/cron',       'label' => '排程設定', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ],
                ];
                foreach ($navSections as $sectionLabel => $items):
                ?>
                <div class="ys-nav-section"><?= $sectionLabel ?></div>
                <?php
                    foreach ($items as $item):
                        $activePaths = $item['active_paths'] ?? [$item['url']];
                        $isActive = false;
                        foreach ($activePaths as $ap) {
                            if (str_starts_with($currentPath, $ap)) { $isActive = true; break; }
                        }
                ?>
                <a href="<?= $item['url'] ?>" class="ys-nav-link<?= $isActive ? ' is-active' : '' ?>">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $item['icon'] ?>"/>
                    </svg>
                    <?= $item['label'] ?>
                </a>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <!-- 使用者資訊 -->
            <?php if (isset($_user)): ?>
            <div class="ys-sidebar-footer flex-shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <a href="/admin/profile"
                       class="flex items-center gap-2.5 min-w-0 ys-profile-link"
                       title="編輯個人資料與頭像">
                        <?php
                        $avatarPath = (string) ($_user['avatar_path'] ?? '');
                        $size       = 32;
                        $alt        = (string) ($_user['display_name'] ?? '');
                        require VIEWS_PATH . '/partials/avatar.php';
                        ?>
                        <span class="text-sm truncate ys-brand-name"><?= \YangSheep\CRM\Core\e($_user['display_name'] ?? '') ?></span>
                    </a>
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_csrf_token" value="<?= \YangSheep\CRM\Core\e($_csrf ?? '') ?>">
                        <button type="submit" class="ys-logout-btn text-sm whitespace-nowrap ml-2">登出</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </aside>

        <!-- 主要內容 -->
        <main class="flex-1 min-w-0 overflow-auto">
            <!-- 頂部列 -->
            <header class="ys-header">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" x-ref="sidebarToggle"
                            @click="sidebarOpen ? closeSidebar() : openSidebar()"
                            class="ys-touch-icon ys-icon-btn"
                            aria-label="切換側邊選單"
                            aria-controls="ys-admin-sidebar"
                            :aria-expanded="sidebarOpen ? 'true' : 'false'">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h2 class="text-lg sm:text-xl font-semibold truncate"><?= \YangSheep\CRM\Core\e($title ?? '後台管理') ?></h2>

                    <?php /* 標題後的狀態標記。用於「這一頁現在處於某種特殊模式」——
                             例如電子發票的測試環境。放在標題旁而不是做成整條橫幅，
                             是因為它需要「一直看得到」而不是「講一次就好」：
                             橫幅會被當成通知而被忽略，標記則跟著標題一起被讀到。 */ ?>
                    <?php if (!empty($titleBadge['label'])): ?>
                    <span class="inline-flex items-center gap-1.5 shrink-0 px-2.5 py-1 rounded-full text-xs font-medium <?= \YangSheep\CRM\Core\e($titleBadge['class'] ?? 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300') ?>"
                          <?php if (!empty($titleBadge['title'])): ?>title="<?= \YangSheep\CRM\Core\e($titleBadge['title']) ?>"<?php endif; ?>>
                        <span class="w-1.5 h-1.5 rounded-full bg-current opacity-80"></span>
                        <?= \YangSheep\CRM\Core\e($titleBadge['label']) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- 右側工具：通知鈴鐺 + 主題切換 -->
                <div class="flex items-center gap-2">
                    <?php
                    $_bellUser = \YangSheep\CRM\Core\Session::get('user');
                    $_bellUid  = is_array($_bellUser) ? (int) ($_bellUser['id'] ?? 0) : 0;
                    $_bellPerm = $_bellUid > 0 && (new \YangSheep\CRM\Role\RoleService())->hasPermission($_bellUid, 'notification.view');
                    $_unread   = $_bellPerm ? (new \YangSheep\CRM\Notification\NotificationRepository())->countUnread($_bellUid) : 0;
                    ?>
                    <?php if ($_bellPerm): ?>
                    <a href="/admin/notifications"
                       class="ys-touch-icon rounded-lg border border-slate-200 dark:border-surface-border text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition relative"
                       title="通知記錄<?= $_unread > 0 ? "（{$_unread} 則未讀）" : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <?php if ($_unread > 0): ?>
                        <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-bold leading-none px-1">
                            <?= $_unread > 99 ? '99+' : $_unread ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <button type="button" x-ref="themeToggle"
                            @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); document.cookie = 'ys_theme=' + (dark ? 'dark' : 'light') + '; path=/; max-age=31536000; samesite=lax'; try { localStorage.setItem('ys_theme', dark ? 'dark' : 'light'); } catch (e) {}"
                            class="ys-touch-icon rounded-lg border border-slate-200 dark:border-surface-border text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5 transition"
                            :title="dark ? '切換為淺色主題' : '切換為深色主題'"
                            aria-label="切換主題">
                        <!-- 太陽（深色模式時顯示，點擊回淺色） -->
                        <svg x-show="dark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <!-- 月亮（淺色模式時顯示，點擊進深色） -->
                        <svg x-show="!dark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Flash 訊息（留白與內容區對齊：同樣使用 --ys-content-gutter） -->
            <?php if (!empty($_flash['success'])): ?>
            <div class="ys-flash ys-flash-success" x-data="{ show: true }" x-show="show">
                <div class="flex justify-between items-center">
                    <span><?= \YangSheep\CRM\Core\e($_flash['success']) ?></span>
                    <button @click="show = false" aria-label="關閉">&times;</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($_flash['error'])): ?>
            <div class="ys-flash ys-flash-error" x-data="{ show: true }" x-show="show">
                <div class="flex justify-between items-center">
                    <span><?= \YangSheep\CRM\Core\e($_flash['error']) ?></span>
                    <button @click="show = false" aria-label="關閉">&times;</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- 頁面內容（全寬 + 5~10vw 留白，見設計規範 §4） -->
            <div class="ys-content ys-admin-content">
                <?= $content ?>
            </div>
        </main>
    </div>

    <script src="<?= \YangSheep\CRM\Core\asset('/assets/js/app.js') ?>"></script>
</body>
</html>
