<?php

declare(strict_types=1);

namespace YangSheep\CRM\Install;

/**
 * 將 installer 的狀態判定與 HTTP 入口分離，確保 DB 探測失敗一律 fail-closed。
 */
final class InstallationGate
{
    /**
     * @param callable():bool $databaseProbe true 代表已存在可登入的使用者
     */
    public static function classify(
        bool $environmentExists,
        bool $installedMarkerExists,
        bool $pendingMarkerExists,
        bool $pendingOwnedBySession,
        callable $databaseProbe
    ): InstallationState
    {
        // install.lock 是安裝完成時寫下的單向 marker。它與 .env 同時存在時，
        // 不應讓每個正常 HTTP request 再建立一條額外 DB 連線做探測。
        if ($environmentExists && $installedMarkerExists) {
            return InstallationState::Installed;
        }

        if (!$environmentExists) {
            // marker 存在但 .env 遺失是既有安裝損壞，不是新的匿名安裝機會。
            if ($installedMarkerExists) {
                return InstallationState::Indeterminate;
            }

            // begin() 先以 atomic create 取得 ownership、再寫 .env。若程序在兩者之間
            // 中斷，只有原 session 可以重試；其他匿名 session 一律 fail-closed。
            if ($pendingMarkerExists) {
                return $pendingOwnedBySession
                    ? InstallationState::Uninstalled
                    : InstallationState::Indeterminate;
            }

            return InstallationState::Uninstalled;
        }

        // 建立第一位管理員後 users > 0，但安裝尚未完成。只有持有 pending proof 的
        // 原 session 可繼續；不能讓全域 pending boolean 重新開放匿名 installer。
        if ($pendingMarkerExists) {
            return $pendingOwnedBySession
                ? InstallationState::Uninstalled
                : InstallationState::Indeterminate;
        }

        try {
            return $databaseProbe()
                ? InstallationState::Installed
                // begin() 會在寫入 .env 前先建立 owner-bound pending marker；因此
                // marker-less .env + empty users 不可能是合法的安裝中途狀態。
                // 這代表既有站台受損、資料被還原或 ownership proof 遺失，必須
                // 交給本機 recovery CLI 判定，不能向匿名 HTTP 重新開放 installer。
                : InstallationState::Indeterminate;
        } catch (\Throwable) {
            return InstallationState::Indeterminate;
        }
    }

    public static function mayServeInstaller(InstallationState $state): bool
    {
        return $state === InstallationState::Uninstalled;
    }
}
