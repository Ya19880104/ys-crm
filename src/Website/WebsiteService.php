<?php

declare(strict_types=1);

namespace YangSheep\CRM\Website;

use YangSheep\CRM\Customer\CustomerRepository;

/**
 * 客戶網站資產領域服務層。
 *
 * 規則：
 * - 建立 / 更新前驗證客戶存在（assigned customer 必須有效）。
 * - 依 case_type 正規化維護日期：非「含維護」類型（build / hosting）清空維護起訖。
 * - markExpired() 委派 repository，供未來到期 cron 使用（本階段不自動排程）。
 */
class WebsiteService
{
    private WebsiteRepository $repository;
    private CustomerRepository $customerRepository;

    /** 含維護期間的案件類型（會保留 maintenance_start/maintenance_end）。 */
    private const MAINTENANCE_CASE_TYPES = ['maintenance_hosting', 'maintenance'];

    public function __construct()
    {
        $this->repository = new WebsiteRepository();
        $this->customerRepository = new CustomerRepository();
    }

    /**
     * 分頁查詢網站資產列表。
     *
     * @param array{customer_id?: int|string, status?: string, due_month?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->repository->findAll($page, $perPage, $filters);
    }

    /**
     * 取得單一網站資產。
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * 取得指定客戶的所有網站資產（供客戶內頁 tab / 主機關聯下拉）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->repository->findByCustomer($customerId);
    }

    /**
     * 建立網站資產。
     *
     * @return int 新資產 ID
     */
    public function create(array $data): int
    {
        $this->assertCustomerExists((int) ($data['customer_id'] ?? 0));
        $data = $this->normalizeMaintenance($data);

        return $this->repository->insert($data);
    }

    /**
     * 更新網站資產。
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('網站資產不存在。');
        }

        if (array_key_exists('customer_id', $data)) {
            $this->assertCustomerExists((int) $data['customer_id']);
        }

        $data = $this->normalizeMaintenance($data);
        $this->repository->update($id, $data);
    }

    /**
     * 刪除網站資產。
     */
    public function delete(int $id): void
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('網站資產不存在。');
        }

        $this->repository->delete($id);
    }

    /**
     * 網站資產總數。
     */
    public function count(): int
    {
        return $this->repository->count();
    }

    /**
     * 將逾期（合約已過但仍 active）的網站資產翻為 expired。
     * 供未來到期 cron（P4-6）呼叫；本模組階段不自動執行。
     *
     * @return int 翻轉筆數
     */
    public function markExpired(): int
    {
        return $this->repository->markExpired();
    }

    /**
     * 確認客戶存在，否則拋出例外。
     */
    private function assertCustomerExists(int $customerId): void
    {
        if ($customerId <= 0 || $this->customerRepository->findById($customerId) === null) {
            throw new \RuntimeException('指定的客戶不存在。');
        }
    }

    /**
     * 依 case_type 正規化維護日期：
     * 非「含維護」類型清空 maintenance_start/maintenance_end（避免殘留無意義資料）。
     * 僅在 data 帶有 case_type 時處理。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeMaintenance(array $data): array
    {
        if (!array_key_exists('case_type', $data)) {
            return $data;
        }

        if (!in_array($data['case_type'], self::MAINTENANCE_CASE_TYPES, true)) {
            $data['maintenance_start'] = null;
            $data['maintenance_end']   = null;
        }

        return $data;
    }
}
