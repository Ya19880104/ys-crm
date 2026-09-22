<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

use YangSheep\CRM\Core\Database;

class CardRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得卡片列表（支援篩選和分頁）
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['board_id'])) {
            $where[] = 's.board_id = ?';
            $params[] = (int) $filters['board_id'];
        }
        if (!empty($filters['stage_id'])) {
            $where[] = 'c.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'c.priority = ?';
            $params[] = $filters['priority'];
        }
        if (!empty($filters['assignee_id'])) {
            $where[] = 'c.assignee_id = ?';
            $params[] = (int) $filters['assignee_id'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
            $keyword = '%' . $filters['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT c.*, s.name AS stage_name, s.color AS stage_color,
                       b.name AS board_name, b.id AS board_id,
                       u.display_name AS assignee_name
                FROM {prefix}cards c
                JOIN {prefix}stages s ON c.stage_id = s.id
                JOIN {prefix}boards b ON s.board_id = b.id
                LEFT JOIN {prefix}users u ON c.assignee_id = u.id
                {$whereClause}
                ORDER BY c.updated_at DESC
                LIMIT {$perPage} OFFSET {$offset}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * 計算篩選後的總數
     */
    public function count(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['board_id'])) {
            $where[] = 's.board_id = ?';
            $params[] = (int) $filters['board_id'];
        }
        if (!empty($filters['stage_id'])) {
            $where[] = 'c.stage_id = ?';
            $params[] = (int) $filters['stage_id'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'c.priority = ?';
            $params[] = $filters['priority'];
        }
        if (!empty($filters['assignee_id'])) {
            $where[] = 'c.assignee_id = ?';
            $params[] = (int) $filters['assignee_id'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(c.title LIKE ? OR c.description LIKE ?)';
            $keyword = '%' . $filters['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             JOIN {prefix}boards b ON s.board_id = b.id
             {$whereClause}",
            $params
        );
    }

    /**
     * 依 ID 取得卡片（含關聯資訊）
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT c.*, s.name AS stage_name, s.color AS stage_color,
                    s.board_id, s.allow_drag_in, s.allow_drag_out,
                    b.name AS board_name,
                    u.display_name AS assignee_name,
                    creator.display_name AS creator_name
             FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             JOIN {prefix}boards b ON s.board_id = b.id
             LEFT JOIN {prefix}users u ON c.assignee_id = u.id
             LEFT JOIN {prefix}users creator ON c.created_by = creator.id
             WHERE c.id = ?",
            [$id]
        );
    }

    /**
     * 建立卡片
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}cards
             (board_id, stage_id, title, description, priority, assignee_id, is_public, source, sort_order, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $data['board_id'],
                $data['stage_id'],
                $data['title'],
                $data['description'] ?? '',
                $data['priority'] ?? 'medium',
                $data['assignee_id'] ?: null,
                $data['is_public'] ?? 1,
                $data['source'] ?? 'manual',
                $data['sort_order'] ?? 0,
                $data['created_by'] ?? null,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新卡片
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE {prefix}cards
             SET stage_id = ?, title = ?, description = ?, priority = ?,
                 assignee_id = ?, is_public = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $data['stage_id'],
                $data['title'],
                $data['description'] ?? '',
                $data['priority'] ?? 'medium',
                $data['assignee_id'] ?: null,
                $data['is_public'] ?? 1,
                $data['sort_order'] ?? 0,
                $id,
            ]
        );
    }

    /**
     * 刪除卡片
     */
    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM {prefix}cards WHERE id = ?", [$id]);
    }

    /**
     * 更新卡片的 stage 和排序（拖拉用）
     */
    public function updateStageAndOrder(int $id, int $stageId, int $sortOrder): void
    {
        $this->db->execute(
            "UPDATE {prefix}cards SET stage_id = ?, sort_order = ?, updated_at = NOW() WHERE id = ?",
            [$stageId, $sortOrder, $id]
        );
    }

    /**
     * 依 ID 取得狀態
     */
    public function getStageById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}stages WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * 取得所有看板（下拉選單用）
     */
    public function getAllBoards(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name FROM {prefix}boards WHERE is_active = 1 ORDER BY sort_order ASC"
        );
    }

    /**
     * 取得所有使用者（下拉選單用）
     */
    public function getAllUsers(): array
    {
        return $this->db->fetchAll(
            "SELECT id, display_name FROM {prefix}users WHERE status = 'active' ORDER BY display_name ASC"
        );
    }

    /**
     * 取得所有狀態（依看板分群，下拉選單用）
     */
    public function getAllStages(): array
    {
        return $this->db->fetchAll(
            "SELECT s.id, s.name, s.board_id, b.name AS board_name
             FROM {prefix}stages s
             JOIN {prefix}boards b ON s.board_id = b.id
             ORDER BY b.sort_order ASC, s.sort_order ASC"
        );
    }
}
