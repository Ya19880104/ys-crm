<?php

declare(strict_types=1);

namespace YangSheep\CRM\Asset;

use YangSheep\CRM\Core\Database;

/**
 * 客戶資產（網站 / 主機）共用輔助工具。
 *
 * 提供 Website 與 Hosting 模組共用的：
 * - 客戶下拉選項（id + display_name）。
 * - 到期月份下拉選項（依資料庫實際出現的到期月份動態產生 + 近 12 個月）。
 * - 逾期判斷（status 仍 active 但到期日已過今日）。
 * - 各種 ENUM 代碼 → 中文標籤對照。
 *
 * 純靜態無狀態；日期判斷一律以伺服器端為準。
 */
final class AssetHelper
{
    /** 網站案件類型代碼 → 中文標籤。 */
    public const WEBSITE_CASE_TYPES = [
        'build'               => '網站製作',
        'hosting'             => '代管',
        'maintenance_hosting' => '維護＋代管',
        'maintenance'         => '僅維護',
    ];

    /** 主機類型代碼 → 中文標籤。 */
    public const HOSTING_TYPES = [
        'shared' => '虛擬主機',
        'vps'    => 'VPS',
    ];

    /** 資產狀態代碼 → 中文標籤（網站 / 主機共用）。 */
    public const ASSET_STATUSES = [
        'active'     => '啟用中',
        'expired'    => '已到期',
        'terminated' => '終止',
    ];

    /**
     * 客戶下拉選項（全部客戶，依名稱排序）。
     *
     * @return array<int, array{id: int, display_name: string}>
     */
    public static function customerOptions(): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT id, display_name FROM {prefix}customers ORDER BY display_name ASC"
        );
    }

    /**
     * 判斷資產是否「逾期」：狀態仍為 active 但到期日（含當日不算逾期）已早於今日。
     * 用於列表視覺標示；不變更實際 status（翻轉由 cron 處理）。
     *
     * @param string      $status   資產目前狀態
     * @param string|null $dueDate  到期日（Y-m-d 或 null）
     */
    public static function isOverdue(string $status, ?string $dueDate): bool
    {
        if ($status !== 'active' || $dueDate === null || $dueDate === '') {
            return false;
        }

        $due = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDate, 0, 10));
        if ($due === false) {
            return false;
        }

        $today = new \DateTimeImmutable('today');
        return $due < $today;
    }

    /**
     * 判斷資產是否「即將到期」：狀態 active 且到期日落在今日起未來 N 天內（含今日）。
     * 用於列表視覺提醒（橘色），與逾期（紅色）互斥。
     *
     * @param string      $status      資產目前狀態
     * @param string|null $dueDate     到期日（Y-m-d 或 null）
     * @param int         $withinDays  視為即將到期的天數門檻
     */
    public static function isExpiringSoon(string $status, ?string $dueDate, int $withinDays = 30): bool
    {
        if ($status !== 'active' || $dueDate === null || $dueDate === '') {
            return false;
        }

        $due = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDate, 0, 10));
        if ($due === false) {
            return false;
        }

        $today = new \DateTimeImmutable('today');
        if ($due < $today) {
            return false; // 已逾期，交給 isOverdue
        }

        $threshold = $today->modify("+{$withinDays} days");
        return $due <= $threshold;
    }

    /**
     * 到期月份下拉選項（value=YYYY-MM => label=YYYY年MM月）。
     *
     * 合併兩個來源並去重排序：
     * 1) 指定資料表中實際出現的到期月份（涵蓋過去與未來，使用者可選到任何有資料的月份）。
     * 2) 今日起未來 12 個月（即使尚無資料也能預先篩選）。
     *
     * @param string $table      不含前綴的資料表名（'customer_websites' | 'customer_hosting'）
     * @param string $dateColumn 到期日欄位名（'contract_end' | 'end_date'）
     * @return array<string, string> 以 YYYY-MM 為鍵、中文月份為值，降冪（新月在前）
     */
    public static function dueMonthOptions(string $table, string $dateColumn): array
    {
        // 白名單：避免欄位/表名注入（不經參數綁定，故嚴格限制）
        $allowed = [
            'customer_websites' => ['contract_end'],
            'customer_hosting'  => ['end_date'],
        ];
        if (!isset($allowed[$table]) || !in_array($dateColumn, $allowed[$table], true)) {
            return [];
        }

        $months = [];

        // 來源 1：DB 實際出現的月份
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT DISTINCT DATE_FORMAT(`{$dateColumn}`, '%Y-%m') AS ym
             FROM {prefix}{$table}
             WHERE `{$dateColumn}` IS NOT NULL
             ORDER BY ym DESC"
        );
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $months[$ym] = true;
            }
        }

        // 來源 2：未來 12 個月
        $cursor = new \DateTimeImmutable('first day of this month');
        for ($i = 0; $i < 12; $i++) {
            $months[$cursor->format('Y-m')] = true;
            $cursor = $cursor->modify('+1 month');
        }

        // 排序（新月在前）並轉中文標籤
        $keys = array_keys($months);
        rsort($keys); // 字典序對 YYYY-MM 等同時間序

        $options = [];
        foreach ($keys as $ym) {
            [$y, $m] = explode('-', $ym);
            $options[$ym] = "{$y}年{$m}月";
        }

        return $options;
    }
}
