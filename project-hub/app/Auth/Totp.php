<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

/**
 * RFC 6238 TOTP（HMAC-SHA1、6 位數、30 秒），與 Google Authenticator 相容。
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const SECRET_BYTES = 20;

    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $counter = pack('J', $step); // 8 bytes big-endian
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 驗證驗證碼，回傳符合的時間步；不符合回傳 null。
     *
     * 時間步必須大於 $lastUsedStep：同一組驗證碼（或更早的驗證碼）不能再次使用，防止重放。
     * 呼叫端仍須以條件式 UPDATE 寫回時間步，才能在併發請求下保證只成功一次。
     */
    public static function match(string $secret, string $input, int $window, ?int $lastUsedStep, int $now): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $input)) {
            return null;
        }
        $current = self::step($now);
        $matched = null;
        // 掃描整個容許範圍且不提早結束，讓比對時間不因符合位置而不同
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $input) && $matched === null) {
                $matched = $step;
            }
        }
        return $matched;
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => Base32::encode($secret),
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** 以每 4 字一組顯示，方便手動輸入。 */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split(Base32::encode($secret), 4, ' '));
    }
}
