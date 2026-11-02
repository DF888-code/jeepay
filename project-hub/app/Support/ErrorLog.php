<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use Throwable;

/**
 * 系統錯誤紀錄：寫入 storage/logs/errors-YYYY-MM.log（每行一筆 JSON）。
 *
 * 為什麼不只用 error_log()：InfinityFree 不提供 PHP 錯誤日誌給使用者查看，
 * 錯誤頁上的追蹤代碼就無從查起。寫在 storage/ 之下（.htaccess 全面拒絕直接存取），
 * 由最高權限管理者在後台依追蹤代碼查詢。
 *
 * 注意事項：
 * - 只記錄路徑，不記錄查詢字串、表單內容、Cookie（可能含 Token、密碼）；
 * - 堆疊只保留檔案與行號，不含函式參數；
 * - 檔案按月分開，只保留最近 3 個月，避免占用主機空間。
 */
final class ErrorLog
{
    private const KEEP_MONTHS = 3;
    private const MAX_READ_BYTES = 2_000_000;

    public function __construct(private string $dir)
    {
    }

    public function record(string $traceId, Throwable $e, string $method, string $path): void
    {
        $trace = [];
        foreach (array_slice($e->getTrace(), 0, 15) as $f) {
            if (isset($f['file'])) {
                $trace[] = $this->relative((string)$f['file']) . ':' . (int)($f['line'] ?? 0);
            }
        }
        $this->write([
            'time' => Clock::utc(),
            'id' => $traceId,
            'method' => $method,
            'path' => substr($path, 0, 300),
            'type' => get_class($e),
            'message' => mb_substr($e->getMessage(), 0, 1000),
            'at' => $this->relative($e->getFile()) . ':' . $e->getLine(),
            'trace' => $trace,
            'php' => PHP_VERSION,
        ]);
    }

    /** PHP 致命錯誤（記憶體不足、執行逾時等無法以 try/catch 捕捉的錯誤） */
    public function recordFatal(string $traceId, array $error, string $method, string $path): void
    {
        $this->write([
            'time' => Clock::utc(),
            'id' => $traceId,
            'method' => $method,
            'path' => substr($path, 0, 300),
            'type' => 'PHP 致命錯誤',
            'message' => mb_substr((string)($error['message'] ?? ''), 0, 1000),
            'at' => $this->relative((string)($error['file'] ?? '')) . ':' . (int)($error['line'] ?? 0),
            'trace' => [],
            'php' => PHP_VERSION,
        ]);
    }

    /**
     * 最近的錯誤（新到舊）。
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100, string $traceId = ''): array
    {
        $files = glob($this->dir . '/errors-*.log') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $file) {
            $size = (int)@filesize($file);
            $fp = @fopen($file, 'rb');
            if ($fp === false) {
                continue;
            }
            // 只讀檔案最後一段，避免大量錯誤時讀爆記憶體
            if ($size > self::MAX_READ_BYTES) {
                fseek($fp, -self::MAX_READ_BYTES, SEEK_END);
                fgets($fp);
            }
            $lines = [];
            while (($line = fgets($fp)) !== false) {
                $lines[] = $line;
            }
            fclose($fp);
            foreach (array_reverse($lines) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || ($traceId !== '' && ($row['id'] ?? '') !== $traceId)) {
                    continue;
                }
                $out[] = $row;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function write(array $row): void
    {
        try {
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                return;
            }
            $file = $this->dir . '/errors-' . gmdate('Y-m') . '.log';
            $isNew = !is_file($file);
            @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
            if ($isNew) {
                $this->purge();
            }
        } catch (Throwable) {
            // 寫錯誤紀錄失敗不能再引發錯誤
        }
    }

    private function purge(): void
    {
        $keep = [];
        for ($i = 0; $i < self::KEEP_MONTHS; $i++) {
            $keep[] = 'errors-' . gmdate('Y-m', strtotime("first day of -{$i} month")) . '.log';
        }
        foreach (glob($this->dir . '/errors-*.log') ?: [] as $file) {
            if (!in_array(basename($file), $keep, true)) {
                @unlink($file);
            }
        }
    }

    /** 只顯示專案內的相對路徑，不透露主機的完整目錄結構 */
    private function relative(string $file): string
    {
        $base = dirname(__DIR__, 2);
        return str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/') : basename($file);
    }
}
