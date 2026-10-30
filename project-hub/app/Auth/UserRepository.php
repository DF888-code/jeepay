<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /** 帳號格式：3–50 字元英數與 . _ -（建立帳號時使用；登入時不限制，以免洩漏規則差異）。 */
    public static function isValidUsername(string $username): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username_norm = ?', [self::normalizeUsername($username)]);
    }

    public function count(): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM users');
    }

    public function create(string $username, string $displayName, ?string $email, string $passwordHash, bool $superAdmin, ?int $createdBy): int
    {
        $now = Clock::utc();
        $this->db->run(
            'INSERT INTO users (username, username_norm, display_name, email, password_hash, is_super_admin,
                                must_enroll_2fa, created_at, created_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$username, self::normalizeUsername($username), $displayName, $email, $passwordHash, $superAdmin ? 1 : 0, $now, $createdBy, $now]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    /**
     * 原子性寫入最後使用的 TOTP 時間步：只有時間步嚴格大於既有值才成功。
     * 兩個請求同時送出同一組驗證碼時，只有一個會得到 true。
     */
    public function consumeTotpStep(int $userId, int $step): bool
    {
        $stmt = $this->db->run(
            'UPDATE users SET totp_last_used_step = ?
              WHERE id = ? AND (totp_last_used_step IS NULL OR totp_last_used_step < ?)',
            [$step, $userId, $step]
        );
        return $stmt->rowCount() === 1;
    }

    public function touchLogin(int $userId): void
    {
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [Clock::utc(), $userId]);
    }

    public function updatePasswordHash(int $userId, string $hash, bool $bumpAuthVersion): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, updated_at = ?'
            . ($bumpAuthVersion ? ', auth_version = auth_version + 1' : '')
            . ' WHERE id = ?',
            [$hash, Clock::utc(), $userId]
        );
    }

    public function setPendingTotp(int $userId, string $encryptedSecret, int $expiresAt): void
    {
        $this->db->run(
            'UPDATE users SET totp_pending_secret_enc = ?, totp_pending_expires_at = ?, updated_at = ? WHERE id = ?',
            [$encryptedSecret, Clock::utc($expiresAt), Clock::utc(), $userId]
        );
    }

    /**
     * 以「仍是同一把待確認 Secret 且未過期」為條件，原子性啟用新的 2FA。
     * 同時讓其他 Session 失效（auth_version + 1）。
     */
    public function activatePendingTotp(int $userId, string $expectedPendingEnc, int $usedStep): bool
    {
        $stmt = $this->db->run(
            'UPDATE users
                SET totp_secret_enc = totp_pending_secret_enc,
                    totp_enabled = 1,
                    must_enroll_2fa = 0,
                    totp_last_used_step = ?,
                    totp_pending_secret_enc = NULL,
                    totp_pending_expires_at = NULL,
                    auth_version = auth_version + 1,
                    updated_at = ?
              WHERE id = ? AND totp_pending_secret_enc = ? AND totp_pending_expires_at > ?',
            [$usedStep, Clock::utc(), $userId, $expectedPendingEnc, Clock::utc()]
        );
        return $stmt->rowCount() === 1;
    }

    /** 清除 2FA 並要求下次登入重新綁定（管理者重設、緊急工具使用）。 */
    public function resetTotp(int $userId): void
    {
        $this->db->run(
            'UPDATE users
                SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_used_step = NULL,
                    totp_pending_secret_enc = NULL, totp_pending_expires_at = NULL,
                    must_enroll_2fa = 1, auth_version = auth_version + 1, updated_at = ?
              WHERE id = ?',
            [Clock::utc(), $userId]
        );
    }
}
