<?php
/**
 * Tailwind 橋接層（共用）— Spike 設計系統
 *
 * 設計規範：docs/design/2026-08-16-ys-crm-admin-design-system.md
 *
 * 【為何抽成共用檔】
 * 原本每個 layout 各自帶一份 tailwind.config，結果 2026-08-16 的稽核發現：
 * 「全站禁綠」只在 admin layout 生效，portal / portal-auth / public-quote 的
 * config 沒有映射綠色，install 更是完全沒有 config —— 同一條規則散在六個檔案裡，
 * 漏掉任何一個都不會有錯誤訊息，只會在某個頁面悄悄冒出綠色。
 *
 * 所有 layout 一律 require 本檔，不再各自維護色票。
 *
 * 【必讀規則】
 *   1. 全站禁止綠色。emerald / green / teal / lime 全色階映射為青色。
 *   2. 色階必須映射完整（50–950）。只映射常用的幾階，等於留下未覆蓋的縫隙。
 *   3. 數值來自 Spike demo 實機量測，改動前先回 demo 站重新量測。
 *   4. **50–500 是「填色」階，600–950 是「文字」階。**
 *      50–200 當底色、300–500 當圖示與 badge 填色；600 以上必須對白底達 AA 4.5:1，
 *      因為 views 用的就是 Tailwind 的慣例（`text-*-600`／`bg-*-100`）。
 *      每一階後面都註記了對白底的實測對比值，改動時請一起重算。
 *
 * 【為何 600 不能等於品牌原色】（複審 2026-08-17）
 * 原本 blue.500 與 blue.600 同為 #0085db。那個值對白底只有 3.90:1，
 * 於是 `text-blue-600`（付款編號、連結）與 `bg-blue-600 text-white`（主要按鈕）
 * 全站都低於 AA。青／琥珀／紅三組也一樣：600 停在鮮豔的填色調上，
 * `text-green-600`（#2ab0d4）只有 2.54:1、`text-red-500`（#fb977d）只有 2.14:1。
 * 把 600 以上壓深之後，按鈕上的白字反而也一併達標（#0072bd 對白 5.07:1）。
 */
?>
<style>
/* ── slate-400 的主題感知值 ────────────────────────────────────────────
 * `text-slate-400` 在 views 中有 400+ 處裸用（沒有 dark: 變體），同一個 class
 * 要同時服務兩個主題：淺色時是「白底上的次要文字」，深色時是「深底上的次要文字」。
 * 固定值不可能兩邊都對 —— Spike 原本的 #aebcc3 對白底只有 1.95:1（付款明細的
 * <dt> 標籤、頁尾版權都因此幾乎看不見），但把它壓深，深色主題就換成它失敗。
 *
 * 用 CSS 變數讓同一個 utility 依主題取不同值，就不必逐頁加 dark: 變體。
 *
 * ⚠️ 變數定義在這裡而不是 assets/css/ys-tokens.css：那支只有 admin layout 載，
 * 其餘六個 layout 都沒載，變數會是 undefined → 顏色直接失效。本檔是七個 layout
 * 共用的唯一來源，色階與其變數必須放在一起。
 * ⚠️ 也因為是變數，slate-400 不可再搭配透明度修飾（text-slate-400/50）。
 *    目前全站沒有這種用法；要用請改用其他階。 */
/* ⚠️ 門檻要對**最亮的實際底色**算，不是對純白。auth／portal-auth 的頁面底是
 * slate-100 #e7ecf0 而非 #ffffff：#68737d 對白有 4.84:1，對 #e7ecf0 只剩 4.07:1
 * （實機量到的就是這個數字）。以下值兩種底色都過 AA。 */
