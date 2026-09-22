<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Database;

/**
 * 工作追加內容資料存取層（job_entries）。
 * 提供依工作查詢、新增、刪除；JOIN 撰寫者名供顯示。
 * 全程 prepared statement，{prefix} 佔位符 + 參數綁定。
 *
 * 對應架構設計 §5.3 job_entries、§7.6「持續追加內容、附時間、可附圖」。
 */
class JobEntryRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 取得指定工作的所有追加內容（新→舊），含撰寫者名。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByJobId(int $jobId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.job_id, e.admin_user_id, e.content,
                    e.image_path, e.media_type, e.created_at,
                    u.display_name AS author_name
             FROM {prefix}job_entries e
             LEFT JOIN {prefix}users u ON e.admin_user_id = u.id
             WHERE e.job_id = :job_id
             ORDER BY e.created_at DESC, e.id DESC",
            ['job_id' => $jobId]
        );
    }

    /**
     * 取得單筆追加內容。
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM {prefix}job_entries WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 新增追加內容。created_at 由 DB NOW() 預設（不接受前端傳入時間）。
     *
     * @return int 新 entry ID
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            "INSERT INTO {prefix}job_entries
                (job_id, admin_user_id, content, file_id, image_path, media_type, created_at)
             VALUES
                (:job_id, :admin_user_id, :content, :file_id, :image_path, :media_type, NOW())",
            [
                'job_id'        => $data['job_id'],
                'admin_user_id' => $data['admin_user_id'] ?? null,
                'content'       => $data['content'],
                'file_id'       => $data['file_id'] ?? null,
                'image_path'    => $data['image_path'] ?? null,
                'media_type'    => $data['media_type'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * 刪除追加內容。
     *
     * @return int 影響筆數
     */
    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {prefix}job_entries WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * 指定工作的追加內容數量。
     */
    public function countByJob(int $jobId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}job_entries WHERE job_id = :job_id",
            ['job_id' => $jobId]
        );
    }
}
