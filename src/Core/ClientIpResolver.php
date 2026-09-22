<?php

declare(strict_types=1);

namespace YangSheep\CRM\Core;

/**
 * 訪客真實 IP 的偵測策略。
 *
 * 【為什麼需要「選模式」而不是自動猜】
 * 部署在防火牆（來源 NAT）與反向代理後面時，PHP 看到的
 * `REMOTE_ADDR` 永遠是代理本身的位址。真實 IP 只有在前端設備把它寫進某個
 * HTTP header 時才拿得到，而**每種設備寫的 header 名稱不一樣**
 * （Cloudflare 是 CF-Connecting-IP、多數反向代理是 X-Forwarded-For、
 * nginx 常用 X-Real-IP、Akamai 是 True-Client-IP…）。
 *
 * 程式無法可靠地「自動」判斷哪一個可信 —— 因為那些 header 全都由請求方送來，
 * 猜錯的代價是「攻擊者說自己是誰就是誰」。所以：由人選，而且要讓人**選得對**。
 *
 * 這個類別的設計對照 Wordfence 的做法：提供有限的幾種模式，並且能回報
 * 「用每一種模式，現在這個請求會得到什麼 IP」。管理員拿自己已知的公網 IP
 * 一比就知道該選哪個，不必懂原理。
 *
 * 🔴 **「由右數第 N 段」這道防線只對 X-Forwarded-For 成立**。
 * 其餘四種模式的 header 只有單一值，取到的就是請求方送來的那一段 ——
 * 若前端設備不覆寫該欄位，等於完全沒有防線。詳見 OVERWRITE_ONLY_MODES。
 *
 * 🔴 **預設是 remote_addr（不採信任何 header）**。在還沒確認前端會插入 header 前，
 * 採信 header 比不採信更糟 —— 實測（2026-09-02）舊版無條件採信 XFF，
 * 導致每次換一個偽造值就能完全繞過登入速率限制。
 */
final class ClientIpResolver
{
    /**
     * 可選模式 => [header 名稱, 顯示標籤, 說明]
     * header 為 null 代表直接用 REMOTE_ADDR。
     */
    public const MODES = [
        'remote_addr' => [
            'header' => null,
            'label'  => 'REMOTE_ADDR（預設，最安全）',
            'desc'   => '直接使用 PHP 看到的連線來源。不採信任何 HTTP header，無法被偽造。'
                      . '若站台在反向代理後面，這裡會是代理的位址而非訪客位址。',
        ],
        'x_forwarded_for' => [
            'header' => 'X-Forwarded-For',
            'label'  => 'X-Forwarded-For',
            'desc'   => '最通用的反向代理／負載平衡器欄位。多層代理時以逗號串接，'
                      . '本系統取「由右數第 N 段」（N = 下方的信任層數）。',
        ],
        'x_real_ip' => [
            'header' => 'X-Real-IP',
            'label'  => 'X-Real-IP',
            'desc'   => 'nginx 反向代理常用。通常只有單一值。',
        ],
        'cf_connecting_ip' => [
            'header' => 'CF-Connecting-IP',
            'label'  => 'CF-Connecting-IP（Cloudflare）',
            'desc'   => '站台在 Cloudflare 後方時使用。⚠️ 僅在請求確實來自 Cloudflare 時才可信，'
                      . '請一併把 Cloudflare 的位址範圍填入下方的信任來源。',
        ],
        'true_client_ip' => [
            'header' => 'True-Client-IP',
            'label'  => 'True-Client-IP（Akamai／Cloudflare Enterprise）',
            'desc'   => '部分 CDN 使用此欄位。',
        ],
        'custom' => [
            'header' => null,   // 由設定指定
            'label'  => '自訂 header',
            'desc'   => '前端設備使用其他欄位名稱時填在下方。',
        ],
    ];

