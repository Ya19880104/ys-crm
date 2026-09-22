<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

class CardService
{
    private CardRepository $repo;

    public function __construct()
    {
        $this->repo = new CardRepository();
    }

    /**
     * 取得卡片列表（支援篩選和分頁）
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->repo->findAll($filters, $page, $perPage);
    }

    /**
     * 依 ID 取得卡片（含關聯資訊）
     */
    public function findById(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    /**
     * 建立卡片
     */
    public function create(array $data): int
    {
        return $this->repo->create($data);
    }

    /**
     * 這位使用者能不能編輯這張卡片？
     *
     * 語意刻意與 CardDragService::canDrag() 一致：
     *   card.edit_all → 全部可編輯
     *   card.edit_own → 僅限自己是 assignee 的卡片
     *   兩者皆無     → 不可編輯
     *
     * 【為何需要這個方法】（複審 2026-08-17）
     * 卡片更新（HTML 的 POST /admin/cards/{id} 與 API 的 PATCH /api/cards/{id}）
     * 原本**完全沒有歸屬檢查**：拖拉有 canDrag() 把關，編輯卻沒有對應的東西。
     * 路由層放寬成 `edit_all|edit_own` 之後，若不補上這道，
     * 只有 edit_own 的角色就等於拿到了 edit_all —— 可以改任何人的卡片。
     *
     * @param array<string, mixed> $card
     */
    public function canEdit(int $userId, array $card): bool
    {
        if ($userId <= 0 || $card === []) {
            return false;
        }

        $roles = new \YangSheep\CRM\Role\RoleService();

        if ($roles->hasPermission($userId, 'card.edit_all')) {
            return true;
        }

        if ($roles->hasPermission($userId, 'card.edit_own')) {
            return (int) ($card['assignee_id'] ?? 0) === $userId;
        }

        return false;
    }

    /**
     * 更新卡片（自動記 log）
     */
    public function update(int $id, array $data, int $userId): void
    {
        $old = $this->repo->findById($id);
        $this->repo->update($id, $data);

        // 記錄變更歷程
        if ($old) {
            $logService = new CardLogService();
            $fields = ['title', 'description', 'priority', 'assignee_id', 'stage_id', 'is_public'];
            foreach ($fields as $field) {
                $oldVal = (string) ($old[$field] ?? '');
                $newVal = (string) ($data[$field] ?? '');
                if ($oldVal !== $newVal) {
                    $logService->log($id, $userId, 'field_changed', $field, $oldVal, $newVal);
                }
            }
        }
    }

    /**
     * 刪除卡片
     */
    public function delete(int $id): void
    {
        $this->repo->delete($id);
    }

    /**
     * 計算篩選後的總數
     */
    public function count(array $filters = []): int
    {
        return $this->repo->count($filters);
    }

    /**
     * 取得所有看板（下拉選單用）
     */
    public function getAllBoards(): array
    {
        return $this->repo->getAllBoards();
    }

    /**
     * 取得所有使用者（下拉選單用）
     */
    public function getAllUsers(): array
    {
        return $this->repo->getAllUsers();
    }

    /**
     * 取得所有狀態（下拉選單用）
     */
    public function getAllStages(): array
    {
        return $this->repo->getAllStages();
    }

    /**
     * 依 ID 取得狀態（含 board_id）
     */
    public function getStageById(int $id): ?array
    {
        return $this->repo->getStageById($id);
    }
}
