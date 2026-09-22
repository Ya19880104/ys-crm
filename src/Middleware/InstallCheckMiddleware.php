<?php

declare(strict_types=1);

namespace YangSheep\CRM\Middleware;

use YangSheep\CRM\Core\Middleware;
use YangSheep\CRM\Core\Request;
use YangSheep\CRM\Core\Response;
use YangSheep\CRM\Install\InstallationMarkers;

class InstallCheckMiddleware extends Middleware
{
    public function handle(Request $request, callable $next, mixed ...$params): void
    {
        $installLockExists = (new InstallationMarkers())->completeExists();

        if (!$installLockExists) {
            (new Response())->redirect('/install');
            return;
        }

        $next();
    }
}
