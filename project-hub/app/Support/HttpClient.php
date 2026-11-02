<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use RuntimeException;

/**
 * 以 cURL 呼叫外部 HTTPS API（Google Drive、Brevo）。
 *
 * 注意：InfinityFree 單一請求最多 60 秒，因此逾時設為 30 秒，留時間給頁面回應；
 * 一律驗證 TLS 憑證，不提供關閉驗證的選項。
 */
final class HttpClient
{
    public function __construct(private int $timeout = 30)
    {
    }

    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    /**
     * @param list<string> $headers
     * @param string|null $sinkPath 指定時回應內容直接寫入檔案（下載大檔不佔記憶體）
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $sinkPath = null): array
    {
        if (!self::available()) {
            throw new RuntimeException('主機未啟用 cURL，無法連線外部服務');
        }
        $ch = curl_init($url);
        $sink = null;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($sinkPath !== null) {
            $sink = fopen($sinkPath, 'wb');
            if ($sink === false) {
                throw new RuntimeException('無法建立暫存檔');
            }
            $options[CURLOPT_FILE] = $sink;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($sink !== null) {
            fclose($sink);
        }
        if ($result === false) {
            // 錯誤訊息只含連線問題描述，不含網址參數或 Token
            throw new RuntimeException('外部服務連線失敗：' . $error);
        }
        $text = is_string($result) ? $result : '';
        if ($sinkPath !== null && $status >= 400) {
            // 錯誤時回應內容是 JSON 說明，讀回來供判斷；成功時檔案由呼叫端使用
            $text = (string)file_get_contents($sinkPath, false, null, 0, 65536);
        }
        return ['status' => $status, 'body' => $text];
    }
}
