<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

use YangSheep\CRM\Core\Database;

class CardDragService
{
    private Database $db;
    private CardLogService $logService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->logService = new CardLogService();
    }

    /**
     * 拖拉移動卡片
     *
     * 1. 取得 card 和 user
     * 2. 檢查拖拉權限：drag_all 或 (drag_own + 是否為 assignee)
     * 3. 檢查目標 stage 的 allow_drag_in
     * 4. 檢查來源 stage 的 allow_drag_out
     * 5. 更新 stage_id 和 sort_order
     * 6. 記錄 card_log
     *
     * @throws \RuntimeException 權限不足或規則不符時丟出例外
     */
    public function move(int $cardId, int $targetStageId, int $newSortOrder, int $userId): void
    {
        // 取得卡片
        $card = $this->db->fetch(
            "SELECT c.*, s.allow_drag_out, s.id AS source_stage_id, s.name AS source_stage_name
             FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             WHERE c.id = ?",
            [$cardId]
        );

        if (!$card) {
            throw new \RuntimeException('卡片不存在');
        }

        // 取得使用者
        $user = $this->db->fetch(
            "SELECT u.*, r.slug AS role_slug
             FROM {prefix}users u
             LEFT JOIN {prefix}roles r ON u.role_id = r.id
             WHERE u.id = ?",
            [$userId]
        );

        if (!$user) {
            throw new \RuntimeException('使用者不存在');
        }

        // 從 role_permissions 關聯表取得實際權限
        $permRows = $this->db->fetchAll(
            "SELECT p.code FROM {prefix}role_permissions rp
             JOIN {prefix}permissions p ON rp.permission_id = p.id
             WHERE rp.role_id = ?",
            [$user['role_id']]
        );
        $user['permissions'] = array_column($permRows, 'code');

        // 檢查拖拉權限
        if (!$this->canDrag($user, $card)) {
            throw new \RuntimeException('您沒有拖拉此卡片的權限');
        }

        // 檢查來源 stage 的 allow_drag_out
        if (!$card['allow_drag_out']) {
            throw new \RuntimeException('此狀態不允許拖出卡片');
        }

        // 取得目標 stage
        $targetStage = $this->db->fetch(
            "SELECT * FROM {prefix}stages WHERE id = ?",
            [$targetStageId]
        );

        if (!$targetStage) {
            throw new \RuntimeException('目標狀態不存在');
        }

        // 檢查目標 stage 的 allow_drag_in
        if (!$targetStage['allow_drag_in']) {
            throw new \RuntimeException('目標狀態不允許拖入卡片');
        }

        // 更新卡片
        $this->db->execute(
            "UPDATE {prefix}cards SET stage_id = ?, sort_order = ?, updated_at = NOW() WHERE id = ?",
            [$targetStageId, $newSortOrder, $cardId]
        );

        // 記錄 card_log
        $this->logService->log(
            $cardId,
            $userId,
            'moved',
            'stage_id',
            (string) $card['source_stage_id'],
            (string) $targetStageId
        );
    }

    /**
     * 檢查使用者是否可以拖拉此卡片
     */
    public function canDrag(array $user, array $card): bool
    {
        // 解析使用者權限
        $permissions = [];
        if (!empty($user['permissions'])) {
            $permissions = is_string($user['permissions'])
                ? json_decode($user['permissions'], true) ?? []
                : (array) $user['permissions'];
        }

        // 管理員或具有 card.drag_all 權限
        if (($user['role_slug'] ?? '') === 'admin' || in_array('card.drag_all', $permissions, true)) {
            return true;
        }

        // 具有 card.drag_own 權限且是卡片的 assignee
        if (in_array('card.drag_own', $permissions, true)) {
            return (int) ($card['assignee_id'] ?? 0) === (int) $user['id'];
        }

        return false;
    }
}
