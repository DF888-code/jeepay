<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

use ProjectHub\Settings\Settings;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Crypto;
use ProjectHub\Support\Database;

/**
 * 登入節流：依帳號與 IP 計算失敗次數。
 *
 * 帳號以 HMAC 雜湊保存，不存原始字串（使用者可能誤把密碼打在帳號欄）。
 * 不存在的帳號也會被同樣計數與鎖定，避免透過鎖定行為判斷帳號是否存在。
 */
final class LoginThrottle
{
    public const STAGE_PASSWORD = 'password';
    public const STAGE_TOTP = 'totp';
    public const STAGE_REAUTH = 'reauth';

    public function __construct(private Database $db, private Settings $settings, private Crypto $crypto)
    {
    }

    public function accountKey(string $username): string
    {
        return $this->crypto->mac('login-throttle', UserRepository::normalizeUsername($username));
    }

    public function isLocked(string $accountKey, string $ip): bool
    {
        $since = Clock::utc(Clock::now() - $this->settings->int('security.login_lock_minutes') * 60);

        // 只有「完整登入成功」（通過 2FA）才重置帳號的失敗計數。
        // 若密碼正確也算重置，攻擊者在已知密碼時可反覆「密碼成功 → 猜驗證碼」繞過限制。
        $accountFailures = (int)$this->db->value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE account_key = ? AND succeeded = 0 AND attempted_at > ?
                AND attempted_at > COALESCE(
                    (SELECT MAX(s.attempted_at) FROM login_attempts s
                      WHERE s.account_key = ? AND s.succeeded = 1 AND s.stage = 'totp'),
                    '1970-01-01 00:00:00')",
            [$accountKey, $since, $accountKey]
        );
        if ($accountFailures >= $this->settings->int('security.login_max_failures')) {
            return true;
        }
        $ipFailures = (int)$this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        return $ipFailures >= $this->settings->int('security.login_ip_max_failures');
    }

    public function record(string $accountKey, string $ip, string $stage, bool $succeeded): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (account_key, ip, stage, succeeded, attempted_at) VALUES (?, ?, ?, ?, ?)',
            [$accountKey, $ip, $stage, $succeeded ? 1 : 0, Clock::utc()]
        );
        // 無 Cron：以低機率在請求中順便清理兩天前的資料，每次最多 500 筆，避免單次請求過久
        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM login_attempts WHERE attempted_at < ? ORDER BY id LIMIT 500', [Clock::utc(Clock::now() - 2 * 86400)]);
        }
    }
}
