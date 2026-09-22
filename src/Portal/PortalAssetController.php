<?php

declare(strict_types=1);

namespace YangSheep\CRM\Portal;

use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Website\WebsiteService;
use YangSheep\CRM\Hosting\HostingService;
use YangSheep\CRM\Asset\AssetHelper;

/**
 * 客戶 Portal 資產（自己的主機 + 網站，唯讀；含到期日）。
 *
 * 🔴 Zero Trust scope：一律以 session.customer_id 取資產；無任何寫入操作（純唯讀）。
 */
class PortalAssetController extends PortalController
{
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);
    }

    public function index(): void
    {
        $customerId = $this->customerId();

        $websites = (new WebsiteService())->findByCustomer($customerId);
        $hostings = (new HostingService())->findByCustomer($customerId);

        $this->renderPortal('portal/assets/index', [
            'title'             => '我的主機與網站',
            'websites'          => $websites,
            'hostings'          => $hostings,
            'assetStatusLabels' => AssetHelper::ASSET_STATUSES,
            'caseTypeLabels'    => AssetHelper::WEBSITE_CASE_TYPES,
            'hostingTypeLabels' => AssetHelper::HOSTING_TYPES,
        ]);
    }
}
