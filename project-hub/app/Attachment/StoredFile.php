<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

/**
 * 準備輸出給瀏覽器的檔案。外部儲存下載回來的暫存檔在輸出後（或請求結束時）刪除，
 * 不會在主機上留下副本。
 */
final class StoredFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        private bool $temporary,
    ) {
        if ($temporary) {
            // 使用者中途取消下載時 PHP 可能不會執行後續程式，用 shutdown 確保暫存檔被刪除
            register_shutdown_function(fn() => $this->release());
        }
    }

    public function stream(): void
    {
        $fp = @fopen($this->path, 'rb');
        if ($fp !== false) {
            while (!feof($fp)) {
                echo fread($fp, 65536);
                flush();
            }
            fclose($fp);
        }
        $this->release();
    }

    public function release(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
