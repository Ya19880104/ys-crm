<?php

declare(strict_types=1);

namespace YangSheep\CRM\ApiConfig;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;

/**
 * API 設定管理 — CRUD stub
 *
 * 未來擴充用，目前僅留空殼。
 */
class ApiConfigController extends Controller
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    /**
     * API 設定列表
     */
    public function index(): void
    {
        $this->view->layout('admin');
        $this->render('admin/settings/index', [
            'title' => 'API 設定',
        ]);
    }

    /**
     * 新增 API 設定
     */
    public function create(): void
    {
        // TODO: 實作 API 設定新增頁面
    }

    /**
     * 儲存 API 設定
     */
    public function store(): void
    {
        // TODO: 實作 API 設定儲存
    }

    /**
     * 編輯 API 設定
     */
    public function edit(): void
    {
        // TODO: 實作 API 設定編輯頁面
    }

    /**
     * 更新 API 設定
     */
    public function update(): void
    {
        // TODO: 實作 API 設定更新
    }

    /**
     * 刪除 API 設定
     */
    public function destroy(): void
    {
        // TODO: 實作 API 設定刪除
    }
}
