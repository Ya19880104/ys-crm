<?php

declare(strict_types=1);

namespace YangSheep\CRM\User;

use YangSheep\CRM\Auth\CredentialRotationService;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Role\RoleRepository;

class UserService
{
    /** 頭像允許的 MIME → 副檔名 */
    private const AVATAR_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** 頭像大小上限（2MB） */
    private const MAX_AVATAR_SIZE = 2 * 1024 * 1024;

    /** 單邊像素上限（擋解壓縮炸彈；頭像實際只顯示 32–64px，4000 已極寬鬆） */
    private const MAX_AVATAR_DIMENSION = 4000;

    /** 總像素上限（1600 萬 ≈ 4000×4000） */
    private const MAX_AVATAR_PIXELS = 16_000_000;

    /**
     * 帳號格式：英數與 . _ - ，3–50 字元。
     *
     * 【為何不允許 @】登入現在收「帳號或 Email」兩種識別字。若帳號可以長得像 email，
     * 就會出現「A 的帳號 == B 的 email」這種撞號，登入時得靠優先順序才知道是誰
     * （AuthService 有寫死帳號優先，但那是防線不是設計）。從源頭禁掉 @ ，
     * 兩個命名空間就不可能重疊。
     *
     * 【為何長度上限是 50】對齊 users.username 的 VARCHAR(50)。
     */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,50}$/';

    private UserRepository $repository;
    private RoleRepository $roleRepository;
    private Database $db;
    private CredentialRotationService $credentialRotation;

    public function __construct()
    {
        $this->repository = new UserRepository();
        $this->roleRepository = new RoleRepository();
        $this->db = Database::getInstance();
        $this->credentialRotation = new CredentialRotationService();
    }

    /**
     * 分頁查詢使用者列表
     *
     * @return array{items: array, total: int}
     */
    public function findAll(int $page = 1, int $perPage = 15): array
    {
        return $this->repository->findAll($page, $perPage);
    }

    /**
     * 根據 ID 取得使用者
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * 建立使用者
     *
     * @return int 新使用者 ID
     * @throws \RuntimeException 帳號已存在時
     */
    public function create(array $data): int
    {
        // 🔴 【建立也要驗格式，不能只在改名時驗】原本只有 update() 與
        // checkUsernameAvailability() 呼叫 validateUsername()，create() 完全跳過。
        // 結果是可以「建立」一個永遠改不回來的帳號 —— 而且那個規則不是美觀問題：
        // 登入表單同時接受帳號與 Email，所以帳號含 @ 就可能與別人的 Email 撞名，
        // 讓登入時「這是誰」變成不確定的事。擋在改名卻不擋在建立，等於沒擋。
        $formatError = self::validateUsername((string) ($data['username'] ?? ''));
        if ($formatError !== null) {
            throw new \RuntimeException($formatError);
        }

        // 檢查帳號是否重複
        $existing = $this->repository->findByUsername($data['username']);
        if ($existing !== null) {
            throw new \RuntimeException('帳號已被使用。');
        }

        // 檢查 email 是否重複
        $existingEmail = $this->repository->findByEmail($data['email']);
        if ($existingEmail !== null) {
            throw new \RuntimeException('Email 已被使用。');
        }

        // role_id 完整性：指派前確認該角色存在（避免指向不存在/已刪角色 → 權限懸空）。
        $this->assertRoleExists($data['role_id'] ?? null);

        $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID);

        return $this->repository->insert($data);
    }

    /**
     * 更新使用者
     *
     * @param null|callable(int):void $beforeCommit DB 欄位、credential 與 session revoke
     *                                               成功後、transaction commit 前執行
     * @throws \RuntimeException
     */
    public function update(int $id, array $data, ?callable $beforeCommit = null): void
    {
        $user = $this->repository->findById($id);
        if ($user === null) {
            throw new \RuntimeException('使用者不存在。');
        }

        // 檢查 email 是否與其他使用者重複
        if (isset($data['email'])) {
            $existingEmail = $this->repository->findByEmail($data['email']);
            if ($existingEmail !== null && (int) $existingEmail['id'] !== $id) {
                throw new \RuntimeException('Email 已被使用。');
            }
        }

        // 帳號變更：格式 + 唯一性。
        //
        // 【為何在這裡擋而不只靠資料庫的 UNIQUE】DB 的唯一鍵會擋，但擋出來的是
        // 一個 PDOException（畫面上是 500 或一句看不懂的錯誤）。使用者要看到的是
        // 「這個帳號已經有人用了」。DB 的唯一鍵仍是最後防線（併發時兩個請求可能
        // 同時通過這裡的檢查），兩層都要有。
        if (isset($data['username'])) {
            $newUsername = trim((string) $data['username']);
            $error       = self::validateUsername($newUsername);
            if ($error !== null) {
                throw new \RuntimeException($error);
            }

            $existingName = $this->repository->findByUsername($newUsername);
            if ($existingName !== null && (int) $existingName['id'] !== $id) {
                throw new \RuntimeException('此帳號已被使用，請換一個。');
            }

            $data['username'] = $newUsername;
        }

        // role_id 完整性：若本次有變更 role_id，指派前確認該角色存在。
        if (array_key_exists('role_id', $data)) {
            $this->assertRoleExists($data['role_id']);
        }

        // 密碼保留為明文只到共用 rotator 的 API 邊界；hash、readback 與
        // session revoke 由 CredentialRotationService 在同一交易完成。
        $plainPassword = null;
        if (isset($data['password']) && $data['password'] !== '') {
            $plainPassword = (string) $data['password'];
        }
        unset($data['password']);

        // kill-switch 判斷：本次是否將帳號改為「非 active」（停用）。
        $deactivated = array_key_exists('status', $data)
            && (string) $data['status'] !== 'active';

        if ($plainPassword !== null) {
            $this->credentialRotation->rotateAdmin(
                $id,
                $plainPassword,
                function (int $revoked) use ($id, $data, $beforeCommit): void {
                    $this->repository->update($id, $data);
                    if ($beforeCommit !== null) {
                        $beforeCommit($revoked);
                    }
                }
            );
            return;
        }

        $this->db->transaction(function () use ($id, $data, $deactivated, $beforeCommit): void {
            $this->repository->update($id, $data);

            $revoked = $deactivated
                ? Session::revokeAllForUserOrFail('admin', $id)
                : 0;
            if ($beforeCommit !== null) {
                $beforeCommit($revoked);
            }
        });
    }

    /**
     * 檢查帳號格式，合格回 null，不合格回可直接顯示給使用者的訊息。
     */
    public static function validateUsername(string $username): ?string
    {
        $username = trim($username);

        if ($username === '') {
            return '請輸入帳號。';
        }
        if (mb_strlen($username) < 3) {
            return '帳號至少 3 個字元。';
        }
        if (mb_strlen($username) > 50) {
            return '帳號最多 50 個字元。';
        }
        if (str_contains($username, '@')) {
            // 訊息要說明「為什麼」，否則使用者只會覺得系統莫名其妙。
            return '帳號不可包含 @（Email 已經可以直接用來登入，帳號請用英數與 . _ -）。';
        }
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return '帳號只能使用英文字母、數字與 . _ - 。';
        }

        return null;
    }

    /**
     * 這個帳號可不可以用？（供前端即時檢查與伺服器端再驗）
     *
     * @param int|null $exceptUserId 排除某位使用者（改自己的帳號時要排除自己，
     *                               否則「維持原帳號不變」會被判定為重複）
     * @return array{available: bool, reason: string}
     */
    public function checkUsernameAvailability(string $username, ?int $exceptUserId = null): array
    {
        $username = trim($username);

        $formatError = self::validateUsername($username);
        if ($formatError !== null) {
            return ['available' => false, 'reason' => $formatError];
        }

        $existing = $this->repository->findByUsername($username);
        if ($existing !== null && (int) $existing['id'] !== (int) $exceptUserId) {
            return ['available' => false, 'reason' => '此帳號已被使用，請換一個。'];
        }

        if ($existing !== null) {
            return ['available' => true, 'reason' => '這是您目前的帳號。'];
        }

        return ['available' => true, 'reason' => '可以使用。'];
    }

    /**
     * 處理頭像上傳，回傳可存入資料庫的相對路徑；失敗回 null。
     *
     * 安全處理與其他上傳點一致（Job / Media / Setting）：
     *   1. 以 finfo 讀「實際內容」的 MIME，不信任瀏覽器送來的 type 或副檔名
     *   2. getimagesize() 確認是真的圖片（擋掉偽造 MIME 的檔案）
     *   3. 隨機檔名 —— 使用者無法指定檔名，也就無法猜到別人的頭像路徑
     *
     * ⚠️ uploads/ 位於 web root 下且 Nginx 不讀 .htaccess，上述三道是唯一防線；
     * 放寬任何一道都可能讓可執行檔案落地（見 docs/GO-LIVE.md）。
     *
     * @param array $file $_FILES 的單一檔案結構
     */
    public function handleAvatarUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (($file['size'] ?? 0) > self::MAX_AVATAR_SIZE) {
            return null;
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
        // PHP 8.1 起 finfo 已是物件（finfo class），由 GC 釋放；
        // finfo_close() 在 8.5 為 deprecated 的 no-op。
        if ($mimeType === false || !isset(self::AVATAR_MIMES[$mimeType])) {
            return null;
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return null;
        }

        // 尺寸與總像素上限。
        //
        // 【為何光有 MIME + getimagesize 不夠】一張 65535×65535 的圖，檔案本身可能只有
        // 幾十 KB（高壓縮比），能通過大小限制與真實圖片驗證，但任何後續要解碼它的程式
        // （縮圖、轉檔、甚至瀏覽器）都會瞬間吃掉數十 GB 記憶體。這類「解壓縮炸彈」
        // 必須用維度而非檔案大小來擋。
        [$width, $height] = [(int) ($info[0] ?? 0), (int) ($info[1] ?? 0)];
        if ($width < 1 || $height < 1) {
            return null;
        }
        if ($width > self::MAX_AVATAR_DIMENSION || $height > self::MAX_AVATAR_DIMENSION) {
            return null;
        }
        if ($width * $height > self::MAX_AVATAR_PIXELS) {
            return null;
        }

        $webRoot   = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $uploadDir = \YangSheep\CRM\Core\UploadPath::subdir('avatars', $webRoot);
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }

        // uploads/ 的執行防護是自我修復的：每次寫檔前確保 .user.ini 還在。
        // 防護設定未備妥則中止上傳，不能只在安裝時設一次。
        if (!\YangSheep\CRM\Install\UploadGuard::ensure(\YangSheep\CRM\Core\UploadPath::absolute($webRoot))) {
            return null;
        }

        $filename = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . self::AVATAR_MIMES[$mimeType];
        if (!$this->moveUploadedFile($file['tmp_name'], $uploadDir . '/' . $filename)) {
            return null;
        }

        return \YangSheep\CRM\Core\UploadPath::webSubdir('avatars') . $filename;
    }

    /**
     * 搬移上傳檔案。
     *
     * 抽成獨立方法只為了可測試性：move_uploaded_file() 在 CLI 下對非真實 HTTP 上傳
     * 的檔案一律回 false，測試若要覆蓋上述所有驗證分支就必須能替換這一步。
     * 產品程式碼仍走 move_uploaded_file()，保留它對「確實來自上傳」的檢查。
     */
    protected function moveUploadedFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }

    /**
     * 更新頭像（換新圖時順手刪掉舊檔，避免無主檔案在磁碟累積）。
     */
    public function updateAvatar(int $id, string $newPath): void
    {
        $old = $this->stageAvatarChange($id, $newPath);
        $this->finalizeAvatarChange($old, $newPath);
    }

    /**
     * 移除頭像（改回顯示預設圖案）。
     */
    public function removeAvatar(int $id): void
    {
        $user = $this->repository->findById($id);
        if ($user === null) {
            return;
        }

        $old = trim((string) ($user['avatar_path'] ?? ''));
        $this->repository->update($id, ['avatar_path' => '']);

        if ($old !== '') {
            $this->deleteAvatarFile($old);
        }
    }

    /**
     * 只更新 DB 中的頭像參照，回傳舊路徑供 transaction commit 後清理。
     *
     * 這個方法可安全放在 UserService::update() 的 beforeCommit callback：若後續
     * audit 失敗，DB transaction 會回滾 avatar_path，而且舊實體檔尚未被刪除。
     */
    public function stageAvatarChange(int $id, string $newPath): string
    {
        $user = $this->repository->findById($id);
        if ($user === null) {
            throw new \RuntimeException('使用者不存在。');
        }

        $old = trim((string) ($user['avatar_path'] ?? ''));
        $this->repository->update($id, ['avatar_path' => $newPath]);

        return $old;
    }

    /**
     * DB/audit 已提交後，才清掉已不再被參照的舊實體檔。
     */
    public function finalizeAvatarChange(?string $oldPath, ?string $newPath): void
    {
        $old = trim((string) $oldPath);
        $new = trim((string) $newPath);
        if ($old !== '' && $old !== $new) {
            $this->deleteAvatarFile($old);
        }
    }

    /**
     * transaction 未提交時，清掉剛搬入但尚未被 DB 參照的新上傳檔。
     */
    public function discardPendingAvatar(?string $path): void
    {
        $candidate = trim((string) $path);
        if ($candidate !== '') {
            $this->deleteAvatarFile($candidate);
        }
    }

    /**
     * 刪除頭像實體檔。
     *
     * 只接受 /uploads/avatars/ 底下的單層檔名：路徑來自資料庫，但仍當作不可信輸入處理，
     * 避免任何情況下被導去刪除其他目錄的檔案。
     */
    private function deleteAvatarFile(string $relativePath): void
    {
        $avatarPrefix = \YangSheep\CRM\Core\UploadPath::webSubdir('avatars');
        if (!str_starts_with($relativePath, $avatarPrefix)) {
            return;
        }

        $filename = basename($relativePath);
        if ($filename === '' || $filename !== substr($relativePath, strlen($avatarPrefix))) {
            return;
        }

        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : BASE_PATH;
        $full    = \YangSheep\CRM\Core\UploadPath::subdir('avatars', $webRoot) . '/' . $filename;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * 停用使用者（改為 inactive），並撤銷其所有既存 session（kill-switch）。
     *
     * @param null|callable(int):void $beforeCommit status 與 session revoke 成功後、
     *                                               transaction commit 前執行
     */
    public function deactivate(int $id, ?callable $beforeCommit = null): void
    {
        $this->update($id, ['status' => 'inactive'], $beforeCommit);
    }

    /**
     * role_id 完整性檢查：角色須存在，否則拒絕（避免權限懸空）。
     *
     * @param mixed $roleId 待指派的 role_id
     * @throws \RuntimeException 角色不存在時
     */
    private function assertRoleExists(mixed $roleId): void
    {
        $rid = (int) $roleId;
        if ($rid <= 0 || $this->roleRepository->findById($rid) === null) {
            throw new \RuntimeException('角色不存在。');
        }
    }

    /**
     * 取得使用者總數
     */
    public function count(): int
    {
        return $this->repository->count();
    }
}
