<?php

declare(strict_types=1);

namespace YangSheep\CRM\Board;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;

class BoardController extends Controller
{
    private BoardService $boardService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->boardService = new BoardService();
    }

    /**
     * 後台儀表板 — 乾淨的 YS CRM 歡迎頁（不查 boards/cards）
     */
    public function dashboard(): void
    {
        $this->render('admin/dashboard', [
            'title' => '儀表板',
        ]);
    }

    /**
     * 看板列表
     */
    public function index(): void
    {
        $boards = $this->boardService->findAll();

        $this->render('admin/boards/index', [
            'title'  => '看板管理',
            'boards' => $boards,
        ]);
    }

    /**
     * 新增看板表單
     */
    public function create(): void
    {
        $this->render('admin/boards/create', [
            'title' => '新增看板',
        ]);
    }

    /**
     * POST 建立看板
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $data = $this->request->only(['name', 'description', 'is_active', 'sort_order', 'type']);

        $validator = Validator::make($data, [
            'name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $user = Session::get('user');
        $data['created_by'] = $user['id'] ?? null;
        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['type'] = in_array($data['type'] ?? '', ['public', 'internal']) ? $data['type'] : 'internal';
        $data['is_public'] = $data['type'] === 'public' ? 1 : 0;
        $data['slug'] = $this->generateSlug($data['name']);

        $this->boardService->create($data);

        $this->redirectWith('/admin/boards', 'success', '看板建立成功');
    }

    /**
     * Kanban 看板視圖（核心頁面）
     */
    public function show(): void
    {
        $id = (int) $this->request->param('id');
        $board = $this->boardService->findWithStagesAndCards($id);

        if (!$board) {
            $this->backWithError('看板不存在');
        }

        $this->render('admin/boards/show', [
            'title' => $board['name'],
            'board' => $board,
        ]);
    }

    /**
     * 編輯看板表單
     */
    public function edit(): void
    {
        $id = (int) $this->request->param('id');
        $board = $this->boardService->findById($id);

        if (!$board) {
            $this->backWithError('看板不存在');
        }

        $this->render('admin/boards/edit', [
            'title' => '編輯看板',
            'board' => $board,
        ]);
    }

    /**
     * POST 更新看板
     */
    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');
        $data = $this->request->only(['name', 'description', 'is_active', 'sort_order', 'type']);

        $validator = Validator::make($data, [
            'name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['type'] = in_array($data['type'] ?? '', ['public', 'internal']) ? $data['type'] : 'internal';
        $data['is_public'] = $data['type'] === 'public' ? 1 : 0;

        $this->boardService->update($id, $data);

        $this->redirectWith('/admin/boards', 'success', '看板更新成功');
    }

    /**
     * 產生 URL slug
     */
    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', $slug);
        $slug = trim($slug, '-');
        if (empty($slug)) {
            $slug = 'board-' . time();
        }
        return $slug;
    }

    /**
     * AJAX — 回傳看板的所有卡片 JSON
     */
    public function cards(): void
    {
        $id = (int) $this->request->param('id');
        $cards = $this->boardService->getCardsByBoard($id);

        $this->json(['success' => true, 'data' => $cards]);
    }
}
