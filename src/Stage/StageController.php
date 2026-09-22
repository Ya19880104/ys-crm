<?php

declare(strict_types=1);

namespace YangSheep\CRM\Stage;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Core\Validator;

class StageController extends Controller
{
    private StageService $stageService;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->stageService = new StageService();
    }

    /**
     * 狀態管理列表（按看板分群）
     */
    public function index(): void
    {
        $grouped = $this->stageService->findAllGrouped();

        $this->render('admin/stages/index', [
            'title'   => '狀態管理',
            'grouped' => $grouped,
        ]);
    }

    /**
     * 新增狀態表單
     */
    public function create(): void
    {
        $boards = $this->stageService->getAllBoards();

        $this->render('admin/stages/create', [
            'title'  => '新增狀態',
            'boards' => $boards,
        ]);
    }

    /**
     * POST 建立狀態
     */
    public function store(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $data = $this->request->only([
            'board_id', 'name', 'slug', 'color', 'sort_order',
            'is_public', 'allow_drag_in', 'allow_drag_out',
        ]);

        $validator = Validator::make($data, [
            'board_id' => 'required|integer',
            'name'     => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data['board_id'] = (int) $data['board_id'];
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_public'] = isset($data['is_public']) ? 1 : 0;
        $data['allow_drag_in'] = isset($data['allow_drag_in']) ? 1 : 0;
        $data['allow_drag_out'] = isset($data['allow_drag_out']) ? 1 : 0;

        $this->stageService->create($data);

        $this->redirectWith('/admin/stages', 'success', '狀態建立成功');
    }

    /**
     * 編輯狀態表單
     */
    public function edit(): void
    {
        $id = (int) $this->request->param('id');
        $stage = $this->stageService->findById($id);

        if (!$stage) {
            $this->backWithError('狀態不存在');
        }

        $boards = $this->stageService->getAllBoards();

        $this->render('admin/stages/edit', [
            'title'  => '編輯狀態',
            'stage'  => $stage,
            'boards' => $boards,
        ]);
    }

    /**
     * POST 更新狀態
     */
    public function update(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');
        $data = $this->request->only([
            'name', 'slug', 'color', 'sort_order',
            'is_public', 'allow_drag_in', 'allow_drag_out',
        ]);

        $validator = Validator::make($data, [
            'name' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            $this->backWithError($validator->firstError());
        }

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_public'] = isset($data['is_public']) ? 1 : 0;
        $data['allow_drag_in'] = isset($data['allow_drag_in']) ? 1 : 0;
        $data['allow_drag_out'] = isset($data['allow_drag_out']) ? 1 : 0;

        $this->stageService->update($id, $data);

        $this->redirectWith('/admin/stages', 'success', '狀態更新成功');
    }

    /**
     * POST 刪除狀態（先檢查有無卡片）
     */
    public function destroy(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->backWithError('CSRF 驗證失敗，請重新操作');
        }

        $id = (int) $this->request->param('id');

        try {
            $this->stageService->delete($id);
            $this->redirectWith('/admin/stages', 'success', '狀態已刪除');
        } catch (\RuntimeException $e) {
            $this->backWithError($e->getMessage());
        }
    }

    /**
     * AJAX POST — 批次更新排序
     */
    public function reorder(): void
    {
        if (!Csrf::validate($this->request->input('_csrf_token'))) {
            $this->json(['success' => false, 'message' => 'CSRF 驗證失敗'], 403);
            return;
        }

        $order = $this->request->input('order');
        if (!is_array($order)) {
            $this->json(['success' => false, 'message' => '無效的排序資料'], 422);
            return;
        }

        $this->stageService->reorder($order);

        $this->json(['success' => true, 'message' => '排序已更新']);
    }
}
