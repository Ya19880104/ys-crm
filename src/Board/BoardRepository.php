<?php

declare(strict_types=1);

namespace YangSheep\CRM\Board;

use YangSheep\CRM\Core\Database;

class BoardRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得所有看板
     */
    public function findAll(): array
    {
        return $this->db->fetchAll(
            "SELECT b.*, u.display_name AS creator_name,
                    (SELECT COUNT(*) FROM {prefix}cards c
                     JOIN {prefix}stages s ON c.stage_id = s.id
                     WHERE s.board_id = b.id) AS card_count
             FROM {prefix}boards b
             LEFT JOIN {prefix}users u ON b.created_by = u.id
             ORDER BY b.sort_order ASC, b.id DESC"
        );
    }

    /**
     * 依 ID 取得看板
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT b.*, u.display_name AS creator_name
             FROM {prefix}boards b
             LEFT JOIN {prefix}users u ON b.created_by = u.id
             WHERE b.id = ?",
            [$id]
        );
    }

    /**
     * 取得看板含 stages 和 cards
     */
    public function findWithStagesAndCards(int $id): ?array
    {
        $board = $this->findById($id);
        if (!$board) {
            return null;
        }

        $stages = $this->db->fetchAll(
            "SELECT * FROM {prefix}stages
             WHERE board_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$id]
        );

        foreach ($stages as &$stage) {
            $stage['cards'] = $this->db->fetchAll(
                "SELECT c.*, u.display_name AS assignee_name
                 FROM {prefix}cards c
                 LEFT JOIN {prefix}users u ON c.assignee_id = u.id
                 WHERE c.stage_id = ?
                 ORDER BY c.sort_order ASC, c.id ASC",
                [$stage['id']]
            );
        }
        unset($stage);

        $board['stages'] = $stages;
        return $board;
    }

    /**
     * 建立看板
     */
    public function create(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}boards (name, slug, type, description, is_active, is_public, sort_order, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $data['name'],
                $data['slug'] ?? '',
                $data['type'] ?? 'internal',
                $data['description'] ?? '',
                $data['is_active'] ?? 1,
                $data['is_public'] ?? 0,
                $data['sort_order'] ?? 0,
                $data['created_by'] ?? null,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新看板
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            "UPDATE {prefix}boards
             SET name = ?, type = ?, description = ?, is_active = ?, is_public = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ?",
            [
                $data['name'],
                $data['type'] ?? 'internal',
                $data['description'] ?? '',
                $data['is_active'] ?? 1,
                $data['is_public'] ?? 0,
                $data['sort_order'] ?? 0,
                $id,
            ]
        );
    }

    /**
     * 取得儀表板統計
     */
    public function getDashboardStats(): array
    {
        $totalBoards = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}boards"
        );

        $totalCards = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}cards"
        );

        $inProgressCards = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             WHERE s.slug = 'in-progress' OR s.name LIKE '%進行中%'"
        );

        $recentCards = $this->db->fetchAll(
            "SELECT c.*, s.name AS stage_name, s.color AS stage_color,
                    b.name AS board_name, u.display_name AS assignee_name
             FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             JOIN {prefix}boards b ON s.board_id = b.id
             LEFT JOIN {prefix}users u ON c.assignee_id = u.id
             ORDER BY c.updated_at DESC
             LIMIT 5"
        );

        return [
            'total_boards'      => $totalBoards,
            'total_cards'       => $totalCards,
            'in_progress_cards' => $inProgressCards,
            'recent_cards'      => $recentCards,
        ];
    }

    /**
     * 取得看板的所有卡片（AJAX 用）
     */
    public function getCardsByBoard(int $boardId): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, s.name AS stage_name, s.color AS stage_color,
                    u.display_name AS assignee_name
             FROM {prefix}cards c
             JOIN {prefix}stages s ON c.stage_id = s.id
             LEFT JOIN {prefix}users u ON c.assignee_id = u.id
             WHERE s.board_id = ?
             ORDER BY c.sort_order ASC, c.id ASC",
            [$boardId]
        );
    }
}
