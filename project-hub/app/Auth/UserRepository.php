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

    public function create(string $username, string $displayName, ?string $email, string $passwordHash, bool $superAdmin, ?int $createdBy, bool $mustChangePassword = false): int
    {
        $now = Clock::utc();
        $this->db->run(
            'INSERT INTO users (username, username_norm, display_name, email, password_hash, is_super_admin,
                                must_enroll_2fa, must_change_password, created_at, created_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [$username, self::normalizeUsername($username), $displayName, $email, $passwordHash, $superAdmin ? 1 : 0,
             $mustChangePassword ? 1 : 0, $now, $createdBy, $now]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    /**
     * 管理後台可管理的使用者：不含最高權限管理者（其帳號只能由本人在帳號設定與救援流程處理）。
     *
     * @return list<array<string, mixed>>
     */
    public function listManageable(): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.email, u.status, u.totp_enabled, u.must_enroll_2fa,
                    u.last_login_at, u.created_at,
                    g.can_view_projects, g.can_edit_projects, g.can_create_projects, g.can_delete_projects,
                    COALESCE(g.row_version, 0) AS row_version
               FROM users u
          LEFT JOIN user_global_permissions g ON g.user_id = u.id
              WHERE u.is_super_admin = 0
           ORDER BY u.status = \'active\' DESC, u.display_name, u.id'
        );
    }

    /** @return array<string, mixed>|null 只回傳可被管理後台操作的使用者 */
    public function findManageable(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ? AND is_super_admin = 0', [$id]);
    }

    public function updateProfile(int $id, string $displayName, ?string $email): void
    {
        $this->db->run('UPDATE users SET display_name = ?, email = ?, updated_at = ? WHERE id = ?', [$displayName, $email, Clock::utc(), $id]);
    }

    /** 停用時同時讓所有 Session 失效（雖然每個請求都會檢查 status，仍以版本號再保險一次）。 */
    public function setStatus(int $id, string $status): void
    {
        $this->db->run(
            'UPDATE users SET status = ?, auth_version = auth_version + 1, updated_at = ? WHERE id = ?',
            [$status, Clock::utc(), $id]
        );
    }

    /** 管理者重設密碼：設定臨時密碼、要求下次登入變更，並登出所有裝置。 */
    public function setTemporaryPassword(int $id, string $hash): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 1, auth_version = auth_version + 1, updated_at = ? WHERE id = ?',
            [$hash, Clock::utc(), $id]
        );
    }

    /** 本人完成強制變更密碼。 */
    public function completeRequiredPasswordChange(int $id, string $hash): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, auth_version = auth_version + 1, updated_at = ? WHERE id = ?',
            [$hash, Clock::utc(), $id]
        );
    }

    /** 讓此使用者的所有 Session 失效（單一裝置登入、登出其他裝置時使用）。 */
    public function bumpAuthVersion(int $id): int
    {
        $this->db->run('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$id]);
        return (int)$this->db->value('SELECT auth_version FROM users WHERE id = ?', [$id]);
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
