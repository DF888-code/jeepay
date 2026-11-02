<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use RuntimeException;

/**
 * 把附件存到 Google Drive（決議 #33）。讀取時先下載到 storage/tmp 的暫存檔，
 * 經 PHP 輸出給已通過雙重授權的使用者後立即刪除，主機上不保留副本。
 */
final class GoogleDriveStorage implements FileStorage
{
    public const DRIVER = 'google_drive';

    public function __construct(private GoogleDrive $drive, private string $tmpDir)
    {
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function put(string $name, string $sourcePath): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $name)) {
            throw new RuntimeException('附件名稱不合法');
        }
        $id = $this->drive->upload($name, $sourcePath);
        // 上傳成功後刪除主機上的暫存檔（PHP 的上傳暫存檔會自動刪除，圖片處理產生的需自行刪除）
        if (!is_uploaded_file($sourcePath)) {
            @unlink($sourcePath);
        }
        return $id;
    }

    public function open(string $ref): ?StoredFile
    {
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0700, true);
        }
        $tmp = $this->tmpDir . '/dl-' . bin2hex(random_bytes(16));
        if (!$this->drive->download($ref, $tmp)) {
            return null;
        }
        return new StoredFile($tmp, (int)filesize($tmp), true);
    }

    public function delete(string $ref): void
    {
        $this->drive->delete($ref);
    }
}