    /**
     * 單值 header 的模式清單。
     *
     * 🔴🔴 【為何要特別標出來】類別註解說信任代理檢查「不是主要防線 ——
     * 真正擋偽造的是由右數第 N 段」。那句話**只對 X-Forwarded-For 成立**。
     *
     * X-Real-IP / CF-Connecting-IP / True-Client-IP / 自訂 header 都是覆寫式的
     * 單一值：header 裡只有一段，`count($parts) - $hops` 永遠取到第 0 段，
     * 而那一段完全由請求方決定。若前端設備並不覆寫該 header，
     * 攻擊者送 `-H 'CF-Connecting-IP: 任意值'` 就能：
     *   - 每次換一個值繞過所有 per-IP 限流（登入節流、報價單密碼節流）
     *   - 讓稽核紀錄與登入紀錄寫入他指定的來源位址
     *   - 讓 Session::bindAuth() 綁到偽造的位址
     *
     * 應用層無法分辨「代理寫入的」與「客戶端自己塞的」—— 兩者到達時完全一樣。
     * 所以這裡不假裝能擋，而是把風險標示出來，讓設定的人知道前提是什麼。
     */
    private const OVERWRITE_ONLY_MODES = ['x_real_ip', 'cf_connecting_ip', 'true_client_ip', 'custom'];

    /** 預設可信任的來源範圍（未自訂時採用）。 */
    private const DEFAULT_TRUSTED = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** @var array<string, mixed>|null 每請求記憶一次，避免每次呼叫都查 DB */
    private static ?array $configCache = null;

    /**
     * 解析當前請求的訪客 IP。
     *
     * @param array<string, mixed> $server 通常是 $_SERVER
     * @return array{ip: string, from_header: bool, mode: string, provenance: string, trusted_for_throttle: bool}
     */
    public static function resolve(array $server): array
    {
        $cfg        = self::config();
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $mode       = (string) $cfg['mode'];

        if ($mode === 'remote_addr' || !isset(self::MODES[$mode])) {
            return self::remoteResult($remoteAddr, 'remote_addr');
        }

        // 來源必須是信任的代理才看 header。
        // ⚠️ 在本站的拓樸下（所有請求都經同一個代理），這道檢查對所有人都成立，
        // 因此它不是主要防線 —— 真正擋偽造的是下面的「由右數第 N 段」。
        if (!self::isTrustedProxy($remoteAddr, $cfg['trusted'])) {
            return self::remoteResult($remoteAddr, $mode);
        }

        $headerName = $mode === 'custom'
            ? (string) $cfg['custom_header']
            : (string) (self::MODES[$mode]['header'] ?? '');

        if (trim($headerName) === '') {
            return self::remoteResult($remoteAddr, $mode);
        }

        $candidate = self::pickFromHeader($server, $headerName, (int) $cfg['hops']);
        if ($candidate === null) {
            return self::remoteResult($remoteAddr, $mode);
        }

        $overwriteOnly = in_array($mode, self::OVERWRITE_ONLY_MODES, true);
        return [
            'ip'                   => $candidate,
            'from_header'          => true,
            'mode'                 => $mode,
            'provenance'           => $overwriteOnly ? 'overwrite_header' : 'trusted_forwarded_chain',
            'trusted_for_throttle' => !$overwriteOnly && self::isPublicIp($candidate),
        ];
    }

