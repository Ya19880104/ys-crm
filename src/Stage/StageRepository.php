<?php

declare(strict_types=1);

namespace YangSheep\CRM\Stage;

use YangSheep\CRM\Core\Database;

class StageRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 依看板 ID 取得所有狀態
     */
    public function findByBoard(int $boardId): array
    {
        return $this->db->fetchAll(
            "SELECT s.*, b.name AS board_name,
                    (SELECT COUNT(*) FROM {prefix}cards c WHERE c.stage_id = s.id) AS card_count
             FROM {prefix}stages s
             JOIN {prefix}boards b ON s.board_id = b.id
             WHERE s.board_id = ?
             ORDER BY s.sort_order ASC, s.id ASC",
            [$boardId]
        );
    }

    /**
     * 取得所有狀態（依看板分群）
     */
    public function findAllGrouped(): array
    {
        $stages = $this->db->fetchAll(
            "SELECT s.*, b.name AS board_name,
                    (SELECT COUNT(*) FROM {prefix}cards c WHERE c.stage_id = s.id) AS card_count
             FROM {prefix}stages s
             JOIN {prefix}boards b ON s.board_id = b.id
             ORDER BY b.sort_order ASC, b.id ASC, s.sort_order ASC, s.id ASC"
        );

        $grouped = [];
        foreach ($stages as $stage) {
            $boardId = $stage['board_id'];
            if (!isset($grouped[$boardId])) {
                $grouped[$boardId] = [
                    'board_id'   => $boardId,
                    'board_name' => $stage['board_name'],
                    'stages'     => [],
                ];
            }
            $grouped[$boardId]['stages'][] = $stage;
        }

        return array_values($grouped);
    }

    /**
     * 依 ID 取得狀態
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT s.*, b.name AS board_name
             FROM {prefix}stages s
             JOIN {prefix}boards b ON s.board_id = b.id
             WHERE s.id = ?",
            [$id]
        );
    }

    /**
     * 建立狀態
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}stages
             (board_id, name, slug, color, sort_order, is_public, allow_drag_in, allow_drag_out, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $data['board_id'],
                $data['name'],
                $data['slug'] ?? '',
                $data['color'] ?? '#6b7280',
                $data['sort_order'] ?? 0,
                $data['is_public'] ?? 1,
                $data['allow_drag_in'] ?? 1,
                $data['allow_drag_out'] ?? 1,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新狀態
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE {prefix}stages
             SET name = ?, slug = ?, color = ?, sort_order = ?,
                 is_public = ?, allow_drag_in = ?, allow_drag_out = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $data['name'],
                $data['slug'] ?? '',
                $data['color'] ?? '#6b7280',
                $data['sort_order'] ?? 0,
                $data['is_public'] ?? 1,
                $data['allow_drag_in'] ?? 1,
                $data['allow_drag_out'] ?? 1,
                $id,
            ]
        );
    }

    /**
     * 刪除狀態
     */
    public function delete(int $id): void
    {
        $this->db->execute(
            "DELETE FROM {prefix}stages WHERE id = ?",
            [$id]
        );
    }

    /**
     * 計算該狀態下的卡片數量
     */
    public function countCards(int $stageId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}cards WHERE stage_id = ?",
            [$stageId]
        );
    }

    /**
     * 批次更新排序
     */
    public function reorder(array $order): void
    {
        foreach ($order as $id => $sortOrder) {
            $this->db->execute(
                "UPDATE {prefix}stages SET sort_order = ?, updated_at = NOW() WHERE id = ?",
                [(int) $sortOrder, (int) $id]
            );
        }
    }

    /**
     * 取得所有看板（下拉選單用）
     */
    public function getAllBoards(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name FROM {prefix}boards WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
    }
}
