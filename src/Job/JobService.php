<?php

declare(strict_types=1);

namespace YangSheep\CRM\Job;

use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\BrandColorPolicy;
use YangSheep\CRM\Customer\CustomerRepository;

/**
 * 工作看板領域服務層。
 *
 * 職責：
 * - 看板資料組裝（欄位 + 卡片分組）。
 * - 卡片 CRUD（含客戶存在性驗證、completed_at 依目標欄位自動維護）。
 * - 卡片移動（拖拉 / 變更狀態）並重排 sort。
 * - 追加內容（job_entries）。
 * - 計時器開始 / 停止 / 繼續，保證「同一 job 至多一個 running」（交易 + FOR UPDATE 鎖定）。
 * - 圖片上傳（封面 / 追加附圖），沿用既有 Media 字串路徑模式（/uploads/jobs/）。
 *
 * 對應架構設計 §5.3、§7.6。
 */
class JobService
{
    private JobRepository $jobs;
    private JobColumnRepository $columns;
    private JobEntryRepository $entries;
    private JobTimerRepository $timers;
    private CustomerRepository $customers;
    private Database $db;

    /** 視為「完成」的欄位 slug：移入時寫 completed_at、移出時清空。 */
    private const DONE_SLUGS = ['done', 'completed', 'complete', 'finish', 'finished'];

    /** 允許的圖片 MIME → 副檔名。 */
    private const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** 圖片大小上限（5MB，與 card_comments 一致）。 */
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    public function __construct()
    {
        $this->jobs      = new JobRepository();
        $this->columns   = new JobColumnRepository();
        $this->entries   = new JobEntryRepository();
        $this->timers    = new JobTimerRepository();
        $this->customers = new CustomerRepository();
        $this->db        = Database::getInstance();
    }

    // ───────────────────────── 看板 / 卡片查詢 ─────────────────────────

    /**
     * 組裝看板：取出啟用欄位，並把卡片依 column_id 分組掛入。
     *
     * @param array{customer_id?: int, assigned_to?: int} $filters
     * @return array<int, array<string, mixed>> 每欄含 'jobs' 子陣列
     */
    public function getBoard(array $filters = []): array
    {
        $columns = $this->columns->findAll(true);
        $jobs    = $this->jobs->findForBoard($filters);

        $byColumn = [];
        foreach ($jobs as $job) {
            $byColumn[(int) $job['column_id']][] = $job;
        }

        foreach ($columns as &$col) {
            $col['jobs'] = $byColumn[(int) $col['id']] ?? [];
        }
        unset($col);

        return $columns;
    }

    /**
     * 取得單張卡片完整詳情（含 entries、timers、累計工時、running 段）。
     *
     * @return array<string, mixed>|null
     */
    public function getJobDetail(int $id): ?array
    {
        $job = $this->jobs->findById($id);
        if ($job === null) {
            return null;
        }

        $job['entries']       = $this->entries->findByJobId($id);
        $job['timers']        = $this->timers->findByJobId($id);
        $job['total_seconds'] = $this->timers->totalSeconds($id);
        $job['running']       = $this->timers->findRunning($id);

        return $job;
    }

    /**
     * 取得指定客戶的工作卡片（客戶內頁「工作」tab）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomer(int $customerId): array
    {
        return $this->jobs->findByCustomer($customerId);
    }

    // ───────────────────────── 卡片 CRUD ─────────────────────────

    /**
     * 建立卡片。
     * - 驗證欄位存在且啟用。
     * - customer_id：0/null = 非客戶工作；指定則驗證客戶存在。
     * - sort 自動排到該欄最後。
     * - 若建立時即指定「完成」類欄位，寫入 completed_at。
     *
     * @return int 新卡片 ID
     */
    public function createJob(array $data): int
    {
        $columnId = (int) ($data['column_id'] ?? 0);
        $column   = $this->columns->findById($columnId);
        if ($column === null || (int) $column['is_active'] !== 1) {
            throw new \RuntimeException('指定的看板欄位不存在或已停用。');
        }

        $data['customer_id'] = $this->resolveCustomerId($data['customer_id'] ?? null);
        $data['sort']        = $this->jobs->maxSortInColumn($columnId) + 1;
        $data['completed_at'] = $this->isDoneColumn($column) ? date('Y-m-d H:i:s') : null;

        return $this->jobs->insert($data);
    }

    /**
     * 更新卡片基本欄位（標題 / 內容 / 優先度 / 負責人 / 到期 / 客戶 / 封面）。
     * 不在此處理欄位移動（move() 專責）；completed_at 不在此覆寫。
     */
    public function updateJob(int $id, array $data): void
    {
        $existing = $this->jobs->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('工作不存在。');
        }

        if (array_key_exists('customer_id', $data)) {
            $data['customer_id'] = $this->resolveCustomerId($data['customer_id']);
        }

