<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

/**
 * 附件實體檔案的儲存介面。目前實作本機儲存；日後改用 S3 相容服務（如 Cloudflare R2）時，
 * 只需新增另一個實作並在設定中切換，權限檢查仍由 PHP 控制器負責（決議 #24、#25）。
 */
interface FileStorage
{
    public function driver(): string;

    /** 把暫存檔移入儲存空間。$name 只能是 64 位十六進位隨機字串。 */
    public function put(string $name, string $sourcePath): void;

    /** 將檔案內容直接輸出到瀏覽器（不經過記憶體整份讀入）。 */
    public function stream(string $name): void;

    public function exists(string $name): bool;

    public function delete(string $name): void;
}
