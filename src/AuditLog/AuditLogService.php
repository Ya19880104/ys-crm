<?php

declare(strict_types=1);

namespace YangSheep\CRM\AuditLog;

class AuditLogService
{
    private AuditLogRepository $repository;

    public function __construct()
    {
        $this->repository = new AuditLogRepository();
    }

    /**
     * 記錄審計日誌
     *
     * @param int         $userId     操作者 ID（0 表示匿名）
     * @param string      $action     動作名稱（如 login_success, user_created）
     * @param string|null $targetType 目標類型（如 user, board, card）
     * @param int|null    $targetId   目標 ID
     * @param array|null  $detail     額外細節（JSON 儲存）
     * @param string|null $ip         IP 位址
     */
    public function log(
        int $userId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $detail = null,
        ?string $ip = null
    ): void {
        $this->repository->insert([
            'user_id'     => $userId,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'detail'      => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'  => $ip,
        ]);
    }

    /**
     * 分頁查詢審計日誌
     *
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 20, ?array $filters = null): array
    {
        return $this->repository->findAll($page, $perPage, $filters);
    }
}
