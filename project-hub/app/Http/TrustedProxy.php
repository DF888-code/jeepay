<?php

declare(strict_types=1);

namespace ProjectHub\Http;

/**
 * 判斷使用者的真實 IP（網站前面有 Cloudflare、阿里雲高防等代理時）。
 *
 * 安全原則（各家代理的對接文件都要求）：只有在「連線確實來自信任的代理 IP」時，才採信代理附上的標頭；
 * 否則任何人自己在請求中加上 X-Forwarded-For 就能偽造 IP，繞過登入封鎖、污染歷史紀錄。
 *
 * 模式：
 * - direct：不信任任何標頭，直接使用 REMOTE_ADDR（沒有代理時）
 * - cloudflare：REMOTE_ADDR 屬於 Cloudflare 官方 IP 範圍（內建，另可加入自訂範圍）時，採用 CF-Connecting-IP
 * - aliyun：REMOTE_ADDR 屬於管理者填寫的回源 IP 段時，採用 X-Forwarded-For
 * - custom：REMOTE_ADDR 屬於管理者填寫的 IP 段時，採用管理者指定的標頭
 */
final class TrustedProxy
{
    public const MODES = ['direct', 'cloudflare', 'aliyun', 'custom'];

    /**
     * Cloudflare 官方 IP 範圍（https://www.cloudflare.com/ips/，2026-11 版本）。
     * Cloudflare 很少變動；若日後新增範圍，可在系統設定的「信任的代理 IP 段」補上，不必改程式。
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * @param list<string> $extraRanges 管理者設定的信任 IP 段
     */
    public function __construct(
        private string $mode,
        private array $extraRanges = [],
        private string $customHeader = '',
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @return array{ip:string, remote:string, trusted:bool, header:string, https:bool} 判斷結果（trusted：連線是否來自信任的代理）
     */
    public function resolve(array $server): array
    {
        $remote = substr((string)($server['REMOTE_ADDR'] ?? ''), 0, 45);
        $result = ['ip' => $remote, 'remote' => $remote, 'trusted' => false, 'header' => '', 'https' => false];
        if ($this->mode === 'direct' || !in_array($this->mode, self::MODES, true) || !$this->isTrusted($remote)) {
            return $result;
        }
        $result['trusted'] = true;
        $header = $this->headerName();
        $result['header'] = $header;
        $raw = (string)($server['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
        $ip = $header === 'X-Forwarded-For' ? $this->fromForwardedFor($raw) : self::validIp(trim($raw));
        if ($ip !== null) {
            $result['ip'] = $ip;
        }
        // 代理以 http 回源時（例如 Cloudflare「彈性」SSL、阿里雲 http 回源），由代理告知使用者原本是否使用 https
        $proto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $result['https'] = $proto === 'https';
        return $result;
    }

    public function headerName(): string
    {
        return match ($this->mode) {
            'cloudflare' => 'CF-Connecting-IP',
            'aliyun' => 'X-Forwarded-For',
            default => $this->customHeader !== '' ? $this->customHeader : 'X-Forwarded-For',
        };
    }

    private function isTrusted(string $remote): bool
    {
        $ranges = $this->extraRanges;
        if ($this->mode === 'cloudflare') {
            $ranges = array_merge(self::CLOUDFLARE_RANGES, $ranges);
        }
        foreach ($ranges as $range) {
            if (self::inRange($remote, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * X-Forwarded-For 是「使用者, 代理1, 代理2」的串列，前面的值可被使用者偽造。
     * 從最右邊往左找第一個「不是信任代理」的 IP，才是連到代理的真實使用者。
     */
    private function fromForwardedFor(string $raw): ?string
    {
        $parts = array_reverse(array_map('trim', explode(',', $raw)));
        foreach ($parts as $part) {
            $ip = self::validIp($part);
            if ($ip === null) {
                return null; // 格式異常：不採用，退回連線來源
            }
            if (!$this->isTrusted($ip)) {
                return $ip;
            }
        }
        return null;
    }

    private static function validIp(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /** IPv4／IPv6 CIDR 比對 */
    public static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string)$subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : (int)$bits;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * 解析管理者輸入的 IP 段（以逗號或空白分隔）。範圍過大（IPv4 小於 /8、IPv6 小於 /16）視為錯誤：
     * 等於信任整個網路，任何人都能偽造 IP。
     *
     * @return array{0: list<string>, 1: ?string} [IP 段清單, 錯誤訊息]
     */
    public static function parseRanges(string $input): array
    {
        $ranges = [];
        foreach (preg_split('/[\s,，、]+/u', trim($input)) ?: [] as $item) {
            if ($item === '') {
                continue;
            }
            [$subnet, $bits] = array_pad(explode('/', $item, 2), 2, null);
            if (filter_var($subnet, FILTER_VALIDATE_IP) === false || ($bits !== null && !ctype_digit($bits))) {
                return [[], "「{$item}」不是有效的 IP 或 IP 段（例如 203.0.113.0/24）。"];
            }
            $v6 = str_contains((string)$subnet, ':');
            $max = $v6 ? 128 : 32;
            $b = $bits === null ? $max : (int)$bits;
            if ($b > $max || $b < ($v6 ? 16 : 8)) {
                return [[], "「{$item}」範圍過大或不正確（IPv4 至少 /8、IPv6 至少 /16），等於信任大量陌生 IP。"];
            }
            $ranges[] = $subnet . '/' . $b;
        }
        return [array_values(array_unique($ranges)), null];
    }
}
