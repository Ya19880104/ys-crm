<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

class View
{
    private ?string $layout = null;
    private string $content = '';
    private array $sections = [];
    private ?string $currentSection = null;

    /**
     * 渲染模板
     *
     * @param string $template 模板路徑（相對於 views/，不含 .php）
     * @param array $data 傳入模板的資料
     * @return string 渲染後的 HTML
     */
    public function render(string $template, array $data = []): string
    {
        // 路徑穿越防護
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new \RuntimeException("無效的模板路徑: {$template}");
        }

        $templatePath = VIEWS_PATH . '/' . $template . '.php';

        // 確認解析後的路徑仍在 VIEWS_PATH 內
        $realPath = realpath($templatePath);
        $realViews = realpath(VIEWS_PATH);
        if ($realPath === false || !str_starts_with($realPath, $realViews)) {
            throw new \RuntimeException("模板不存在: {$template}");
        }

        // 提取資料為變數（EXTR_SKIP 防止覆蓋既有變數）
        extract($data, EXTR_SKIP);

        // $view 指向自身，讓模板可以呼叫 $view->layout() 等方法
        $view = $this;

        ob_start();
        require $templatePath;
        $this->content = ob_get_clean();

        // 若有設定 layout，渲染 layout
        if ($this->layout !== null) {
            // 路徑穿越防護
            if (str_contains($this->layout, '..') || str_contains($this->layout, "\0")) {
                throw new \RuntimeException("無效的 Layout 路徑: {$this->layout}");
            }

            $layoutPath = VIEWS_PATH . '/layouts/' . $this->layout . '.php';
            $realLayoutPath = realpath($layoutPath);
            $realViews = realpath(VIEWS_PATH);
            if ($realLayoutPath === false || !str_starts_with($realLayoutPath, $realViews)) {
                throw new \RuntimeException("Layout 不存在: {$this->layout}");
            }

            $content = $this->content;
            ob_start();
            require $layoutPath;
            return ob_get_clean();
        }

        return $this->content;
    }

    /**
     * 在模板中設定 layout
     */
    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    /**
     * 載入 partial 模板
     */
    public function partial(string $name, array $data = []): void
    {
        // 路徑穿越防護
        if (str_contains($name, '..') || str_contains($name, "\0")) {
            throw new \RuntimeException("無效的 Partial 路徑: {$name}");
        }

        $partialPath = VIEWS_PATH . '/partials/' . $name . '.php';
        if (!file_exists($partialPath)) {
            $partialPath = VIEWS_PATH . '/' . $name . '.php';
        }

        if (!file_exists($partialPath)) {
            throw new \RuntimeException("Partial 不存在: {$name}");
        }

        // 確認解析後的路徑仍在 VIEWS_PATH 內
        $realPath = realpath($partialPath);
        $realViews = realpath(VIEWS_PATH);
        if ($realPath === false || !str_starts_with($realPath, $realViews)) {
            throw new \RuntimeException("Partial 不存在: {$name}");
        }

        extract($data, EXTR_SKIP);
        $view = $this;
        require $partialPath;
    }

    /**
     * 開始一個 section（用於 layout 中的區塊）
     */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * 結束當前 section
     */
    public function endSection(): void
    {
        if ($this->currentSection !== null) {
            $this->sections[$this->currentSection] = ob_get_clean();
            $this->currentSection = null;
        }
    }

    /**
     * 輸出 section 內容（用於 layout）
     */
    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * 取得主內容（用於 layout）
     */
    public function getContent(): string
    {
        return $this->content;
    }
}

/**
 * 全域 escape 函數 — 防止 XSS
 */
/**
 * 靜態資源網址（帶版本參數）。
 *
 * 🔴 【為什麼需要】原本 CSS/JS 是以固定網址載入，沒有任何版本資訊。
 * 後果是：**改了樣式，使用者看到的仍是舊版**，除非他自己按強制重新整理。
 * 這在開發時特別惡毒 —— 樣式明明部署了、原始碼也對，畫面就是不動，
 * 於是查修方向會轉向「是不是 CSS 寫錯了」，而真正的原因是瀏覽器根本沒去拿新檔。
 *（2026-09-02 實測踩到兩次：一次量到假的「零差異」，一次以為新元件沒生效。）
 *
 * 版本取檔案的 mtime：檔案改了才換網址，沒改就繼續走快取 ——
 * 既不會失效過頭，也不會失效不足。
 *
 * @param string $path 以 / 開頭的公開路徑，例如 /assets/css/app.css
 */
function asset(string $path): string
{
    $version = null;

    // public_html 在 Structure B 下與 app 目錄同層；兩種佈署都試。
    foreach ([BASE_PATH . '/public_html' . $path, BASE_PATH . $path] as $candidate) {
        if (is_file($candidate)) {
            $version = (string) filemtime($candidate);
            break;
        }
    }

    // 找不到檔案就原樣輸出（不要因為快取參數而讓資源 404）。
    return $version === null ? $path : $path . '?v=' . $version;
}

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
