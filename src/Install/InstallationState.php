<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

/**
 * 安裝狀態必須保留「無法判定」：資料庫故障不是一個新的安裝機會。
 */
enum InstallationState
{
    case Uninstalled;
    case Installed;
    case Indeterminate;
}