    /**
     * 診斷：列出每一種模式「現在」會得到什麼，供設定頁比對。
     *
     * 這是本功能最有價值的部分 —— 管理員不必理解 header 的差別，
     * 只要看哪一列等於自己已知的公網 IP 就好。
     *
     * @param array<string, mixed> $server
     * @return list<array{mode: string, label: string, desc: string, header: string, raw: string, ip: string, usable: bool, note: string}>
     */
    public static function diagnose(array $server): array
    {
        $cfg        = self::config();
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $out        = [];

        foreach (self::MODES as $mode => $meta) {
            $headerName = $mode === 'custom'
                ? (string) $cfg['custom_header']
                : (string) ($meta['header'] ?? '');

            if ($mode === 'remote_addr') {
                $out[] = [
                    'mode'      => $mode,
                    'label'     => (string) $meta['label'],
                    'desc'      => (string) $meta['desc'],
                    'header'    => '—',
                    'raw'       => $remoteAddr,
                    'ip'        => $remoteAddr,
                    'usable'    => true,
                    'forgeable' => false,   // 不採信任何 header，無從偽造
                    'warning'   => '',
                    'note'      => self::describeIp($remoteAddr),
                ];
                continue;
            }

            if (trim($headerName) === '') {
                $out[] = [
                    'mode' => $mode, 'label' => (string) $meta['label'], 'desc' => (string) $meta['desc'],
                    'header' => '（未設定）', 'raw' => '', 'ip' => '', 'usable' => false,
                    'forgeable' => true, 'warning' => '',
                    'note' => '尚未填入 header 名稱',
                ];
                continue;
            }

            $raw       = trim((string) ($server[self::serverKey($headerName)] ?? ''));
            $candidate = $raw === '' ? null : self::pickFromHeader($server, $headerName, (int) $cfg['hops']);

            $forgeable = in_array($mode, self::OVERWRITE_ONLY_MODES, true);

            $out[] = [
                'mode'      => $mode,
                'label'     => (string) $meta['label'],
                'desc'      => (string) $meta['desc'],
                'header'    => $headerName,
                'raw'       => $raw,
                'ip'        => $candidate ?? '',
                'usable'    => $candidate !== null,
                // 🔴 這一欄是設定頁最該讓人看到的資訊：選了單值 header 的模式，
                // 若前端設備沒有「覆寫」該 header，任何人都能自己填一個值進來。
                'forgeable' => $forgeable,
                'warning'   => $forgeable
                    ? '此 header 只有單一值，應用層無法分辨是前端設備寫的還是請求方自己塞的。'
                      . '唯有確認前端設備會「覆寫」（而非附加）此欄位時才可選用，'
                      . '否則任何人都能偽造來源位址並繞過所有以 IP 為基準的限流。'
                    : '',
                'note'   => $raw === ''
                    ? '這個請求沒有帶此 header —— 前端設備並未插入'
                    : ($candidate === null ? '有 header 但取不到合法 IP（檢查信任層數）' : self::describeIp($candidate)),
            ];
        }

        return $out;
    }

    /** 讓設定變更後立即生效（同一請求內改設定時使用）。 */
    public static function flushCache(): void
    {
        self::$configCache = null;
    }

    // ───────────────────────── 內部 ─────────────────────────

    /**
     * 取得設定。優先讀系統設定；讀不到（安裝流程／DB 尚未就緒）時退回 .env，
     * 再退回最安全的預設值。
     *
     * @return array{mode: string, custom_header: string, hops: int, trusted: list<string>}
     */
    private static function config(): array
    {
        if (self::$configCache !== null) {
            /** @var array{mode: string, custom_header: string, hops: int, trusted: list<string>} */
            return self::$configCache;
        }

        $mode    = '';
        $custom  = '';
        $hops    = 0;
        $trusted = '';

        try {
            $s       = new \YangSheep\CRM\Setting\SettingService();
            $mode    = trim((string) ($s->get('cdn', 'client_ip_mode') ?? ''));
            $custom  = trim((string) ($s->get('cdn', 'client_ip_header') ?? ''));
            $hops    = (int) ($s->get('cdn', 'client_ip_hops') ?? 0);
            $trusted = trim((string) ($s->get('cdn', 'trusted_proxies') ?? ''));
        } catch (\Throwable) {
            // DB 尚未就緒（安裝流程）→ 走 .env / 預設值。
        }

        if ($mode === '') {
            $mode = trim((string) ($_ENV['CLIENT_IP_MODE'] ?? 'remote_addr'));
        }
        if ($custom === '') {
            $custom = trim((string) ($_ENV['CLIENT_IP_HEADER'] ?? ''));
        }
        if ($hops < 1) {
            $hops = (int) ($_ENV['CLIENT_IP_TRUSTED_HOPS'] ?? 1);
        }
        if ($trusted === '') {
            $trusted = trim((string) ($_ENV['TRUSTED_PROXIES'] ?? ''));
        }

        $trustedList = $trusted === ''
            ? self::DEFAULT_TRUSTED
            : array_values(array_filter(array_map('trim', explode(',', $trusted)), static fn(string $v): bool => $v !== ''));

        self::$configCache = [
            'mode'          => isset(self::MODES[$mode]) ? $mode : 'remote_addr',
            'custom_header' => $custom,
            'hops'          => max(1, $hops),
            'trusted'       => $trustedList,
        ];

        /** @var array{mode: string, custom_header: string, hops: int, trusted: list<string>} */
        return self::$configCache;
    }

