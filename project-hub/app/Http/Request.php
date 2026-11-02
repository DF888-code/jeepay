<?php

declare(strict_types=1);

namespace ProjectHub\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = '/' . trim($path, '/');
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES
        );
    }

    /** 只接受字串；陣列或其他型別一律視為空字串，避免型別混淆。 */
    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? self::clean($v) : $default;
    }

    /** @return list<string> 陣列型輸入（例如 attachment_ids[]）；非字串元素一律忽略 */
    public function inputList(string $key): array
    {
        $v = $this->post[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int}|null 單一上傳檔案；多檔或格式不符時回傳 null */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !is_string($f['name'] ?? null) || !is_string($f['tmp_name'] ?? null)) {
            return null;
        }
        return ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'size' => (int)($f['size'] ?? 0), 'error' => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE)];
    }

    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? self::clean($v) : $default;
    }

    /**
     * 移除 NUL 字元：許多 PHP 函式（日期解析、檔案路徑）遇到 NUL 會丟出例外或截斷字串，
     * 正常的表單與網址參數不會含有 NUL，直接移除最安全。
     */
    private static function clean(string $v): string
    {
        return str_contains($v, "\0") ? str_replace("\0", '', $v) : $v;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string)($this->server[$key] ?? '');
    }

    public function ip(): string
    {
        return substr((string)($this->server['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public function userAgent(): string
    {
        return mb_substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        // 主機實測 $_SERVER['HTTPS'] 可正確反映 HTTPS，因此不信任任何代理標頭（X-Forwarded-Proto 可被偽造）
        $https = (string)($this->server['HTTPS'] ?? '');
        return ($https !== '' && strtolower($https) !== 'off') || (string)($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function host(): string
    {
        return (string)($this->server['HTTP_HOST'] ?? '');
    }

    public function uri(): string
    {
        return (string)($this->server['REQUEST_URI'] ?? '/');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD'], true);
    }
}
