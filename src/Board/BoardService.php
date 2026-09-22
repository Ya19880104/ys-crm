<?php

declare(strict_types=1);

namespace YangSheep\CRM\Board;

class BoardService
{
    private BoardRepository $repo;

    public function __construct()
    {
        $this->repo = new BoardRepository();
    }

    /**
     * 取得所有看板
     */
    public function findAll(): array
    {
        return $this->repo->findAll();
    }

    /**
     * 依 ID 取得看板
     */
    public function findById(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    /**
     * 取得看板含 stages 和 cards（Kanban 視圖用）
     */
    public function findWithStagesAndCards(int $id): ?array
    {
        return $this->repo->findWithStagesAndCards($id);
    }

    /**
     * 建立看板
     */
    public function create(array $data): int
    {
        return $this->repo->create($data);
    }

    /**
     * 更新看板
     */
    public function update(int $id, array $data): void
    {
        $this->repo->update($id, $data);
    }

    /**
     * 取得儀表板統計資料
     */
    public function getDashboardStats(): array
    {
        return $this->repo->getDashboardStats();
    }

    /**
     * 取得看板的所有卡片（AJAX 用）
     */
    public function getCardsByBoard(int $boardId): array
    {
        return $this->repo->getCardsByBoard($boardId);
    }
}
