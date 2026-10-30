<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use RuntimeException;

/**
 * 伺服器端機密設定（config/config.php）。
 *
 * 為什麼不放資料庫：資料庫帳密與應用金鑰是解開其他機密的鑰匙，
 * 若與資料放在同一處，資料庫被整份匯出時就等於全部外洩。
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values)
    {
    }

    /** 設定檔不存在時回傳 null，由呼叫端顯示設定說明頁。 */
    public static function load(string $basePath): ?self
    {
        // 環境變數僅供測試使用；InfinityFree 無法設定環境變數，因此不影響正式環境
        $file = getenv('PH_CONFIG_FILE') ?: $basePath . '/config/config.php';
        if (!is_file($file)) {
            return null;
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new RuntimeException('config.php 必須回傳陣列');
        }
        return new self($values);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string)$v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool)$this->get($key, $default);
    }

    /** 應用金鑰（32 bytes 二進位）。 */
    public function appKey(): string
    {
        $key = base64_decode($this->string('app_key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_key 設定無效');
        }
        return $key;
    }

    /**
     * 列出設定問題（不含任何設定值本身），供設定說明頁顯示。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $key = base64_decode($this->string('app_key'), true);
        if ($key === false || strlen($key) !== 32) {
            $problems[] = 'app_key 未設定或格式錯誤（需為 32 bytes 的 Base64 字串）';
        }
        foreach (['db.host', 'db.name', 'db.user'] as $k) {
            if ($this->string($k) === '') {
                $problems[] = "{$k} 未設定";
            }
        }
        return $problems;
    }
}
