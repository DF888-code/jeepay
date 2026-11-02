<?php

declare(strict_types=1);

namespace ProjectHub\Settings;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Crypto;
use ProjectHub\Support\Database;
use Throwable;

/**
 * 機密設定（寄信密碼、API 金鑰、Google 授權 Token）。
 *
 * - 以 app_key 衍生的子金鑰加密，且每個設定鍵使用不同的子金鑰（加密用途含鍵名），
 *   即使有人把某一列密文搬到另一個鍵，也無法解密；
 * - 只提供「是否已設定」給畫面，明文不回顯到瀏覽器，也不寫入任何紀錄。
 */
final class SecretSettings
{
    public function __construct(private Database $db, private Crypto $crypto)
    {
    }

    public function get(string $key): ?string
    {
        try {
            $blob = $this->db->value('SELECT value_enc FROM secret_settings WHERE setting_key = ?', [$key]);
        } catch (Throwable) {
            return null; // 遷移 0004 尚未套用
        }
        if (!is_string($blob)) {
            return null;
        }
        try {
            return $this->crypto->decrypt($blob, 'secret-setting:' . $key);
        } catch (Throwable) {
            // app_key 被更換時舊密文無法解開：視為未設定，請管理者重新輸入
            error_log('[project-hub] 機密設定無法解密（app_key 是否變更過？）：' . $key);
            return null;
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, string $value, ?int $userId): void
    {
        $this->db->run(
            'REPLACE INTO secret_settings (setting_key, value_enc, updated_by, updated_at) VALUES (?, ?, ?, ?)',
            [$key, $this->crypto->encrypt($value, 'secret-setting:' . $key), $userId, Clock::utc()]
        );
    }

    public function delete(string $key): void
    {
        $this->db->run('DELETE FROM secret_settings WHERE setting_key = ?', [$key]);
    }

    /** @return array<string, mixed>|null 以 JSON 保存的結構化機密（例如 Google 連線資訊） */
    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $value */
    public function setJson(string $key, array $value, ?int $userId): void
    {
        $this->set($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $userId);
    }
}