    /**
     * 從 header 取出訪客 IP：由右數第 $hops 段。
     *
     * 【為何不是最左段】XFF 的慣例是每經一跳往右附加。客戶端可以先塞一段假的：
     *     客戶端送           X-Forwarded-For: 1.2.3.4
     *     前端設備附加真實   X-Forwarded-For: 1.2.3.4, <真實>
     * 取最左段會拿到偽造值；取由右數第 1 段才是我方設備寫入的那一段。
     *
     * @param array<string, mixed> $server
     */
    private static function pickFromHeader(array $server, string $headerName, int $hops): ?string
    {
        $raw = trim((string) ($server[self::serverKey($headerName)] ?? ''));
        if ($raw === '') {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $v): bool => $v !== ''
        ));
        if ($parts === []) {
            return null;
        }

        // 段數不足代表設定與實際拓樸不符 —— 寧可回 null 讓呼叫端退回 REMOTE_ADDR，
        // 也不要去取一個可能由客戶端控制的位置。
        $index = count($parts) - max(1, $hops);
        if ($index < 0) {
            return null;
        }

        $candidate = $parts[$index];

        // 部分設備會帶上 port（1.2.3.4:56789）或 IPv6 方括號
        $candidate = preg_replace('/^\[(.+)\]$/', '$1', $candidate) ?? $candidate;
        if (substr_count($candidate, ':') === 1 && str_contains($candidate, '.')) {
            $candidate = explode(':', $candidate)[0];
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : null;
    }

    private static function serverKey(string $headerName): string
    {
        return 'HTTP_' . strtoupper(str_replace('-', '_', trim($headerName)));
    }

    /**
     * Header 不可用時，仍保留設定 mode 供診斷，但只以 remote address 作結果。
     * 只有明確選擇 remote_addr 模式時，公網 remote address 才代表直接連線來源。
     * Header 模式退回 REMOTE_ADDR 時，它可能只是未列入 trusted 清單的共享代理，
     * 因此只能用於診斷／稽核，不能悄悄升格成 throttle source。
     *
     * @return array{ip: string, from_header: bool, mode: string, provenance: string, trusted_for_throttle: bool}
     */
    private static function remoteResult(string $remoteAddr, string $mode): array
    {
        return [
            'ip'                   => $remoteAddr,
            'from_header'          => false,
            'mode'                 => $mode,
            'provenance'           => 'remote_addr',
            'trusted_for_throttle' => $mode === 'remote_addr' && self::isPublicIp($remoteAddr),
        ];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Match the deployment's trusted-proxy contract for every origin consumer.
     *
     * @param list<string> $trusted Exact IPs or IPv4/IPv6 CIDR blocks.
     */
    public static function isTrustedProxy(string $ip, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if (str_contains($entry, '/')) {
                if (self::inCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === $entry) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = array_pad(explode('/', $cidr, 2), 2, '');

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        if ($bitsRaw === '' || preg_match('/^\d+$/', $bitsRaw) !== 1) {
            return false;
        }
        $bits = (int) $bitsRaw;
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        if (!isset($ipBin[$bytes], $subnetBin[$bytes])) {
            return false;
        }

        $mask = ~((1 << (8 - $rem)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** 給人看的一句話：這個 IP 像不像真的訪客位址。 */
    private static function describeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '不是合法的 IP';
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        return $isPublic
            ? '公網位址 —— 看起來像真實訪客'
            : '私有／保留位址 —— 這是內網或代理，不是訪客位址';
    }
}
