<?php
declare(strict_types=1);

namespace YangSheep\CRM\Module;

use YangSheep\CRM\Core\Controller;

/** Registered only inside the existing settings permission + step-up group. */
final class ModuleController extends Controller
{
    public function index(): void
    {
        $this->response->header('Cache-Control', 'no-store');
        $this->render('admin/modules/index', [
            'title' => '功能模組盤點',
            'report' => (new ModuleReadinessService())->report(),
        ]);
    }

    public function data(): void
    {
        $this->response->header('Cache-Control', 'no-store');
        $this->json(['data' => (new ModuleReadinessService())->report()]);
    }
}
