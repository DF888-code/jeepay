<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use ProjectHub\App;
use RuntimeException;

/**
 * 依附件記錄的 storage_driver 取得對應的儲存實作。
 * 每個檔案記住自己的位置，切換預設儲存位置後舊檔仍從原處讀取，不需搬移。
 */
final class StorageManager
{
    public const DRIVERS = ['local', GoogleDriveStorage::DRIVER];

    private ?GoogleDrive $drive = null;

    public function __construct(private App $app)
    {
    }

    public function forDriver(string $driver): FileStorage
    {
        return match ($driver) {
            'local' => new LocalFileStorage($this->app->storagePath() . '/uploads'),
            GoogleDriveStorage::DRIVER => new GoogleDriveStorage($this->drive(), $this->app->storagePath() . '/tmp'),
            default => throw new RuntimeException('不支援的附件儲存位置：' . $driver),
        };
    }

    /** 新上傳檔案使用的儲存位置；設定為 Google Drive 但尚未連結時回傳錯誤訊息，不偷偷改存本機。 */
    public function forUploads(): FileStorage|string
    {
        $driver = $this->app->settings->string('attachments.storage');
        if ($driver === GoogleDriveStorage::DRIVER && !$this->drive()->connected()) {
            return '附件儲存位置設定為 Google Drive，但尚未連結 Google 帳號，請聯絡最高權限管理者。';
        }
        return $this->forDriver($driver);
    }

    public function drive(): GoogleDrive
    {
        return $this->drive ??= new GoogleDrive($this->app->settings, $this->app->secrets, $this->app->serviceEndpoints());
    }

    /**
     * 附件記錄的讀取代號：本機檔案沿用 stored_name；外部儲存使用 storage_ref。
     *
     * @param array<string, mixed> $att
     */
    public static function ref(array $att, bool $preview = false): ?string
    {
        $ref = $preview ? ($att['preview_storage_ref'] ?? null) : ($att['storage_ref'] ?? null);
        $name = $preview ? $att['preview_stored_name'] : $att['stored_name'];
        return is_string($ref) && $ref !== '' ? $ref : (is_string($name) ? $name : null);
    }
}
