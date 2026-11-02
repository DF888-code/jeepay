<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use RuntimeException;

/**
 * 本機儲存：storage/uploads/ab/cd/{64 位十六進位名稱}。
 *
 * 安全設計：
 * - 檔名只接受 64 位十六進位，不可能出現「../」等路徑穿越；
 * - 檔案沒有副檔名，且目錄有 .htaccess 全面拒絕存取並關閉 PHP 執行，即使上傳內容是程式也不會被執行；
 * - 以兩層子目錄分散檔案，避免單一目錄檔案過多。
 */
final class LocalFileStorage implements FileStorage
{
    private const HTACCESS = <<<'HT'
# 上傳的附件只能透過 PHP 檢查權限後讀出，不可被直接存取或執行
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar
HT;

    public function __construct(private string $root)
    {
    }

    public function driver(): string
    {
        return 'local';
    }

    public function put(string $name, string $sourcePath): string
    {
        $target = $this->path($name);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('無法建立附件目錄');
        }
        $this->protectRoot();
        // 上傳的原始暫存檔用 move_uploaded_file；處理過的圖片（自行產生的暫存檔）用 rename
        $moved = is_uploaded_file($sourcePath) ? @move_uploaded_file($sourcePath, $target) : @rename($sourcePath, $target);
        if (!$moved) {
            throw new RuntimeException('無法儲存附件');
        }
        @chmod($target, 0600);
        return $name;
    }

    public function open(string $ref): ?StoredFile
    {
        $path = $this->path($ref);
        return is_file($path) ? new StoredFile($path, (int)filesize($path), false) : null;
    }

    public function delete(string $ref): void
    {
        $path = $this->path($ref);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $name)) {
            throw new RuntimeException('附件名稱不合法');
        }
        return $this->root . '/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . $name;
    }

    private function protectRoot(): void
    {
        $file = $this->root . '/.htaccess';
        if (!is_file($file)) {
            @file_put_contents($file, self::HTACCESS . "\n");
        }
    }
}
