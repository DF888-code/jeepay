<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

/**
 * AWS Signature Version 4（S3 相容 API，Cloudflare R2 使用）。
 *
 * 為什麼自己實作而不用 AWS SDK：SDK 體積大且相依多，InfinityFree 也不能執行 Composer；
 * 本平台只需要 PUT／GET／DELETE 單一物件。正確性以 AWS 官方文件的測試向量驗證（tests/Unit）。
 */
final class S3Signer
{
    public function __construct(
        private string $accessKeyId,
        private string $secretKey,
        private string $region = 'auto',
        private string $service = 's3',
    ) {
    }

    /**
     * 產生簽章後的標頭（含 Authorization）。
     *
     * @param array<string, string> $headers 要一併簽署的標頭（名稱不分大小寫）
     * @return array<string, string>
     */
    public function sign(string $method, string $url, array $headers, string $payloadHash, int $timestamp): array
    {
        $parts = parse_url($url);
        $host = (string)$parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $date = gmdate('Ymd', $timestamp);

        $all = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        foreach ($headers as $name => $value) {
            $all[strtolower($name)] = trim($value);
        }
        ksort($all);
        $canonicalHeaders = '';
        foreach ($all as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($all));

        $canonical = implode("\n", [
            $method,
            self::canonicalUri((string)($parts['path'] ?? '/')),
            self::canonicalQuery((string)($parts['query'] ?? '')),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $toSign, $key);

        unset($all['host']); // Host 由 cURL 依網址送出
        $all['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        return $all;
    }

    /** 路徑每一段做 RFC 3986 編碼（保留斜線） */
    private static function canonicalUri(string $path): string
    {
        return implode('/', array_map(static fn(string $s): string => rawurlencode(rawurldecode($s)), explode('/', $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        sort($pairs);
        return implode('&', array_map(static fn(array $p): string => $p[0] . '=' . $p[1], $pairs));
    }
}
