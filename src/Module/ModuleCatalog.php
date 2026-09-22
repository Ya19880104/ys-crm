<?php
declare(strict_types=1);

namespace YangSheep\CRM\Module;

use InvalidArgumentException;

/** Closed source inventory, NOT module authorization or an enable/disable registry. */
final class ModuleCatalog
{
    public static function definitions(): array
    {
        $entries = [
            ['core', '核心安全與系統', 'existing', true, [], ['src/Core/App.php', 'src/Core/Database.php']],
            ['crm.customers', '客戶與聯絡人', 'existing', true, ['core'], ['src/Customer/CustomerRepository.php']],
            ['crm.catalog', '產品目錄', 'planned', false, ['core'], []],
            ['content.portal', '首頁與公告入口', 'planned', false, ['core'], []],
            ['crm.tracking', '客戶紀錄與追蹤', 'planned', false, ['crm.customers'], []],
            ['work.jobs', '工作交付', 'existing', false, ['crm.customers'], ['src/Job/JobController.php']],
            ['collaboration', '團隊協作', 'planned', false, ['core'], []],
            ['documents.quotes', '報價與列印', 'existing', false, ['crm.customers'], ['src/Quote/QuoteService.php', 'views/public/quote/print.php']],
            ['finance.payments', '收付款', 'existing', false, ['documents.quotes'], ['src/Payment/PaymentService.php']],
            ['finance.einvoice', '電子發票', 'existing', false, ['finance.payments'], ['src/EInvoice/InvoiceService.php']],
            ['finance.recurring', '週期帳務', 'existing', false, ['documents.quotes', 'finance.payments'], ['src/Recurring/RecurringService.php', 'src/Portal/CustomerPaymentMethodRepository.php']],
            ['commerce.orders', '商業訂單', 'planned', false, ['crm.customers'], []],
            ['sales', '業務管理', 'planned', false, ['crm.customers'], []],
            ['marketing', '行銷與生日郵件', 'planned', false, ['crm.customers'], []],
            ['integration.api', '外部API與表單', 'planned', false, ['crm.customers'], []],
            ['integration.erp', 'YS ERP整合', 'planned', false, ['integration.api'], []],
            ['integration.hub', 'YS HUB更新', 'planned', false, ['core'], []],
            ['ys.services', '網站與主機服務', 'existing', false, ['crm.customers'], ['src/Website/WebsiteController.php', 'src/Hosting/HostingController.php']],
        ];
        $rows = [];
        foreach ($entries as [$id, $label, $stage, $required, $dependsOn, $sentinels]) {
            $rows[] = ['id'=>$id, 'label'=>$label, 'stage'=>$stage, 'required'=>$required,
                'depends_on'=>$dependsOn, 'sentinels'=>$sentinels];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        self::validate($rows);
        return $rows;
    }

    public static function validate(array $definitions): void
    {
        if ($definitions === [] || !array_is_list($definitions)) {
            self::reject();
        }
        $keys = ['id', 'label', 'stage', 'required', 'depends_on', 'sentinels'];
        $byId = [];
        foreach ($definitions as $row) {
            if (!is_array($row) || count($row) !== count($keys)
                || array_diff($keys, array_keys($row)) !== []) {
                self::reject();
            }
            if (!is_string($row['id'])
                || preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)*\z/', $row['id']) !== 1
                || isset($byId[$row['id']]) || !is_string($row['label']) || trim($row['label']) === ''
                || !in_array($row['stage'], ['existing', 'planned'], true) || !is_bool($row['required'])
                || !is_array($row['depends_on']) || !array_is_list($row['depends_on'])
                || !is_array($row['sentinels']) || !array_is_list($row['sentinels'])) {
                self::reject();
            }
            $dependencies = [];
            foreach ($row['depends_on'] as $dependency) {
                if (!is_string($dependency) || isset($dependencies[$dependency])) {
                    self::reject();
                }
                $dependencies[$dependency] = true;
            }
            if (($row['stage'] === 'planned' && $row['sentinels'] !== [])
                || ($row['stage'] === 'existing' && $row['sentinels'] === [])) {
                self::reject();
            }
            $paths = [];
            foreach ($row['sentinels'] as $path) {
                // Only fixed, portable relative file names. No traversal, wrappers or drive paths.
                if (!is_string($path)
                    || preg_match('~\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z~', $path) !== 1
                    || isset($paths[$path])) {
                    self::reject();
                }
                $paths[$path] = true;
            }
            $byId[$row['id']] = $row;
        }
        foreach ($byId as $row) {
            foreach ($row['depends_on'] as $dependency) {
                if (!isset($byId[$dependency])) { self::reject(); }
            }
        }
        $states = [];
        foreach (array_keys($byId) as $id) {
            self::visit($id, $byId, $states);
        }
    }

    private static function visit(string $id, array $byId, array &$states): void
    {
        if (($states[$id] ?? 0) === 1) { self::reject(); }
        if (($states[$id] ?? 0) === 2) { return; }
        $states[$id] = 1;
        foreach ($byId[$id]['depends_on'] as $dependency) {
            self::visit($dependency, $byId, $states);
        }
        $states[$id] = 2;
    }

    private static function reject(): never
    {
        // Catalog contents may become diagnostics; never interpolate invalid input.
        throw new InvalidArgumentException('Invalid module catalog.');
    }
}
