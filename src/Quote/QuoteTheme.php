<?php

declare(strict_types=1);

namespace YangSheep\CRM\Quote;

use YangSheep\CRM\Core\BrandColorPolicy;
use YangSheep\CRM\Setting\SettingService;

/**
 * 公開報價單的外觀（配色與品牌顯示方式）。
 *
 * 【為何抽出來】原本整組色票寫死在 views/public/quote/show.php 的 CSS 變數裡
 * （米色／褐色系）。那對「不同客戶、不同品牌」完全沒有彈性，換一次要改 CSS。
 *
 * 這裡把它變成「預設幾組 + 可自訂」，並把**驗證**放在一起 ——
 * 色碼是會被塞進 style 屬性的值，不能直接把使用者輸入寫進去。
 */
final class QuoteTheme
{
    /**
     * 內建配色。每組定義的是報價單用到的 CSS 變數。
     *
     * @var array<string, array{label: string, vars: array<string, string>}>
     */
    public const PRESETS = [
        'warm' => [
            'label' => '暖米色',
            'vars'  => [
                'doc-text'        => '#2a2a2a',
                'doc-muted'       => '#6b6258',
                'doc-line'        => '#d8c9b6',
                'doc-strong-line' => '#2a2a2a',
                'doc-brand'       => '#6b4f30',
                'doc-brand-2'     => '#7a5a36',
                'doc-rail'        => '#f7f0e6',
                'doc-rail-line'   => '#d4c2aa',
                'doc-soft'        => '#faf7f2',
            ],
        ],
        'clean' => [
            'label' => '純白簡潔',
            'vars'  => [
                'doc-text'        => '#1f2933',
                'doc-muted'       => '#6b7480',
                'doc-line'        => '#e2e8f0',
                'doc-strong-line' => '#1f2933',
                'doc-brand'       => '#1f2933',
                'doc-brand-2'     => '#3d4855',
                'doc-rail'        => '#ffffff',
                'doc-rail-line'   => '#e2e8f0',
                'doc-soft'        => '#f8fafc',
            ],
        ],
        'slate' => [
            'label' => '冷灰',
            'vars'  => [
                'doc-text'        => '#22272e',
                'doc-muted'       => '#5f6870',
                'doc-line'        => '#d6dde4',
                'doc-strong-line' => '#22272e',
                'doc-brand'       => '#39424c',
                'doc-brand-2'     => '#4f5a66',
                'doc-rail'        => '#f2f5f8',
                'doc-rail-line'   => '#d6dde4',
                'doc-soft'        => '#f8fafc',
            ],
        ],
        'navy' => [
            'label' => '深藍',
            'vars'  => [
                'doc-text'        => '#1b2432',
                'doc-muted'       => '#5b6675',
                'doc-line'        => '#d4dce8',
                'doc-strong-line' => '#1b2432',
                'doc-brand'       => '#1f3a5f',
                'doc-brand-2'     => '#2c5282',
                'doc-rail'        => '#f2f6fb',
                'doc-rail-line'   => '#d4dce8',
                'doc-soft'        => '#f8fafc',
            ],
        ],
    ];

    public const BRAND_DISPLAYS = ['logo_and_name', 'logo_only', 'name_only'];

    private SettingService $settings;

    public function __construct(?SettingService $settings = null)
    {
        $this->settings = $settings ?? new SettingService();
    }

    /**
     * 產生要注入報價單的 CSS 變數宣告（已逐項驗證，可安全放進 <style>）。
     *
     * @return array{css: string, brand_display: string, preset: string}
     */
    public function resolve(): array
    {
        $g      = $this->settings->getGroup('quote_style');
        $preset = (string) ($g['quote_theme'] ?? 'warm');

        if (!isset(self::PRESETS[$preset]) && $preset !== 'custom') {
            $preset = 'warm';
        }

        $vars = self::PRESETS['warm']['vars'];

        if ($preset === 'custom') {
            // 自訂只覆寫三個「看得出差別」的變數，其餘沿用暖米色的中性值。
            // 讓使用者填九個色碼只會得到一份難看的配色 —— 也更容易填出對比不足的組合。
            $brand = self::sanitizeColor((string) ($g['quote_color_brand'] ?? ''));
            $rail  = self::sanitizeColor((string) ($g['quote_color_rail'] ?? ''));
            $line  = self::sanitizeColor((string) ($g['quote_color_line'] ?? ''));

            if ($brand !== null) {
                $vars['doc-brand']       = $brand;
                $vars['doc-brand-2']     = $brand;
                $vars['doc-strong-line'] = $brand;
            }
            if ($rail !== null) {
                $vars['doc-rail'] = $rail;
                $vars['doc-soft'] = $rail;
            }
            if ($line !== null) {
                $vars['doc-line']      = $line;
                $vars['doc-rail-line'] = $line;
            }
        } elseif (isset(self::PRESETS[$preset])) {
            $vars = self::PRESETS[$preset]['vars'];
        }

        $css = '';
        foreach ($vars as $name => $value) {
            $css .= '--' . $name . ': ' . $value . ';';
        }

        $display = (string) ($g['quote_brand_display'] ?? 'logo_and_name');
        if (!in_array($display, self::BRAND_DISPLAYS, true)) {
            $display = 'logo_and_name';
        }

        return ['css' => $css, 'brand_display' => $display, 'preset' => $preset];
    }

    /**
     * 只接受 #RGB / #RRGGBB。
     *
     * 🔴 這個值會被寫進 <style> 區塊。CSS 屬性值裡的分號可以結束宣告、
     * 大括號可以跳出規則 —— 直接把使用者輸入拼進去，等於開一條樣式注入。
     * 用白名單式的格式檢查（而非「移除危險字元」）：不合格就當作沒填，
     * 退回預設值，而不是嘗試把它修好。
     */
    public static function sanitizeColor(string $value): ?string
    {
        return BrandColorPolicy::normalizeCssHex($value);
    }
}
