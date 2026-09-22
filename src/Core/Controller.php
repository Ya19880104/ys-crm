<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

use function YangSheep\CRM\Core\e;

abstract class Controller
{
    protected Request $request;
    protected Response $response;
    protected View $view;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
        $this->view = new View();
    }

    /**
     * 渲染模板並輸出
     */
    protected function render(string $template, array $data = []): void
    {
        // 注入通用資料
        $data['_flash'] = [
            'success' => Session::getFlash('success'),
            'error'   => Session::getFlash('error'),
            'info'    => Session::getFlash('info'),
        ];
        $data['_user'] = Session::get('user');
        $data['_csrf'] = Csrf::token();

        // 注入看板列表（側邊欄用）
        if ($data['_user'] && !isset($data['_boards_nav'])) {
            $db = Database::getInstance();
            $data['_boards_nav'] = $db->fetchAll(
                "SELECT id, name, type, slug FROM {prefix}boards WHERE is_active = 1 ORDER BY sort_order ASC"
            );
        }

        // 注入站台設定（OG 圖片等，供 layout 使用）
        if (!isset($data['_og_image'])) {
            try {
                $db = Database::getInstance();
                $siteSettings = $db->fetchAll(
                    "SELECT setting_key, setting_value FROM {prefix}settings WHERE setting_group = 'site'"
                );
                foreach ($siteSettings as $row) {
                    if ($row['setting_key'] === 'og_image' && $row['setting_value'] !== '') {
                        $data['_og_image'] = $row['setting_value'];
                    }
                    if ($row['setting_key'] === 'site_name' && $row['setting_value'] !== '') {
                        $data['_site_name'] = $row['setting_value'];
                    }
                    if ($row['setting_key'] === 'site_desc' && $row['setting_value'] !== '') {
                        $data['_site_desc'] = $row['setting_value'];
                    }
                    if ($row['setting_key'] === 'site_logo' && $row['setting_value'] !== '') {
                        $data['_site_logo'] = $row['setting_value'];
                    }
                }
            } catch (\Throwable) {
                // DB 尚未就緒時靜默跳過
            }
        }

        $html = $this->view->render($template, $data);
        $this->response->html($html);
    }

    /**
     * 回傳 JSON
     */
    protected function json(mixed $data, int $status = 200): void
    {
        $this->response->json($data, $status);
    }

    /**
     * 重新導向
     */
    protected function redirect(string $url): never
    {
        $this->response->redirect($url);
    }

    /**
     * 帶 flash 訊息的重新導向
     */
    protected function redirectWith(string $url, string $type, string $message): never
    {
        Session::flash($type, $message);
        $this->response->redirect($url);
    }

    /**
     * 回到前一頁並帶錯誤訊息
     */
    /**
     * 要求當前使用者具備指定權限，否則中止並回到安全頁面。
     *
     * 【為何 Controller 還要再檢查一次】
     * 路由群組的 middleware 通常只掛「模組層」權限（例如 customers.view）。
     * 若把該群組內的寫入路由也一併涵蓋，等於任何看得到資料的人都能改與刪。
     * 因此凡是會產生寫入的動作（store / update / destroy），都必須在此再檢查
     * 對應的細粒度權限（customers.create / customers.edit / customers.delete）。
     *
     * 稽核發現的實際情境：只有 customers.view 的自訂角色，取得合法 CSRF token 後
     * 仍可 POST 刪除客戶 —— 因為刪除路由與檢視路由掛在同一個群組權限之下。
     *
     * 【為何叫 guardPermission 而不是 requirePermission】
     * Job / Quote / Payment / Recurring 四個 Controller 已各自有 **private**
     * requirePermission()。父類別若用同名的 protected 方法，PHP 會因「子類別不得
     * 收緊繼承來的可見性」而在載入時 Fatal error —— 而且 `php -l` 檢查不出來，
     * 只有實際載入類別才會炸。改名是為了與那些既有實作共存。
     *
     * @param string $code     權限碼
     * @param string $fallback 無權限時導向的頁面
     */
    protected function guardPermission(string $code, string $fallback = '/admin/dashboard'): void
    {
        $user = \YangSheep\CRM\Core\Session::get('user');
        $uid  = (int) ($user['id'] ?? 0);

        if ($uid <= 0) {
            $this->redirect('/login');
        }

        if (!(new \YangSheep\CRM\Role\RoleService())->hasPermission($uid, $code)) {
            $this->redirectWith($fallback, 'error', '您沒有執行此操作的權限。');
        }
    }

    protected function backWithError(string $message): never
    {
        Session::flash('error', $message);
        $this->response->back();
    }
}