:root      { --ys-scale-slate-400: #616b74; } /* 對白 5.44:1、對 #e7ecf0 4.57:1 */
.dark      { --ys-scale-slate-400: #aebcc3; } /* 對 #111c2d 8.78:1 */
</style>
<script src="https://cdn.tailwindcss.com"></script>
<script>
    // Spike 主色（#0085db）與青色（#46caeb）色階。
    // 括號內為對白底的實測對比；600 起皆 ≥ AA 4.5:1。
    const YS_BLUE = {
        50:  '#e8f5fd', 100: '#cfeafb', 200: '#9ad5f5', 300: '#5cbcee', 400: '#1f9fe4',
        500: '#0085db', // 品牌原色，填色用（對白 3.90:1，不可當文字色）
        600: '#0072bd', // 5.07:1
        700: '#005f9e', // 6.71:1
        800: '#004a7c', // 9.25:1
        900: '#00375d',
        950: '#00253e',
    };

    // 🔴 全站禁綠：所有「成功／正向」語意的綠色系一律映射為青色。
    // Spike 原版的 success 是 #4bd08b（綠），本專案不採用。
    const YS_CYAN = {
        50:  '#e1f7fc', 100: '#c7f0f9', 200: '#9ce4f5', 300: '#6bd6ef', 400: '#46caeb',
        500: '#46caeb', // 填色用（對白 1.92:1）
        600: '#1e788f', // 5.07:1
        700: '#1d6a80', // 6.13:1
        800: '#17576a', // 8.05:1
        900: '#134757',
        950: '#0d3340',
    };

    const YS_SLATE = {
        50:  '#f5f8fb', 100: '#e7ecf0', 200: '#e6ecf1', 300: '#e6ecf1',
        400: 'var(--ys-scale-slate-400)', // 主題感知，見上方 <style> 的說明
        500: '#5f6870', // 5.68:1（原 #707a82 實測只有 4.38:1，差一點就是差）
        600: '#4f575e', // 7.35:1
        700: '#495057', // 8.18:1
        800: '#111c2d', 900: '#111c2d',
        950: '#0b1420',
    };

    const YS_AMBER = {
        50:  '#fff6ea', 100: '#ffedd5', 200: '#fde4bb', 300: '#fbd39a', 400: '#f8c076',
        500: '#f8c076', // 填色用（對白 1.64:1）
        600: '#8a6a3a', // 5.00:1
        700: '#7d5c30', // 6.09:1
        800: '#654a27', // 8.20:1
        900: '#4a3620',
        950: '#2e2114',
    };

    const YS_RED = {
        50:  '#ffede9', 100: '#ffddd5', 200: '#fec7b9', 300: '#fdb098', 400: '#fb977d',
        500: '#fb977d', // 填色用（對白 2.14:1）
        600: '#a85940', // 5.04:1
        700: '#944e39', // 6.16:1
        800: '#874835', // 6.98:1
        900: '#6b3829', // 9.45:1
        950: '#4f2a1f',
    };

    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    blue: YS_BLUE,
                    brand: {
                        DEFAULT: '#0085db',
                        light:   '#0085db',
                        accent:  '#46caeb',
                        dark:    '#46caeb',
                    },
                    slate: YS_SLATE,
                    gray:  YS_SLATE,

                    // 禁綠：四個綠色系全部指向青色
                    emerald: YS_CYAN,
                    green:   YS_CYAN,
                    teal:    YS_CYAN,
                    lime:    YS_CYAN,
                    cyan:    YS_CYAN,

                    amber:  YS_AMBER,
                    yellow: YS_AMBER,
                    orange: YS_AMBER,

                    red:  YS_RED,
                    rose: YS_RED,

                    // 🔴 navy 是「深色表面」的語意色，**任何一階都不可以是白色**。
                    //
                    // 本檔曾經把 navy.900 設成 #ffffff（原意是讓後台側欄跟著 Spike 改成白底）。
                    // 但後台早就不用 navy 了，被改到的全是別的地方：
                    //   portal 側欄       bg-navy-900 text-white  → 白底白字
                    //   公開報價頁抬頭     bg-navy-900 text-white  → 白底白字
                    //   portal 登入標題    text-navy-900           → 白字（實測對比 1.2:1）
                    //   auth 背景漸層      from-navy-900           → 漸層起點變白
                    //
                    // 這是共用 token 的語意撞名：色票名稱沒變、掃描綠色也掃不出來，
                    // 但畫面已經壞了。要改某一頁的底色請改那一頁的 class，不要改色票語意。
                    navy: {
                        900: '#111c2d',
                        950: '#0b1420',
                        topbar: '#111c2d',
                    },
                    surface: {
                        dark:   '#111c2d',
                        card:   '#111c2d',
                        border: 'rgba(189, 200, 240, 0.2)',
                    },
                },
                // 卡片圓角 18px 是 Spike 最明顯的識別特徵
                borderRadius: {
                    sm: '8px', DEFAULT: '8px', md: '12px',
                    lg: '18px', xl: '18px', '2xl': '24px', '3xl': '24px',
                },
                // 全站只有兩階陰影，且帶藍調而非灰調
                boxShadow: {
                    sm:      '0 2px 6px 0 rgba(37, 83, 185, 0.1)',
                    DEFAULT: '0 2px 6px 0 rgba(37, 83, 185, 0.1)',
                    md:      '0 2px 6px 0 rgba(37, 83, 185, 0.1)',
                    lg:      '0 8px 24px 0 rgba(37, 83, 185, 0.16)',
                    xl:      '0 8px 24px 0 rgba(37, 83, 185, 0.16)',
                },
                fontFamily: {
                    sans: ['Plus Jakarta Sans', 'PingFang TC', 'Microsoft JhengHei',
                           'Noto Sans TC', '-apple-system', 'sans-serif'],
                },
            },
        },
    };
</script>
