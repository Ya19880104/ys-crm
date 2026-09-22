<?php

declare(strict_types=1);

namespace YangSheep\CRM\Card;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;

class CardController extends Controller
{
    private CardService $cardService;
    private CardDragService $dragService;
    private CardLogService $logService;
    private CardCommentService $commentService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->cardService = new CardService();
        $this->dragService = new CardDragService();
        $this->logService = new CardLogService();
        $this->commentService = new CardCommentService();
    }

    /**
     * 卡片列表（表格視圖，支援篩選）
     */
    public function index(): void
    {
        $filters = [
            'board_id'    => $this->request->query('board_id'),
            'stage_id'    => $this->request->query('stage_id'),
            'priority'    => $this->request->query('priority'),
            'assignee_id' => $this->request->query('assignee_id'),
            'keyword'     => $this->request->query('keyword'),
        ];
        $filters = array_filter($filters);

        $page = max(1, (int) $this->request->query('page', '1'));
        $perPage = 20;

        $cards = $this->cardService->findAll($filters, $page, $perPage);
        $total = $this->cardService->count($filters);
        $totalPages = (int) ceil($total / $perPage);

        $boards = $this->cardService->getAllBoards();
        $stages = $this->cardService->getAllStages();
        $users = $this->cardService->getAllUsers();

        $this->render('admin/cards/index', [
            'title'      => '卡片管理',
            'cards'      => $cards,
            'filters'    => $filters,
            'boards'     => $boards,
            'stages'     => $stages,
            'users'      => $users,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    /**
     * 新增卡片表單
     */
    public function create(): void
    {
        $boards = $this->cardService->getAllBoards();
        $stages = $this->cardService->getAllStages();
        $users = $this->cardService->getAllUsers();

        $this->render('admin/cards/create', [
            'title'  => '新增卡片',
            'boards' => $boards,
            'stages' => $stages,
            'users'  => $users,
        ]);
    }

    /**
     * POST 建立卡片
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $data = $this->request->only([
            'stage_id', 'title', 'description', 'priority',
            'assignee_id', 'is_public', 'sort_order',
        ]);

        $validator = Validator::make($data, [
            'stage_id' => 'required|integer',
            'title'    => 'required|string|max:200',
            'priority' => 'required|in:critical,high,medium,low',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $user = Session::get('user');
        $data['stage_id'] = (int) $data['stage_id'];

        // 從 stage 查詢對應的 board_id
        $stage = $this->cardService->getStageById($data['stage_id']);
        if (!$stage) {
            $this->backWithError('無效的狀態');
        }
        $data['board_id'] = (int) $stage['board_id'];

        $data['assignee_id'] = !empty($data['assignee_id']) ? (int) $data['assignee_id'] : null;
        $data['is_public'] = isset($data['is_public']) ? 1 : 0;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['source'] = 'manual';
        $data['created_by'] = $user['id'] ?? null;

        $cardId = $this->cardService->create($data);

        // 記錄建立歷程
        if ($user) {
            $this->logService->log($cardId, (int) $user['id'], 'created', null, null, null);
        }

        $this->redirectWith('/admin/cards/' . $cardId, 'success', '卡片建立成功');
    }

    /**
     * 卡片詳情（含歷程時間軸）
     */
    public function show(): void
    {
        $id = (int) $this->request->param('id');
        $card = $this->cardService->findById($id);

        if (!$card) {
            $this->backWithError('卡片不存在');
        }

        $logs = $this->logService->getCardLogs($id);
        $comments = $this->commentService->findByCardId($id);

        $this->render('admin/cards/show', [
            'title'    => $card['title'],
            'card'     => $card,
            'logs'     => $logs,
            'comments' => $comments,
        ]);
    }

    /**
     * POST 新增留言
     */
    public function addComment(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗');
        }

        $cardId = (int) $this->request->param('id');
        $card = $this->cardService->findById($cardId);
        if (!$card) {
            $this->backWithError('卡片不存在');
        }

        $content = trim($this->request->input('content', ''));
        if (empty($content)) {
            $this->backWithError('備註內容不能為空');
        }

        $user = Session::get('user');
        $notedAt = $this->request->input('noted_at');
        if (empty($notedAt) || strtotime($notedAt) === false) {
            $notedAt = date('Y-m-d H:i:s');
        }

        // 處理媒體上傳（圖片或影片）
        $mediaPath = null;
        $mediaType = null;
        $uploadFailed = false;
        $file = $this->request->file('media');
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $file = $this->request->file('image'); // 相容舊欄位名
        }
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $media = $file['error'] === UPLOAD_ERR_OK
                ? $this->commentService->handleMediaUpload($file)
                : null;
            if ($media) {
                $mediaPath = $media['path'];
                $mediaType = $media['type'];
            } else {
                $uploadFailed = true;
            }
        }

        $this->commentService->create([
            'card_id'    => $cardId,
            'user_id'    => (int) ($user['id'] ?? 0),
            'content'    => $content,
            'image_path' => $mediaPath,
            'media_type' => $mediaType,
            'noted_at'   => $notedAt,
        ]);

        if ($uploadFailed) {
            $this->redirectWith('/admin/cards/' . $cardId, 'error', '備註文字已新增，但附件未儲存。請重試上傳；若持續失敗，請聯絡管理員。');
        }
        $this->redirectWith('/admin/cards/' . $cardId, 'success', '備註已新增');
    }

    /**
     * POST 刪除留言
     */
    public function deleteComment(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗');
        }

        $cardId = (int) $this->request->param('id');
        $commentId = (int) $this->request->param('comment_id');

        $comment = $this->commentService->findById($commentId);
        if (!$comment || (int) $comment['card_id'] !== $cardId) {
            $this->backWithError('留言不存在');
        }

        // 權限檢查：僅允許留言作者或管理員（role_id <= 2）刪除
        $user = Session::get('user');
        $isOwner = (int) ($user['id'] ?? 0) === (int) $comment['user_id'];
        $isAdmin = (int) ($user['role_id'] ?? 99) <= 2;
        if (!$isOwner && !$isAdmin) {
            $this->backWithError('您沒有權限刪除此留言');
        }

        // 刪除圖片檔案
        if (!empty($comment['image_path'])) {
            $filePath = dirname(__DIR__, 2) . '/public' . $comment['image_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $this->commentService->delete($commentId);

        $this->redirectWith('/admin/cards/' . $cardId, 'success', '備註已刪除');
    }

    /**
     * 編輯卡片表單
     */
    public function edit(): void
    {
        $id = (int) $this->request->param('id');
        $card = $this->cardService->findById($id);

        if (!$card) {
            $this->backWithError('卡片不存在');
        }

        $boards = $this->cardService->getAllBoards();
        $stages = $this->cardService->getAllStages();
        $users = $this->cardService->getAllUsers();

        $this->render('admin/cards/edit', [
            'title'  => '編輯卡片',
            'card'   => $card,
            'boards' => $boards,
            'stages' => $stages,
            'users'  => $users,
        ]);
    }

    /**
     * POST 更新卡片
     */
    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id   = (int) $this->request->param('id');
        $card = $this->cardService->findById($id);
        if (!$card) {
            $this->backWithError('卡片不存在');
        }

        // 🔴 /admin/cards 這個路由群組只掛了 card.create。
        // 也就是說在補上這道之前，任何能建卡的人（seed 的 staff 即是）
        // 都能改**任何人**的卡片 —— 與 API 平面是同一個缺口，只是沒被稽核掃到。
        $user   = Session::get('user');
        $userId = (int) ($user['id'] ?? 0);
        if (!$this->cardService->canEdit($userId, $card)) {
            $this->backWithError('您沒有編輯此卡片的權限');
        }

        $data = $this->request->only([
            'stage_id', 'title', 'description', 'priority',
            'assignee_id', 'is_public', 'sort_order',
        ]);

        $validator = Validator::make($data, [
            'stage_id' => 'required|integer',
            'title'    => 'required|string|max:200',
            'priority' => 'required|in:critical,high,medium,low',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data['stage_id'] = (int) $data['stage_id'];
        $data['assignee_id'] = !empty($data['assignee_id']) ? (int) $data['assignee_id'] : null;
        $data['is_public'] = isset($data['is_public']) ? 1 : 0;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        $this->cardService->update($id, $data, $userId);

        $this->redirectWith('/admin/cards/' . $id, 'success', '卡片更新成功');
    }

    /**
     * POST 刪除卡片
     */
    public function destroy(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');
        $card = $this->cardService->findById($id);
        if (!$card) {
            $this->backWithError('卡片不存在');
        }

        // 權限檢查：刪除卡片需要 card.delete。
        //
        // 【為何不再用 role_id <= 2】role id 是 seed 出來的資料，不是權限。
        // 新增一個自訂角色就可能拿到 id 2 而變成「管理員」，而真正被授予
        // card.delete 的自訂角色反而被擋。權限碼才是這件事的唯一依據
        // ——目前 seed 中只有 super_admin 與 admin 具備 card.delete，
        // 行為與原本的意圖一致，但不再依賴 id 的巧合。
        $this->guardPermission('card.delete', '/admin/cards');

        $this->cardService->delete($id);

        $this->redirectWith('/admin/cards', 'success', '卡片已刪除');
    }

    /**
     * AJAX POST — 拖拉移動卡片
     */
    public function move(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->json(['success' => false, 'message' => 'CSRF 驗證失敗'], 403);
            return;
        }

        $cardId = (int) $this->request->param('id');
        $targetStageId = (int) $this->request->input('stage_id');
        $newSortOrder = (int) $this->request->input('sort_order', '0');
        $user = Session::get('user');

        if (!$user) {
            $this->json(['success' => false, 'message' => '請先登入'], 403);
            return;
        }

        try {
            $this->dragService->move($cardId, $targetStageId, $newSortOrder, (int) $user['id']);
            $this->json(['success' => true, 'message' => '卡片已移動']);
        } catch (\RuntimeException $e) {
            // 僅回傳安全的錯誤訊息，不洩漏內部細節
            $safeMessages = ['卡片不存在', '無效的目標狀態', '此狀態不允許拖拉進入', '此狀態不允許拖拉出去'];
            $msg = in_array($e->getMessage(), $safeMessages, true)
                ? $e->getMessage()
                : '操作失敗，請稍後再試';
            $this->json(['success' => false, 'message' => $msg], 422);
        }
    }

    /**
     * AJAX PATCH — 更新卡片
     */
    public function apiUpdate(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->json(['success' => false, 'message' => 'CSRF 驗證失敗'], 403);
            return;
        }

        $id = (int) $this->request->param('id');
        $card = $this->cardService->findById($id);

        if (!$card) {
            $this->json(['success' => false, 'message' => '卡片不存在'], 404);
            return;
        }

        $user   = Session::get('user');
        $userId = (int) ($user['id'] ?? 0);

        // 🔴 歸屬檢查。路由層放行的是 `card.edit_all|card.edit_own`，
        // 「能不能改**這一張**」必須在這裡判斷 —— 少了這段，只有 edit_own 的角色
        // 就能改任何人的卡片（複審 2026-08-17 指出此端點原本完全沒有歸屬檢查）。
        if (!$this->cardService->canEdit($userId, $card)) {
            $this->json(['success' => false, 'message' => '您沒有編輯此卡片的權限'], 403);
            return;
        }

        $data = $this->request->only([
            'stage_id', 'title', 'description', 'priority',
            'assignee_id', 'is_public', 'sort_order',
        ]);

        // 合併現有資料與新資料
        $merged = array_merge([
            'stage_id'    => $card['stage_id'],
            'title'       => $card['title'],
            'description' => $card['description'],
            'priority'    => $card['priority'],
            'assignee_id' => $card['assignee_id'],
            'is_public'   => $card['is_public'],
            'sort_order'  => $card['sort_order'],
        ], array_filter($data, fn($v) => $v !== null, ARRAY_FILTER_USE_BOTH));

        $this->cardService->update($id, $merged, $userId);

        $this->json(['success' => true, 'message' => '卡片已更新']);
    }
}
