<?php
declare(strict_types=1);
namespace YangSheep\CRM\Auth;

use YangSheep\CRM\Core\Database;

/** Serialize every shared rail across check/verify/record; never redirect inside callback. */
final class ThrottleAdmission
{
    public static function run(string $scope, string $identity, ?string $trustedIp, callable $callback): mixed
    {
        $db = Database::getInstance();
        $names = [$db->namedLockName('admission:' . $scope . ':identity:' . $identity)];
        if ($trustedIp !== null) {
            $names[] = $db->namedLockName('admission:' . $scope . ':ip:' . $trustedIp);
        }
        sort($names, SORT_STRING);
        $held = [];
        try {
            foreach (array_unique($names) as $name) {
                if ((int) $db->fetchColumn('SELECT GET_LOCK(:name, :timeout)', ['name'=>$name, 'timeout'=>5]) !== 1) {
                    throw new \RuntimeException('驗證服務忙碌，請稍後再試。');
                }
                $held[] = $name;
            }
            return $callback();
        } finally {
            foreach (array_reverse($held) as $name) {
                $db->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name'=>$name]);
            }
        }
    }
}
