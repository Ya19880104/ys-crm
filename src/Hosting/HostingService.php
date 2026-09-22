<?php

declare(strict_types=1);

namespace YangSheep\CRM\Hosting;

use YangSheep\CRM\Customer\CustomerRepository;
use YangSheep\CRM\Website\WebsiteRepository;

/**
 * 客戶主機資產領域服務層。
 *
 * 規則：
 * - 建立 / 更新前驗證客戶存在。
 * - related_website_id 一致性：若有指定，該網站必須屬於同一客戶；否則視為未關聯（清為 null）。
 *   （DB 無法跨欄位約束，故於此強制；亦防越權把別的客戶網站關聯進來。）
 * - markExpired() 委派 repository，供未來到期 cron 使用（本階段不自動排程）。
 */
class HostingService
{
    private HostingRepository $repository;
    private CustomerRepository $customerRepository;
    private WebsiteRepository $websiteRepository;

    public function __construct()
    {
        $this->repository = new HostingRepository();
        $this->customerRepository = new CustomerRepository();
        $this->websiteRepository = new WebsiteRepository();
    }

    /**
     * 分頁查詢主機資產列表。
     *
     * @param array{customer_id?: int|string, status?: string, due_month?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->repository->findAll($page, $perPage, $filters);
    }

    /**
     * 取得單一主機資產。
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * 取得指定客戶的所有主機資產（供客戶內頁 tab）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->repository->findByCustomer($customerId);
    }

    /**
     * 建立主機資產。
     *
     * @return int 新資產 ID
     */
    public function create(array $data): int
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $this->assertCustomerExists($customerId);

        $data['related_website_id'] = $this->resolveRelatedWebsite(
            $customerId,
            $data['related_website_id'] ?? null
        );

        return $this->repository->insert($data);
    }

    /**
     * 更新主機資產。
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('主機資產不存在。');
        }

        // 目標客戶：以本次 data 為主，否則沿用既有
        $customerId = array_key_exists('customer_id', $data)
            ? (int) $data['customer_id']
            : (int) $existing['customer_id'];

        if (array_key_exists('customer_id', $data)) {
            $this->assertCustomerExists($customerId);
        }

        if (array_key_exists('related_website_id', $data)) {
            $data['related_website_id'] = $this->resolveRelatedWebsite(
                $customerId,
                $data['related_website_id']
            );
        }

        $this->repository->update($id, $data);
    }

    /**
     * 刪除主機資產。
     */
    public function delete(int $id): void
    {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('主機資產不存在。');
        }

        $this->repository->delete($id);
    }

    /**
     * 主機資產總數。
     */
    public function count(): int
    {
        return $this->repository->count();
    }

    /**
     * 將逾期（租用已過但仍 active）的主機資產翻為 expired。
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
     * 解析關聯網站：
     * - null / 0 → null（未關聯）。
     * - 指定 id 但網站不存在或不屬於該客戶 → null（拒絕越權關聯，靜默忽略而非報錯，
     *   因為前端下拉理應已限定同客戶，此為防線）。
     * - 合法且同客戶 → 回傳該 id。
     *
     * @param int          $customerId 主機所屬客戶
     * @param mixed        $websiteId  表單傳入的關聯網站 id
     */
    private function resolveRelatedWebsite(int $customerId, mixed $websiteId): ?int
    {
        $websiteId = (int) ($websiteId ?? 0);
        if ($websiteId <= 0) {
            return null;
        }

        $website = $this->websiteRepository->findById($websiteId);
        if ($website === null || (int) $website['customer_id'] !== $customerId) {
            return null;
        }

        return $websiteId;
    }
}
