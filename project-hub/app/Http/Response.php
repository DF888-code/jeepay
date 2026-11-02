<?php

declare(strict_types=1);

namespace ProjectHub\Http;

final class Response
{
    /** @var (callable(): void)|null 大型檔案以串流輸出，避免整份讀入記憶體 */
    private $streamer = null;

    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    /** @param array<string, string> $headers */
    public static function stream(callable $streamer, array $headers): self
    {
        $response = new self('', 200, $headers);
        $response->streamer = $streamer;
        return $response;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** 303：POST 之後以 GET 重新導向，避免重新整理時重送表單。 */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        // HEX 選項把 < > & ' " 轉成 \u003C 等：即使回應被誤當 HTML 解析，也不會出現可執行的標籤
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        // 不透露 PHP 版本（expose_php 只能在 php.ini 關閉，免費主機無法修改）
        header_remove('X-Powered-By');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->streamer !== null) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
