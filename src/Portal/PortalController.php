<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Csrf;
use YangSheep\CRM\Core\Session;
use YangSheep\CRM\Portal\Auth\CustomerGuard;

/**
 * 客戶 Portal 基底控制器。
 *
 * 與管理員 Controller 的差異：render() 注入的是「客戶平面」的通用資料
 * （_customer = CustomerGuard 快照、_csrf、_flash），且預設套用 portal layout——
 * 絕不注入管理員 `_user` 或後台側欄看板（避免客戶頁誤帶後台導覽 / 資料）。
 *
 * 所有 portal 頁面控制器應繼承本類別，並一律以 CustomerGuard::customerId() 為查詢 scope。
 */
abstract class PortalController extends Controller
{
    /**
     * 渲染 portal 頁面（套 portal layout，注入客戶平面通用資料）。
     *
     * @param string               $template views 下相對路徑（如 'portal/dashboard'）
     * @param array<string, mixed> $data
     */
    protected function renderPortal(string $template, array $data = []): void
    {
        $data['_flash'] = [
            'success' => Session::getFlash('success'),
            'error'   => Session::getFlash('error'),
            'info'    => Session::getFlash('info'),
        ];
        $data['_csrf']     = Csrf::token();
        $data['_customer'] = CustomerGuard::context();
        $data['_can']      = static fn (string $scope): bool => CustomerGuard::can($scope);

        // 套 portal layout（與 admin / public 分離）。
        $this->view->layout('portal');
        $html = $this->view->render($template, $data);
        $this->response->html($html);
    }

    /**
     * 當前登入客戶 id（強制 scope 根據）。
     */
    protected function customerId(): int
    {
        return CustomerGuard::customerId();
    }

    /**
     * 當前登入的 customer_user id。
     */
    protected function customerUserId(): int
    {
        return CustomerGuard::id();
    }
}
