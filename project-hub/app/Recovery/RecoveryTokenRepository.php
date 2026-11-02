<?php

declare(strict_types=1);

namespace ProjectHub\Recovery;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 最高權限管理者救援 Token（docs/04 第 3 節）。
 *
 * - 資料庫只存 SHA-256，資料庫外洩也無法還原出可用的連結；
 * - 使用時以單一條件式 UPDATE 原子性標記 used_at，同一個 Token 同時送出兩次也只有一次成功；
 * - 「兩者皆遺失」的 Token 在冷卻時間結束前不能使用（created_at 條件）。
 */
final class RecoveryTokenRepository
{
    public const PURPOSES = ['reset_password', 'reset_2fa', 'reset_both'];

    public function __construct(private Database $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    /** 建立新 Token，同時讓同一位使用者同用途尚未使用的舊 Token 失效。 */
    public function create(int $userId, string $purpose, string $token, int $expiresAt, string $ip): void
    {
        $this->db->transaction(function () use ($userId, $purpose, $token, $expiresAt, $ip): void {
            $now = Clock::utc();
            $this->db->run(
                'UPDATE admin_recovery_tokens SET invalidated_at = ?
                  WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND invalidated_at IS NULL',
                [$now, $userId, $purpose]
            );
            $this->db->run(
                'INSERT INTO admin_recovery_tokens (user_id, purpose, token_hash, created_at, expires_at, request_ip) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $purpose, self::hash($token), $now, Clock::utc($expiresAt), $ip]
            );
        });
    }

    /** @return array<string, mixed>|null 依 Token 取得紀錄（不論狀態，供判斷失敗原因） */
    public function find(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        return $this->db->one('SELECT * FROM admin_recovery_tokens WHERE token_hash = ?', [self::hash($token)]);
    }

    /**
     * 判斷 Token 目前狀態。
     *
     * @param array<string, mixed>|null $row
     * @return string ok／not_found／used／invalidated／expired／cooling（冷卻中）
     */
    public static function status(?array $row, int $cooldownSeconds): string
    {
        if ($row === null) {
            return 'not_found';
        }
        if ($row['used_at'] !== null) {
            return 'used';
        }
        if ($row['invalidated_at'] !== null) {
            return 'invalidated';
        }
        if ((string)$row['expires_at'] <= Clock::utc()) {
            return 'expired';
        }
        if ($row['purpose'] === 'reset_both' && self::usableAt($row, $cooldownSeconds) > Clock::now()) {
            return 'cooling';
        }
        return 'ok';
    }

    /** @param array<string, mixed> $row */
    public static function usableAt(array $row, int $cooldownSeconds): int
    {
        $created = (int)strtotime((string)$row['created_at'] . ' UTC');
        return $row['purpose'] === 'reset_both' ? $created + $cooldownSeconds : $created;
    }

    /**
     * 原子性使用 Token：所有條件寫在同一個 UPDATE，影響 1 列才算成功。
     * 必須在交易中呼叫，與重設密碼／2FA 一起提交。
     */
    public function consume(string $token, string $purpose, int $cooldownSeconds): bool
    {
        $now = Clock::now();
        $createdBefore = $purpose === 'reset_both' ? Clock::utc($now - $cooldownSeconds) : Clock::utc($now);
        return $this->db->run(
            'UPDATE admin_recovery_tokens SET used_at = ?
              WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND invalidated_at IS NULL
                AND expires_at > ? AND created_at <= ?',
            [Clock::utc($now), self::hash($token), $purpose, Clock::utc($now), $createdBefore]
        )->rowCount() === 1;
    }

    /** 讓此使用者所有尚未使用的 Token 失效（救援完成、本人取消、變更救援 Email 時）。 */
    public function invalidateAll(int $userId): int
    {
        return $this->db->run(
            'UPDATE admin_recovery_tokens SET invalidated_at = ? WHERE user_id = ? AND used_at IS NULL AND invalidated_at IS NULL',
            [Clock::utc(), $userId]
        )->rowCount();
    }

    /** @return list<array<string, mixed>> 尚未使用、未失效、未過期的申請 */
    public function pending(int $userId): array
    {
        return $this->db->all(
            'SELECT id, purpose, created_at, expires_at, request_ip FROM admin_recovery_tokens
              WHERE user_id = ? AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > ? ORDER BY id DESC',
            [$userId, Clock::utc()]
        );
    }
}
