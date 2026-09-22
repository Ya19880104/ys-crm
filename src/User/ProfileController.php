<?php

declare(strict_types=1);

namespace YangSheep\CRM\User;

use YangSheep\CRM\AuditLog\AuditLogService;
use YangSheep\CRM\Auth\LoginAttemptService;
use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;

/**
 * 個人資料（編輯自己的顯示名稱、頭像、密碼）。
 *
 * 【為何需要獨立於 UserController】
 * 側欄的頭像連結原本指向 /admin/users/{id}/edit，那個頁面需要 user.manage 權限
 * 並會觸發 step-up 再認證 —— 一般 admin / staff 點下去只會被導走，等於「看得到、
 * 改不了自己的頭像」。使用者管理與個人資料是兩件事，權限模型也不同。
 *
 * 【本控制器的安全邊界】
 *   1. 一律只操作 session 中的當前使用者，**不接受任何來自請求的 user id**。
 *      這是防提權的關鍵：只要 id 能從外部指定，就會變成「改別人資料」的入口。
 *   2. 不允許修改 role_id 與 status —— 那是管理操作，必須走 UserController
 *      並具備 user.manage 權限。這裡連欄位都不接受，而不只是前端不顯示。
 */
class ProfileController extends Controller
{
    private UserService $userService;
    private AuditLogService $auditLogService;
    private LoginAttemptService $loginAttempts;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->userService     = new UserService();
        $this->auditLogService = new AuditLogService();
        $this->loginAttempts   = new LoginAttemptService();
    }

    public function edit(): void
    {
        $user = $this->currentUser();

        // 我的登入紀錄：任何人都該看得到「有誰用我的帳號嘗試登入」，
        // 那是本人才會察覺異常的資訊，不該只有管理員看得到。
        // 識別字同時比對帳號與 Email —— 兩者都能登入，只比一個會漏掉一半。
        $myAttempts = $this->loginAttempts->historyForIdentifiers(
            [(string) ($user['username'] ?? ''), (string) ($user['email'] ?? '')],
            1,
            10
        );

        $this->render('admin/profile/edit', [
            'title'        => '個人資料',
            'profile'      => $user,
            'myAttempts'   => $myAttempts['items'],
            'myAttemptsTotal' => $myAttempts['total'],
            'breadcrumb'   => [['label' => '個人資料']],
        ]);
    }

    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $user = $this->currentUser();
        $id   = (int) $user['id'];

        $rules = [
            'display_name' => 'required|string|min:1|max:100',
            'email'        => 'required|email|max:255',
        ];

        $password = $this->request->input('password', '');
        if ($password !== '') {
            $rules['password'] = 'string|min:10|confirmed';
        }

        $validator = Validator::make($this->request->all(), $rules);
        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        // 只取這幾個欄位：role_id / status 即使被塞進表單也不會生效。
        $data = [
            'display_name' => (string) $this->request->input('display_name'),
            'email'        => (string) $this->request->input('email'),
        ];

        // 帳號變更（可選）：只有真的填了**而且與現值不同**時才寫入。
        // 一律送同一個值進 update() 會讓每次存檔都跑一次唯一性查詢，
        // 也會在稽核紀錄裡留下沒發生過的「帳號變更」。
        $newUsername = trim((string) $this->request->input('username', ''));
        $oldUsername = (string) ($user['username'] ?? '');
        $usernameChanged = $newUsername !== '' && $newUsername !== $oldUsername;
        if ($usernameChanged) {
            // 格式錯誤在這裡先擋，訊息才會是「帳號只能使用英文字母…」
            // 而不是 UserService 丟出來的通用例外。
            $formatError = UserService::validateUsername($newUsername);
            if ($formatError !== null) {
                $this->backWithError($formatError);
            }
            $data['username'] = $newUsername;
        }

        if ($password !== '') {
            $data['password'] = $password;
        }

        // 頭像先驗證再持久化（與 UserController 相同理由：避免部分更新無稽核）。
        $avatarFile = $this->request->file('avatar');
        $avatarPath = null;
        if ($avatarFile !== null && ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $avatarPath = $this->userService->handleAvatarUpload($avatarFile);
            if ($avatarPath === null) {
                $this->backWithError('頭像上傳失敗：請確認為 JPG / PNG / WebP、不超過 2MB 且尺寸在 4000×4000 以內。其他變更未儲存。');
            }
        }

        $removeAvatar = $this->request->input('remove_avatar') === '1';
        $avatarChanged = $removeAvatar || $avatarPath !== null;
        $newAvatarPath = $avatarPath ?? '';
        $oldAvatarPath = null;
        $transactionCommitted = false;

        try {
            $this->userService->update(
                $id,
                $data,
                function (int $_revoked) use (
                    $id,
                    $data,
                    $avatarChanged,
                    $newAvatarPath,
                    &$oldAvatarPath
                ): void {
                    if ($avatarChanged) {
                        $oldAvatarPath = $this->userService->stageAvatarChange($id, $newAvatarPath);
                    }

                    $this->auditLogService->log(
                        $id,
                        'profile_updated',
                        'user',
                        $id,
                        ['fields' => array_merge(array_keys($data), $avatarChanged ? ['avatar_path'] : [])],
                        $this->request->ip()
                    );
                }
            );
            $transactionCommitted = true;

            $this->userService->finalizeAvatarChange($oldAvatarPath, $newAvatarPath);

            $this->refreshSessionUser($id);

            // 改密碼會撤銷所有 session（含目前這個），需重新登入。
            if ($password !== '') {
                $this->redirectWith('/login', 'success', '密碼已更新，請以新密碼重新登入。');
            }

            if ($usernameChanged) {
                $this->redirectWith(
                    '/admin/profile',
                    'success',
                    sprintf('個人資料已更新，帳號由「%s」改為「%s」，下次請以新帳號（或 Email）登入。', $oldUsername, $newUsername)
                );
            }

            $this->redirectWith('/admin/profile', 'success', '個人資料已更新。');
        } catch (\Throwable $e) {
            if (!$transactionCommitted) {
                $this->userService->discardPendingAvatar($avatarPath);
            }
            if ($e instanceof \RuntimeException) {
                $this->backWithError($e->getMessage());
            }
            error_log('Profile update failed: ' . $e->getMessage());
            $this->backWithError('個人資料更新失敗，請稍後再試。');
        }
    }

    /**
     * AJAX：檢查帳號是否可用（供表單即時提示）。
     *
     * 【安全邊界】
     *   - 只回答「可不可以用」，不回傳任何屬於別人的資料（不說是誰佔用的）。
     *   - 排除對象固定是 session 中的自己，**不接受請求傳入的 id** ——
     *     否則就變成「幫我確認某個 id 的帳號是不是某某」的查詢介面。
     *   - 掛在 /admin 之下，已有登入 + 2FA + CSRF 四層；未登入者打不到。
     *
     * ⚠️ 這個端點本質上可以列舉帳號（輸入什麼就知道存不存在）。因為只開放給
     * 已登入的內部人員，可接受；**不可**把它搬到登入頁那類公開位置。
     */
    public function checkUsername(): void
    {
        $user = $this->currentUser();

        $candidate = trim((string) $this->request->input('username', ''));
        $result    = $this->userService->checkUsernameAvailability($candidate, (int) $user['id']);

        $this->json([
            'available' => $result['available'],
            'message'   => $result['reason'],
            'current'   => $candidate === (string) ($user['username'] ?? ''),
        ]);
    }

    /**
     * 取得當前登入者的最新資料（不信任 session 內的快照）。
     *
     * @return array<string, mixed>
     */
    private function currentUser(): array
    {
        $sessionUser = Session::get('user');
        $id          = (int) ($sessionUser['id'] ?? 0);

        if ($id <= 0) {
            $this->redirect('/login');
        }

        $user = $this->userService->findById($id);
        if ($user === null) {
            $this->redirect('/login');
        }

        return $user;
    }

    /**
     * 更新 session 內的使用者快照（否則側欄會沿用登入當下的舊值）。
     */
    private function refreshSessionUser(int $id): void
    {
        $fresh = $this->userService->findById($id);
        if ($fresh === null) {
            return;
        }

        $sessionUser = Session::get('user') ?? [];
        // username 也要同步：側欄與個人資料頁都會顯示它，
        // 漏掉的話改完帳號畫面仍是舊值，看起來像沒存成功。
        $sessionUser['username']     = (string) ($fresh['username'] ?? '');
        $sessionUser['display_name'] = (string) ($fresh['display_name'] ?? '');
        $sessionUser['email']        = (string) ($fresh['email'] ?? '');
        $sessionUser['avatar_path']  = (string) ($fresh['avatar_path'] ?? '');
        Session::set('user', $sessionUser);
    }
}
