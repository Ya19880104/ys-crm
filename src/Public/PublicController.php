<?php

declare(strict_types=1);

namespace YangSheep\CRM\Public;

use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Database;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Auth\TurnstileVerifier;

class PublicController extends Controller
{
    private Database $db;
    private TurnstileVerifier $turnstileVerifier;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->db = Database::getInstance();
        $this->turnstileVerifier = new TurnstileVerifier();
    }

    /**
     * 公開首頁 — YS CRM 歡迎頁（前台僅顯示歡迎與登入入口）
     */
    public function home(): void
    {
        $this->view->layout('auth');
        $this->render('public/home', [
            'title' => 'YS CRM',
        ]);
    }
}
