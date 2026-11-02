<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

/**
 * 附件實體檔案的儲存介面：本機（LocalFileStorage）與 Google Drive（GoogleDriveStorage，決議 #33）。
 *
 * 不論存在哪裡，檔案都只能經由 PHP 控制器重新做雙重授權後讀出（決議 #25），
 * 因此介面不提供「取得公開網址」之類的方法。
 */
interface FileStorage
{
    /** 寫入 note_attachments.storage_driver 的代號 */
    public function driver(): string;

    /**
     * 把暫存檔存入儲存空間。$name 只能是 64 位十六進位隨機字串。
     *
     * @return string 之後讀取／刪除用的檔案代號（本機為 $name，Google Drive 為檔案 ID）
     */
    public function put(string $name, string $sourcePath): string;

    /** 取得可讀取的檔案；檔案已不存在時回傳 null。外部儲存會先下載到暫存檔。 */
    public function open(string $ref): ?StoredFile;

    public function delete(string $ref): void;
}
