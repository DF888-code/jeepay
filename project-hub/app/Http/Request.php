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
        private array $server
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
            $_SERVER
        );
    }

    /** 只接受字串；陣列或其他型別一律視為空字串，避免型別混淆。 */
    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function query(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
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