        $this->jobs->update($id, $data);
    }

    /**
     * 移動卡片到目標欄位與排序位置（拖拉 / 變更狀態下拉共用）。
     * - 驗證目標欄位存在且啟用。
     * - 依目標欄位是否為「完成」類，維護 completed_at（移入寫入、移出清空）。
     *
     * @param int $id        卡片 id
     * @param int $columnId  目標欄位 id
     * @param int $sort      目標排序（前端給的 index；非負）
     */
    public function moveJob(int $id, int $columnId, int $sort): void
    {
        $existing = $this->jobs->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('工作不存在。');
        }

        $column = $this->columns->findById($columnId);
        if ($column === null || (int) $column['is_active'] !== 1) {
            throw new \RuntimeException('目標欄位不存在或已停用。');
        }

        $sort = max(0, $sort);

        $this->db->transaction(function () use ($id, $columnId, $sort, $column, $existing): void {
            $this->jobs->move($id, $columnId, $sort);

            // completed_at 維護：移入完成欄寫入（若原本無）、移出完成欄清空
            $isDone = $this->isDoneColumn($column);
            $hadCompleted = ($existing['completed_at'] ?? null) !== null;

            if ($isDone && !$hadCompleted) {
                $this->jobs->update($id, ['completed_at' => date('Y-m-d H:i:s')]);
            } elseif (!$isDone && $hadCompleted) {
                $this->jobs->update($id, ['completed_at' => null]);
            }
        });
    }

    /**
     * 刪除卡片（entries / timers 由 FK CASCADE 連帶刪除）。
     */
    public function deleteJob(int $id): void
    {
        $existing = $this->jobs->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('工作不存在。');
        }
        $this->jobs->delete($id);
    }

    // ───────────────────────── 追加內容 ─────────────────────────

    /**
     * 為工作新增一則追加內容。
     *
     * @param int                                       $jobId
     * @param int|null                                  $adminUserId
     * @param string                                    $content
     * @param array{path: string, type: string}|null    $media  已上傳的圖片（path/type），可 null
     * @return int 新 entry ID
     */
    public function addEntry(int $jobId, ?int $adminUserId, string $content, ?array $media = null): int
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            throw new \RuntimeException('工作不存在。');
        }

        $content = trim($content);
        if ($content === '' && $media === null) {
            throw new \RuntimeException('追加內容不可為空。');
        }

        return $this->entries->insert([
            'job_id'        => $jobId,
            'admin_user_id' => $adminUserId,
            'content'       => $content,
            'image_path'    => $media['path'] ?? null,
            'media_type'    => $media !== null ? ($media['type'] ?? 'image') : null,
        ]);
    }

    /**
     * 刪除追加內容（限屬於指定工作）。
     */
    public function deleteEntry(int $jobId, int $entryId): void
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null || (int) $entry['job_id'] !== $jobId) {
            throw new \RuntimeException('追加內容不存在。');
        }
        $this->entries->delete($entryId);
    }

    // ───────────────────────── 計時器 ─────────────────────────

    /**
     * 開始 / 繼續計時：於交易內鎖定該工作的 running 段。
     * - 若已有 running 段 → 視為已在計時，直接回傳該段 id（idempotent，不重複開始）。
     * - 否則新增一筆 running 段。
     * 「開始」與「繼續」語意相同（皆為新增 running 段）；保證至多一個 running。
     *
     * @return int 進行中（或新建）的計時段 id
     */
    public function startTimer(int $jobId, ?int $adminUserId, ?string $note = null): int
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            throw new \RuntimeException('工作不存在。');
        }

        return $this->db->transaction(function () use ($jobId, $adminUserId, $note): int {
            $running = $this->timers->findRunningForUpdate($jobId);
            if ($running !== null) {
                return (int) $running['id']; // 已在計時，不重複建立
            }
            return $this->timers->startSegment($jobId, $adminUserId, $note);
        });
    }

    /**
     * 停止計時：於交易內鎖定 running 段並結算。
     * - 無 running 段 → 擲例外（避免無效停止造成 UI 誤解）。
     *
     * @return int 被停止的計時段 id
     */
    public function stopTimer(int $jobId): int
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            throw new \RuntimeException('工作不存在。');
        }

        return $this->db->transaction(function () use ($jobId): int {
            $running = $this->timers->findRunningForUpdate($jobId);
            if ($running === null) {
                throw new \RuntimeException('目前沒有進行中的計時。');
            }
            $this->timers->stopSegment((int) $running['id']);
            return (int) $running['id'];
        });
    }

    // ───────────────────────── 看板欄位（總控 CRUD） ─────────────────────────

    /**
     * 取得欄位清單（管理頁：含停用、含卡片數）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColumns(bool $activeOnly = false): array
    {
        return $this->columns->findAll($activeOnly);
    }

    public function getColumn(int $id): ?array
    {
        return $this->columns->findById($id);
    }

    /**
     * 建立欄位。slug 由名稱正規化產生（或採傳入值），唯一性檢查。
     *
     * @return int 新欄位 ID
     */
    public function createColumn(array $data): int
    {
        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''), (string) ($data['name'] ?? ''));
        if ($slug === '') {
            throw new \RuntimeException('欄位代碼（slug）無效。');
        }
        if ($this->columns->slugExists($slug)) {
            throw new \RuntimeException('欄位代碼已存在，請改用其他代碼。');
        }

        return $this->columns->insert([
            'name'      => $data['name'],
            'slug'      => $slug,
            'color'     => $this->normalizeColor($data['color'] ?? null),
            'sort'      => $this->columns->maxSort() + 1,
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);
    }

    /**
     * 更新欄位（name / color / sort / is_active；slug 不可改）。
     */
    public function updateColumn(int $id, array $data): void
    {
        $existing = $this->columns->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('欄位不存在。');
        }

        $update = [];
        if (array_key_exists('name', $data)) {
            $update['name'] = $data['name'];
        }
        if (array_key_exists('color', $data)) {
            $update['color'] = $this->normalizeColor($data['color']);
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = (int) $data['is_active'];
        }
        if (array_key_exists('sort', $data)) {
            $update['sort'] = (int) $data['sort'];
        }

        $this->columns->update($id, $update);
    }

    /**
     * 刪除欄位。含卡片時拒絕（保護資料；請先移走卡片或停用欄位）。
     */
    public function deleteColumn(int $id): void
    {
        $existing = $this->columns->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('欄位不存在。');
        }
        if ($this->columns->jobCount($id) > 0) {
            throw new \RuntimeException('此欄位仍有工作卡片，請先移走卡片或改為停用。');
        }
        $this->columns->delete($id);
    }

    // ───────────────────────── 圖片上傳 ─────────────────────────

    /**
     * 處理圖片上傳（封面 / 追加附圖共用）。
     * MIME 白名單 + 大小限制 + getimagesize 真實圖片驗證 + 隨機檔名，存 /uploads/jobs/。
     * 與 CardCommentService::handleMediaUpload 相同安全模式。
     *
     * @param array $file $_FILES 單一檔案結構
     * @return array{path: string, type: string}|null 成功回傳 path/type；失敗或未上傳回 null
     */
    public function handleImageUpload(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        // PHP 8.1 起 finfo 已是物件（finfo class），由 GC 釋放；
        // finfo_close() 在 8.5 為 deprecated 的 no-op。

        if (!isset(self::IMAGE_MIMES[$mimeType])) {
            return null;
        }
        if (($file['size'] ?? 0) > self::MAX_IMAGE_SIZE) {
            return null;
        }
        // 真實圖片驗證（防偽造副檔名 / MIME）
        if (@getimagesize($file['tmp_name']) === false) {
            return null;
        }

        $ext      = self::IMAGE_MIMES[$mimeType];
        $filename = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        $webRoot   = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $uploadDir = \YangSheep\CRM\Core\UploadPath::subdir('jobs', $webRoot);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 寫檔前自我修復 uploads/ 的執行防護，未備妥則中止上傳。
        if (!\YangSheep\CRM\Install\UploadGuard::ensure(\YangSheep\CRM\Core\UploadPath::absolute($webRoot))) {
            return null;
        }

        $destPath = $uploadDir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return [
                'path' => \YangSheep\CRM\Core\UploadPath::webSubdir('jobs') . $filename,
                'type' => 'image',
            ];
        }

        return null;
    }

    // ───────────────────────── 內部輔助 ─────────────────────────

    /**
     * 解析 customer_id：0/null/空 → null（非客戶工作）；指定則驗證存在。
     */
    private function resolveCustomerId(mixed $customerId): ?int
    {
        $id = (int) ($customerId ?? 0);
        if ($id <= 0) {
            return null;
        }
        if ($this->customers->findById($id) === null) {
            throw new \RuntimeException('指定的客戶不存在。');
        }
        return $id;
    }

    /**
     * 判斷欄位是否為「完成」類（依 slug 白名單）。
     *
     * @param array<string, mixed> $column
     */
    private function isDoneColumn(array $column): bool
    {
        return in_array(strtolower((string) ($column['slug'] ?? '')), self::DONE_SLUGS, true);
    }

    /**
     * slug 正規化：優先採傳入 slug，否則由 name 轉小寫英數 + 連字號。
     * 僅保留 a-z0-9 與 -；非法字元（含中文）以 - 取代後去除頭尾 -。
     * 若結果為空（如純中文名），退而以隨機碼，確保唯一可建立。
     */
    private function normalizeSlug(string $slug, string $name): string
    {
        $src = $slug !== '' ? $slug : $name;
        $src = strtolower(trim($src));
        $out = preg_replace('/[^a-z0-9]+/', '-', $src) ?? '';
        $out = trim($out, '-');
        if ($out === '') {
            $out = 'col-' . bin2hex(random_bytes(4));
        }
        return substr($out, 0, 50);
    }

    /**
     * 顏色正規化：限 #RRGGBB；非法則回預設品牌深藍。
     */
    private function normalizeColor(mixed $color): string
    {
        return BrandColorPolicy::normalize($color);
    }
}
