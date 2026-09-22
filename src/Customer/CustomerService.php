<?php

declare(strict_types=1);

namespace YangSheep\CRM\Customer;

use YangSheep\CRM\Core\Database;

/**
 * 客戶領域服務層。
 *
 * 規則：
 * - 建立客戶與其聯絡人於同一交易內（原子性）。
 * - 個人客戶（type=individual）若未填任何聯絡人，自動以客戶名建立 1 筆主要聯絡人。
 * - 公司客戶允許 0 聯絡人（可事後於內頁新增）。
 * - 永遠確保「至多一筆」主要聯絡人：若多筆勾選 primary，只保留第一筆。
 */
class CustomerService
{
    private CustomerRepository $repository;
    private CustomerContactRepository $contactRepository;

    public function __construct()
    {
        $this->repository = new CustomerRepository();
        $this->contactRepository = new CustomerContactRepository();
    }

    /**
     * 分頁查詢客戶列表。
     *
     * @param array{type?: string, status?: string, keyword?: string} $filters
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15, array $filters = []): array
    {
        return $this->repository->findAll($page, $perPage, $filters);
    }

    /**
     * 取得單一客戶（含負責人/建立者名稱）。
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * 取得單一客戶並附帶聯絡人。
     */
    public function findWithContacts(int $id): ?array
    {
        return $this->repository->findWithContacts($id);
    }

    /**
     * 建立客戶（含聯絡人），於單一交易內完成。
     *
     * @param array              $data     客戶欄位（type/display_name/tax_id/... + created_by）
     * @param array<int, array>  $contacts 聯絡人陣列（每筆含 name/role/phone/...）
     * @return int 新客戶 ID
     */
    public function create(array $data, array $contacts = []): int
    {
        $contacts = $this->prepareContacts($contacts, $data['type'] ?? 'individual', $data['display_name'] ?? '');

        $db = Database::getInstance();
        return $db->transaction(function () use ($data, $contacts): int {
            $customerId = $this->repository->insert($data);

            foreach ($contacts as $contact) {
                $this->contactRepository->insert($customerId, $contact);
            }

            return $customerId;
        });
    }

    /**
     * 更新客戶基本資料（不含聯絡人；聯絡人有獨立的新增/刪除操作）。
     */
    public function update(int $id, array $data): void
    {
        $customer = $this->repository->findById($id);
        if ($customer === null) {
            throw new \RuntimeException('客戶不存在。');
        }

        $this->repository->update($id, $data);
    }

    /**
     * 刪除客戶（聯絡人由 FK CASCADE 連帶刪除）。
     */
    public function delete(int $id): void
    {
        $customer = $this->repository->findById($id);
        if ($customer === null) {
            throw new \RuntimeException('客戶不存在。');
        }

        $this->repository->delete($id);
    }

    /**
     * 為既有客戶新增一筆聯絡人。
     *
     * @return int 新聯絡人 ID
     */
    public function addContact(int $customerId, array $data): int
    {
        $customer = $this->repository->findById($customerId);
        if ($customer === null) {
            throw new \RuntimeException('客戶不存在。');
        }

        // 若此筆要設為主要聯絡人，且已存在其他主要聯絡人，先取消既有 primary 旗標於單一交易內
        $setPrimary = !empty($data['is_primary']);

        $db = Database::getInstance();
        return $db->transaction(function () use ($customerId, $data, $setPrimary): int {
            if ($setPrimary) {
                $this->clearPrimaryFlag($customerId);
            }
            return $this->contactRepository->insert($customerId, $data);
        });
    }

    /**
     * 刪除聯絡人（限定所屬客戶）。
     *
     * @return bool 是否確實刪除
     */
    public function deleteContact(int $customerId, int $contactId): bool
    {
        $contact = $this->contactRepository->findById($contactId);
        if ($contact === null || (int) $contact['customer_id'] !== $customerId) {
            throw new \RuntimeException('聯絡人不存在或不屬於此客戶。');
        }

        $affected = $this->contactRepository->deleteForCustomer($contactId, $customerId);
        return $affected > 0;
    }

    /**
     * 客戶總數。
     */
    public function count(): int
    {
        return $this->repository->count();
    }

    /**
     * 整理聯絡人輸入：
     * - 過濾掉沒有名稱的空白列。
     * - 保證至多一筆 primary（多筆勾選只留第一筆）。
     * - 個人客戶若整理後為 0 筆，補一筆以客戶名為名的主要聯絡人。
     *
     * @param array<int, array> $contacts
     * @return array<int, array>
     */
    private function prepareContacts(array $contacts, string $type, string $displayName): array
    {
        $clean = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $name = trim((string) ($contact['name'] ?? ''));
            if ($name === '') {
                continue; // 略過未填名稱的空列
            }
            $contact['name'] = $name;
            $clean[] = $contact;
        }

        // 保證至多一筆 primary
        $primarySeen = false;
        foreach ($clean as &$contact) {
            if (!empty($contact['is_primary'])) {
                if ($primarySeen) {
                    $contact['is_primary'] = 0;
                } else {
                    $primarySeen = true;
                }
            }
        }
        unset($contact);

        // 個人客戶且無任何聯絡人 → 自動建立一筆主要聯絡人
        if ($clean === [] && $type === 'individual' && trim($displayName) !== '') {
            $clean[] = [
                'name'       => trim($displayName),
                'is_primary' => 1,
            ];
            $primarySeen = true;
        }

        // 若有聯絡人但無人標記 primary，將第一筆設為 primary
        if ($clean !== [] && !$primarySeen) {
            $clean[0]['is_primary'] = 1;
        }

        return $clean;
    }

    /**
     * 取消客戶現有所有聯絡人的 primary 旗標。
     */
    private function clearPrimaryFlag(int $customerId): void
    {
        $db = Database::getInstance();
        $db->execute(
            "UPDATE {prefix}customer_contacts SET is_primary = 0 WHERE customer_id = :customer_id",
            ['customer_id' => $customerId]
        );
    }
}
