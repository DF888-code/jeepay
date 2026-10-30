<?php

declare(strict_types=1);

/**
 * 整合測試用的 HTTP 用戶端：每個 Browser 物件有自己的 Cookie，模擬一個獨立的瀏覽器／裝置。
 * 不自動跟隨轉址，讓測試可以檢查每一步的狀態碼與 Location。
 */
final class Browser
{
    public int $status = 0;
    public string $body = '';
    /** @var array<string, list<string>> */
    public array $headers = [];
    private string $jar;

    public function __construct(private string $baseUrl)
    {
        $this->jar = (string)tempnam(sys_get_temp_dir(), 'phjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path, null);
    }

    /** @param array<string, string> $data 未提供 _csrf 時自動帶入上一頁的 CSRF Token */
    public function post(string $path, array $data, bool $withCsrf = true): self
    {
        if ($withCsrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        return $this->request('POST', $path, $data);
    }

    /** 送出表單：先 GET 表單頁取得 CSRF Token，再 POST。 */
    public function submit(string $formPath, string $action, array $data): self
    {
        $this->get($formPath);
        return $this->post($action, $data);
    }

    public function csrf(): string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m) ? $m[1] : '';
    }

    public function location(): string
    {
        return $this->headers['location'][0] ?? '';
    }

    public function header(string $name): string
    {
        return implode("\n", $this->headers[strtolower($name)] ?? []);
    }

    public function cookieValue(string $name): string
    {
        foreach ((array)file($this->jar) as $line) {
            $parts = explode("\t", trim((string)$line));
            if (count($parts) === 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }
        return '';
    }

    /** @param array<string, string>|null $data */
    private function request(string $method, string $path, ?array $data): self
    {
        $ch = curl_init($this->baseUrl . $path);
        $this->headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $this->headers[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $this->body = (string)curl_exec($ch);
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // 釋放 handle 時 curl 才會把 Cookie 寫入檔案，供 cookieValue() 讀取
        unset($ch);
        return $this;
    }
}
