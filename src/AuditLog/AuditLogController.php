<?php

declare(strict_types=1);

namespace YangSheep\CRM\AuditLog;

use YangSheep\CRM\Auth\LoginAttemptService;
use YangSheep\CRM\Core\Controller;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\GeoIp\GeoIpService;

/**
 * 稽核紀錄檢視：操作紀錄與登入紀錄併為同一頁的兩個頁簽。
 *
 * 【為何併在一起】兩者回答的是同一個問題 ——「誰在什麼時候做了什麼」。
 * 分成兩個選單項目的話，查一件事要在兩頁之間來回跳，而且很容易只看了一半
 * （例如查到有人改了設定，卻沒去看那個時間點附近有沒有可疑的登入）。
 *
 * 【為何用 ?type= 而不是前端頁簽】兩邊各有自己的篩選與分頁。做成前端切換
 * 就得一次載入兩份資料（各 30 筆 + 各自的總數），而且分頁狀態會消失在網址之外，
 * 上一頁與分享連結都會失效。伺服器端頁簽讓網址就是狀態。
 */
class AuditLogController extends Controller
{
    private AuditLogService $service;
    private LoginAttemptService $attempts;
    private GeoIpService $geo;

    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
        $this->service  = new AuditLogService();
        $this->attempts = new LoginAttemptService();
        $this->geo      = new GeoIpService();
    }

    public function index(): void
    {
        $type = (string) $this->request->query('type', 'audit');
        if (!in_array($type, ['audit', 'login'], true)) {
            $type = 'audit';
        }

        $page    = max(1, (int) $this->request->query('page', 1));
        $perPage = 30;

        $view = [
            'title'      => '稽核紀錄',
            'type'       => $type,
            'page'       => $page,
            'perPage'    => $perPage,
            'breadcrumb' => [['label' => '稽核紀錄']],
            // 兩個頁簽各自的篩選值都要帶回去，切換頁簽時才不會把對方的條件洗掉
            'action'     => trim((string) $this->request->query('action', '')),
            'result'     => trim((string) $this->request->query('result', '')),
            'keyword'    => trim((string) $this->request->query('keyword', '')),
        ];

        if ($type === 'login') {
            $res   = $this->attempts->history($page, $perPage, [
                'result'  => $view['result'],
                'keyword' => $view['keyword'],
            ]);
            $total = (int) $res['total'];

            $view += [
                'attempts'       => $res['items'],
                'logs'           => [],
                'total'          => $total,
                'totalPages'     => max(1, (int) ceil($total / $perPage)),
                'locations'      => $this->describeLocations($res['items']),
                'clientIpIsReal' => $this->request->clientIpIsReal(),
                'geoEnabled'     => $this->geo->isEnabled(),
            ];
        } else {
            $filters = $view['action'] !== '' ? ['action' => $view['action']] : null;
            $res     = $this->service->findAll($page, $perPage, $filters);
            $total   = (int) ($res['total'] ?? 0);

            $view += [
                'logs'           => $res['items'] ?? [],
                'attempts'       => [],
                'total'          => $total,
                'totalPages'     => max(1, (int) ceil($total / $perPage)),
                'locations'      => [],
                'clientIpIsReal' => $this->request->clientIpIsReal(),
                'geoEnabled'     => $this->geo->isEnabled(),
            ];
        }

        $this->view->layout('admin');
        $this->render('admin/audit-logs/index', $view);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{kind: string, label: string, detail: string}>
     */
    private function describeLocations(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ip = (string) ($r['ip_address'] ?? '');
            if ($ip !== '' && !isset($out[$ip])) {
                $out[$ip] = $this->geo->describe($ip);
            }
        }
        return $out;
    }
}
