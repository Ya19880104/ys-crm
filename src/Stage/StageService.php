<?php

declare(strict_types=1);

namespace YangSheep\CRM\Stage;

use YangSheep\CRM\Core\BrandColorPolicy;

class StageService
{
    private StageRepository $repo;

    public function __construct()
    {
        $this->repo = new StageRepository();
    }

    /**
     * 取得看板的所有狀態
     */
    public function findByBoard(int $boardId): array
    {
        return $this->repo->findByBoard($boardId);
    }

    /**
     * 取得所有狀態（依看板分群）
     */
    public function findAllGrouped(): array
    {
        return $this->repo->findAllGrouped();
    }

    /**
     * 依 ID 取得狀態
     */
    public function findById(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    /**
     * 建立狀態
     */
    public function create(array $data): int
    {
        $data['color'] = BrandColorPolicy::normalize($data['color'] ?? null, '#6b7280');
        return $this->repo->create($data);
    }

    /**
     * 更新狀態
     */
    public function update(int $id, array $data): void
    {
        if (array_key_exists('color', $data)) {
            $data['color'] = BrandColorPolicy::normalize($data['color'], '#6b7280');
        }
        $this->repo->update($id, $data);
    }

    /**
     * 刪除狀態（有卡片時擋住）
     *
     * @throws \RuntimeException 有卡片時丟出例外
     */
    public function delete(int $id): void
    {
        $cardCount = $this->repo->countCards($id);
        if ($cardCount > 0) {
            throw new \RuntimeException("此狀態下尚有 {$cardCount} 張卡片，無法刪除");
        }
        $this->repo->delete($id);
    }

    /**
     * 批次更新排序
     *
     * @param array $order [id => sort_order]
     */
    public function reorder(array $order): void
    {
        $this->repo->reorder($order);
    }

    /**
     * 取得所有看板（下拉選單用）
     */
    public function getAllBoards(): array
    {
        return $this->repo->getAllBoards();
    }
}
