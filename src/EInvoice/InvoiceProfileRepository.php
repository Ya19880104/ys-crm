<?php

declare(strict_types=1);

namespace YangSheep\CRM\EInvoice;

use YangSheep\CRM\Core\Database;

/**
 * 客戶常用發票資料存取層。
 */
class InvoiceProfileRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得客戶的預設發票資料。
     *
     * 【只認 is_default=1】刻意不做「沒有預設就取第一筆」的退讓：客戶可能存了多組抬頭
     * （例如個人與公司各一），取錯就是開出統編錯誤的發票，而發票開錯只能作廢重開。
     */
    public function findDefaultByCustomer(int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}invoice_profiles
             WHERE customer_id = :cid AND is_default = 1
             ORDER BY id ASC LIMIT 1",
            ['cid' => $customerId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findAllByCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {prefix}invoice_profiles WHERE customer_id = :cid ORDER BY is_default DESC, id ASC",
            ['cid' => $customerId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM {prefix}invoice_profiles WHERE id = :id", ['id' => $id]);
    }

    /**
     * 取得客戶主檔中可用於發票的基本資料。
     *
     * phone 優先取「主要聯絡人的手機」而非 customers.phone —— 後者常是公司市話，
     * 而 PayNow 的 BuyerPhone 只接受 09 開頭手機或空字串（市話會被 422 拒絕）。
     * 取得手機的唯一好處是會員載具歸戶與簡訊；取不到就留空，不影響發票效力。
     */
    public function findCustomerBasics(int $customerId): ?array
    {
        return $this->db->fetch(
            "SELECT c.display_name,
                    c.email,
                    c.tax_id,
                    c.address,
                    COALESCE(NULLIF(ct.mobile, ''), NULLIF(c.phone, ''), '') AS phone
             FROM {prefix}customers c
             LEFT JOIN {prefix}customer_contacts ct
                    ON ct.customer_id = c.id AND ct.is_primary = 1
             WHERE c.id = :cid
             LIMIT 1",
            ['cid' => $customerId]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(int $customerId, array $data): int
    {
        return $this->db->transaction(function () use ($customerId, $data): int {
            if (!$this->lockCustomer($customerId)) {
                throw new \InvalidArgumentException('找不到客戶。');
            }
            $isDefault = !empty($data['is_default']);
            if ($isDefault) {
                $this->clearDefault($customerId);
            }

            $this->db->execute(
                "INSERT INTO {prefix}invoice_profiles
                    (customer_id, label, profile_type, is_default, buyer_name, buyer_email, buyer_phone,
                     buyer_address, buyer_identifier, carrier_type, carrier_id_1, carrier_id_2, love_code, country)
                 VALUES
                    (:customer_id, :label, :profile_type, :is_default, :buyer_name, :buyer_email, :buyer_phone,
                     :buyer_address, :buyer_identifier, :carrier_type, :carrier_id_1, :carrier_id_2, :love_code, :country)",
                $this->normalize($customerId, $data, $isDefault)
            );

            return (int) $this->db->lastInsertId();
        });
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, int $customerId, array $data): bool
    {
        return (bool) $this->db->transaction(function () use ($id, $customerId, $data): bool {
            if (!$this->lockCustomer($customerId) || !$this->scopedTargetExists($id, $customerId)) {
                return false;
            }
            $isDefault = !empty($data['is_default']);
            if ($isDefault) {
                $this->clearDefault($customerId);
            }

            $params       = $this->normalize($customerId, $data, $isDefault);
            $params['id'] = $id;

            return $this->db->execute(
                "UPDATE {prefix}invoice_profiles SET
                    label = :label, profile_type = :profile_type, is_default = :is_default,
                    buyer_name = :buyer_name, buyer_email = :buyer_email, buyer_phone = :buyer_phone,
                    buyer_address = :buyer_address, buyer_identifier = :buyer_identifier,
                    carrier_type = :carrier_type, carrier_id_1 = :carrier_id_1, carrier_id_2 = :carrier_id_2,
                    love_code = :love_code, country = :country
                 WHERE id = :id AND customer_id = :customer_id",
                $params
            ) >= 0;
        });
    }

    public function delete(int $id, int $customerId): bool
    {
        return (bool) $this->db->transaction(function () use ($id, $customerId): bool {
            if (!$this->lockCustomer($customerId)) {
                return false;
            }
            return $this->db->execute(
                "DELETE FROM {prefix}invoice_profiles WHERE id = :id AND customer_id = :cid",
                ['id' => $id, 'cid' => $customerId]
            ) === 1;
        });
    }

    /**
     * 設為預設（同客戶其他筆一律取消）。
     */
    public function setDefault(int $id, int $customerId): bool
    {
        return (bool) $this->db->transaction(function () use ($id, $customerId): bool {
            if (!$this->lockCustomer($customerId) || !$this->scopedTargetExists($id, $customerId)) {
                return false;
            }
            $this->clearDefault($customerId);

            return $this->db->execute(
                "UPDATE {prefix}invoice_profiles SET is_default = 1 WHERE id = :id AND customer_id = :cid",
                ['id' => $id, 'cid' => $customerId]
            ) >= 0;
        });
    }

    // Every profile mutation locks the same existing parent first, including
    // create when no profile rows exist yet. The lock lives until commit.
    private function lockCustomer(int $customerId): bool
    {
        return $this->db->fetch(
            "SELECT id FROM {prefix}customers WHERE id = :cid FOR UPDATE",
            ['cid' => $customerId]
        ) !== null;
    }

    private function scopedTargetExists(int $id, int $customerId): bool
    {
        return $this->db->fetch(
            "SELECT id FROM {prefix}invoice_profiles WHERE id = :id AND customer_id = :cid FOR UPDATE",
            ['id' => $id, 'cid' => $customerId]
        ) !== null;
    }

    private function clearDefault(int $customerId): void
    {
        $this->db->execute(
            "UPDATE {prefix}invoice_profiles SET is_default = 0 WHERE customer_id = :cid AND is_default = 1",
            ['cid' => $customerId]
        );
    }

    /**
     * 欄位正規化：統編與愛心碼去除非數字（寫入與讀取端一致，避免比對失敗）。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize(int $customerId, array $data, bool $isDefault): array
    {
        return [
            'customer_id'      => $customerId,
            'label'            => mb_substr(trim((string) ($data['label'] ?? '')), 0, 100),
            'profile_type'     => in_array($data['profile_type'] ?? 'b2c', ['b2c', 'b2b', 'donate'], true)
                ? (string) $data['profile_type']
                : 'b2c',
            'is_default'       => $isDefault ? 1 : 0,
            'buyer_name'       => mb_substr(trim((string) ($data['buyer_name'] ?? '')), 0, 100),
            'buyer_email'      => mb_substr(trim((string) ($data['buyer_email'] ?? '')), 0, 190),
            'buyer_phone'      => mb_substr(trim((string) ($data['buyer_phone'] ?? '')), 0, 32),
            'buyer_address'    => mb_substr(trim((string) ($data['buyer_address'] ?? '')), 0, 255),
            'buyer_identifier' => (string) preg_replace('/\D+/', '', (string) ($data['buyer_identifier'] ?? '')),
            'carrier_type'     => mb_substr(trim((string) ($data['carrier_type'] ?? '')), 0, 16),
            'carrier_id_1'     => mb_substr(trim((string) ($data['carrier_id_1'] ?? '')), 0, 64),
            'carrier_id_2'     => mb_substr(trim((string) ($data['carrier_id_2'] ?? '')), 0, 64),
            'love_code'        => (string) preg_replace('/\D+/', '', (string) ($data['love_code'] ?? '')),
            'country'          => mb_substr(trim((string) ($data['country'] ?? 'TW')), 0, 8) ?: 'TW',
        ];
    }
}
