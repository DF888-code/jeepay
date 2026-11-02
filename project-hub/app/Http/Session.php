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

    /**
     * 登出或登入狀態失效時，清除 Session 中「所有」與使用者相關的資料，只保留提示訊息。
     *
     * 為什麼用白名單保留而不是逐項刪除：逐項刪除容易漏掉日後新增的狀態
     * （例如管理者再驗證標記曾因此在登出後殘留），保留清單則只有明確安全的項目。
     * CSRF Token 也一併更換，避免登出前取得的表單在新的登入狀態下仍可送出。
     */
    public function clearAuth(): void
    {
        $flash = $_SESSION['flash'] ?? null;
        $_SESSION = [];
        if ($flash !== null) {
            $_SESSION['flash'] = $flash;
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
