<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Quote\QuoteService;
use YangSheep\CRM\Payment\PaymentRepository;
use YangSheep\CRM\Website\WebsiteService;
use YangSheep\CRM\Hosting\HostingService;

/**
 * 客戶 Portal 總覽（自己的報價/付款/資產到期/未付款摘要）。
 *
 * 🔴 所有資料一律以 session 的 customer_id（CustomerGuard）scope；絕不接受前端傳入 id。
 */
class PortalDashboardController extends PortalController
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    public function index(): void
    {
        $customerId = $this->customerId();

        $quoteService   = new QuoteService();
        $websiteService = new WebsiteService();
        $hostingService = new HostingService();
        $paymentRepo    = new PaymentRepository();

        // 全部以 customer_id 強制 scope。
        $quotes   = $quoteService->findByCustomer($customerId);
        $payments = $paymentRepo->findByCustomer($customerId);
        $websites = $websiteService->findByCustomer($customerId);
        $hostings = $hostingService->findByCustomer($customerId);

        // 摘要統計（純伺服器端計算）。
        $pendingPayments = array_values(array_filter(
            $payments,
            static fn ($p) => in_array((string) ($p['status'] ?? ''), ['pending', 'awaiting_transfer'], true)
        ));
        $pendingAmount = 0.0;
        foreach ($pendingPayments as $p) {
            $pendingAmount += (float) ($p['amount'] ?? 0);
        }

        // 待簽署報價（sent / viewed）。
        $awaitingSign = array_values(array_filter(
            $quotes,
            static fn ($q) => in_array((string) ($q['status'] ?? ''), ['sent', 'viewed'], true)
        ));

        // 即將到期資產（60 天內）。
        $expiringAssets = $this->collectExpiring($websites, $hostings, 60);

        $this->renderPortal('portal/dashboard', [
            'title'           => '總覽',
            'quotes'          => $quotes,
            'payments'        => $payments,
            'pendingPayments' => $pendingPayments,
            'pendingAmount'   => $pendingAmount,
            'awaitingSign'    => $awaitingSign,
            'websiteCount'    => count($websites),
            'hostingCount'    => count($hostings),
            'expiringAssets'  => $expiringAssets,
            'quoteStatusLabels' => \YangSheep\CRM\Quote\QuoteController::statusLabels(),
        ]);
    }

    /**
     * 蒐集 $days 天內到期的資產（網站合約 / 主機租期），合併排序。
     *
     * @param array<int, array<string, mixed>> $websites
     * @param array<int, array<string, mixed>> $hostings
     * @return array<int, array{type: string, name: string, due: string}>
     */
    private function collectExpiring(array $websites, array $hostings, int $days): array
    {
        $today    = new \DateTimeImmutable('today');
        $deadline = $today->add(new \DateInterval('P' . $days . 'D'));
        $out = [];

        foreach ($websites as $w) {
            $due = $this->parseDate($w['contract_end'] ?? null);
            if ($due !== null && $due >= $today && $due <= $deadline) {
                $out[] = [
                    'type' => '網站',
                    'name' => (string) ($w['url'] ?? '網站'),
                    'due'  => $due->format('Y-m-d'),
                ];
            }
        }
        foreach ($hostings as $h) {
            $due = $this->parseDate($h['end_date'] ?? null);
            if ($due !== null && $due >= $today && $due <= $deadline) {
                $out[] = [
                    'type' => '主機',
                    'name' => (string) ($h['ip_address'] ?? '主機'),
                    'due'  => $due->format('Y-m-d'),
                ];
            }
        }

        usort($out, static fn ($a, $b) => strcmp($a['due'], $b['due']));
        return $out;
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $s = substr((string) ($value ?? ''), 0, 10);
        if ($s === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $dt !== false ? $dt->setTime(0, 0, 0) : null;
    }
}
