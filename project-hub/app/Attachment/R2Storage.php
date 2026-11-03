<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use ProjectHub\Settings\SecretSettings;
use ProjectHub\Settings\Settings;
use ProjectHub\Support\Clock;
use ProjectHub\Support\HttpClient;
use RuntimeException;

/**
 * Cloudflare R2（S3 相容 API）附件儲存。
 *
 * 安全設計：
 * - bucket 必須保持私有（不開啟 r2.dev 公開網址），檔案只能經 PHP 檢查雙重授權後讀出；
 * - API Token 只給「Object Read & Write」且只限這個 bucket；金鑰以 app_key 加密保存、不回顯；
 * - 物件名稱使用與資料庫相同的 64 位隨機名稱，不帶原始檔名與副檔名；
 * - 讀取時下載到 storage/tmp 暫存，輸出後立即刪除，主機上不保留副本。
 */
final class R2Storage implements FileStorage
{
    public const DRIVER = 'r2';
    public const ACCESS_KEY = 'r2.access_key_id';
    public const SECRET_KEY = 'r2.secret_access_key';

    /** @param array<string, string> $endpoints 只供測試以假服務取代 R2 網址 */
    public function __construct(
        private Settings $settings,
        private SecretSettings $secrets,
        private string $tmpDir,
        private array $endpoints = [],
        private HttpClient $http = new HttpClient(),
    ) {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    /** 帳號 ID、bucket、兩把金鑰都已設定 */
    public function configured(): bool
    {
        return $this->settings->string('storage.r2_account_id') !== '' && $this->settings->string('storage.r2_bucket') !== ''
            && $this->secrets->has(self::ACCESS_KEY) && $this->secrets->has(self::SECRET_KEY);
    }

    public function put(string $name, string $sourcePath): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $name)) {
            throw new RuntimeException('附件名稱不合法');
        }
        $body = file_get_contents($sourcePath);
        if ($body === false) {
            throw new RuntimeException('無法讀取暫存檔');
        }
        $res = $this->request('PUT', $name, $body, ['content-type' => 'application/octet-stream']);
        if ($res['status'] !== 200) {
            throw new DriveException(self::error('上傳', $res));
        }
        if (!is_uploaded_file($sourcePath)) {
            @unlink($sourcePath); // 圖片處理產生的暫存檔；PHP 上傳暫存檔會自動刪除
        }
        return $name;
    }

    public function open(string $ref): ?StoredFile
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ref)) {
            return null;
        }
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0700, true);
        }
        $tmp = $this->tmpDir . '/dl-' . bin2hex(random_bytes(16));
        $res = $this->request('GET', $ref, '', [], $tmp);
        if ($res['status'] === 404) {
            @unlink($tmp);
            return null;
        }
        if ($res['status'] !== 200) {
            @unlink($tmp);
            throw new DriveException(self::error('讀取', $res));
        }
        return new StoredFile($tmp, (int)filesize($tmp), true);
    }

    public function delete(string $ref): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ref)) {
            return;
        }
        $res = $this->request('DELETE', $ref, '');
        if ($res['status'] !== 204 && $res['status'] !== 200 && $res['status'] !== 404) {
            throw new DriveException(self::error('刪除', $res));
        }
    }

    /** 設定頁的「測試連線」：寫入、讀回、刪除一個測試物件，確認金鑰權限正確 */
    public function selfTest(): void
    {
        $key = '.ph-connection-test-' . bin2hex(random_bytes(6));
        $content = 'project-hub connection test ' . Clock::utc();
        $res = $this->request('PUT', $key, $content, ['content-type' => 'text/plain']);
        if ($res['status'] !== 200) {
            throw new DriveException(self::error('寫入測試檔', $res));
        }
        $res = $this->request('GET', $key, '');
        if ($res['status'] !== 200 || $res['body'] !== $content) {
            throw new DriveException(self::error('讀回測試檔', $res));
        }
        $res = $this->request('DELETE', $key, '');
        if ($res['status'] !== 204 && $res['status'] !== 200) {
            throw new DriveException(self::error('刪除測試檔', $res));
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    private function request(string $method, string $key, string $body, array $headers = [], ?string $sink = null): array
    {
        $account = $this->settings->string('storage.r2_account_id');
        $bucket = $this->settings->string('storage.r2_bucket');
        $access = $this->secrets->get(self::ACCESS_KEY);
        $secret = $this->secrets->get(self::SECRET_KEY);
        if ($account === '' || $bucket === '' || $access === null || $secret === null) {
            throw new DriveException('尚未完成 Cloudflare R2 設定');
        }
        $base = $this->endpoints['r2'] ?? "https://{$account}.r2.cloudflarestorage.com";
        $url = rtrim($base, '/') . '/' . rawurlencode($bucket) . '/' . rawurlencode($key);
        $signed = (new S3Signer($access, $secret))->sign($method, $url, $headers, hash('sha256', $body), Clock::now());
        $lines = [];
        foreach ($signed as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        try {
            return $this->http->request($method, $url, $lines, $method === 'PUT' ? $body : null, $sink);
        } catch (RuntimeException $e) {
            throw new DriveException($e->getMessage());
        }
    }

    /** @param array{status:int, body:string} $res */
    private static function error(string $action, array $res): string
    {
        // S3 錯誤回應是 XML：<Code>AccessDenied</Code>；只取代碼，不回傳其他內容
        $code = preg_match('#<Code>([A-Za-z]+)</Code>#', $res['body'], $m) ? $m[1] : '';
        $hint = match ($code) {
            'AccessDenied', 'SignatureDoesNotMatch', 'InvalidAccessKeyId', 'Unauthorized' => '（金鑰錯誤，或 API Token 沒有這個 bucket 的「Object Read & Write」權限）',
            'NoSuchBucket' => '（bucket 名稱錯誤或不存在）',
            default => '',
        };
        return "Cloudflare R2 {$action}失敗（HTTP {$res['status']}" . ($code !== '' ? "，{$code}" : '') . "）{$hint}";
    }
}
