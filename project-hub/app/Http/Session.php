<?php

declare(strict_types=1);

namespace ProjectHub\Http;

/**
 * 原生 PHP Session 的安全設定包裝。
 */
final class Session
{
    private bool $started = false;

    public function start(string $name, bool $secure, ?string $savePath, int $maxLifetimeSeconds): void
    {
        if ($this->started) {
            return;
        }
        ini_set('session.use_strict_mode', '1');   // 拒絕使用伺服器未發行過的 Session ID（防 Session 固定攻擊）
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)$maxLifetimeSeconds);
        if ($savePath !== null) {
            // 存在專案自己的 storage 目錄，不與共用主機上其他網站共用 /tmp
            session_save_path($savePath);
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** 清除登入相關狀態但保留 Session（以便顯示提示訊息與 CSRF Token）。 */
    public function clearAuth(): void
    {
        foreach (['uid', 'auth_version', 'stage', 'stage_at', 'login_at', 'last_seen', 'totp_change_until'] as $k) {
            unset($_SESSION[$k]);
        }
        $this->regenerate();
    }

    public function csrfToken(): string
    {
        $token = $_SESSION['csrf'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf'] = $token;
        }
        return $token;
    }

    public function validCsrf(string $token): bool
    {
        $expected = $_SESSION['csrf'] ?? null;
        return is_string($expected) && $token !== '' && hash_equals($expected, $token);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlash(): array
    {
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($items) ? array_values($items) : [];
    }
}
