<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use finfo;

/**
 * 上傳檔案的安全檢查與圖片處理。
 *
 * 檢查順序（任一失敗即拒絕）：
 * 1. 以 finfo 讀取檔案內容判斷實際 MIME，不相信瀏覽器提供的類型；
 * 2. 實際 MIME 必須在白名單內，且副檔名必須與該 MIME 相符（防止把程式偽裝成 .jpg，或把圖片改名成 .php）；
 * 3. 圖片必須能被 GD 實際解碼；JPG／PNG／WebP 一律重新編碼，移除 EXIF（含 GPS 位置）並破壞夾帶程式碼的偽裝檔；
 * 4. 圖片像素數有上限，避免「解壓縮炸彈」耗盡主機記憶體。
 */
final class UploadInspector
{
    /** 實際 MIME → [類別, 允許的副檔名, 顯示名稱] */
    public const WHITELIST = [
        'image/jpeg' => ['image', ['jpg', 'jpeg'], 'JPG 圖片'],
        'image/png' => ['image', ['png'], 'PNG 圖片'],
        'image/webp' => ['image', ['webp'], 'WebP 圖片'],
        'image/gif' => ['image', ['gif'], 'GIF 圖片'],
        'application/pdf' => ['file', ['pdf'], 'PDF'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['file', ['docx'], 'Word'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['file', ['xlsx'], 'Excel'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['file', ['pptx'], 'PowerPoint'],
        'application/msword' => ['file', ['doc'], 'Word（舊版）'],
        'application/vnd.ms-excel' => ['file', ['xls'], 'Excel（舊版）'],
        'application/vnd.ms-powerpoint' => ['file', ['ppt'], 'PowerPoint（舊版）'],
        'application/zip' => ['file', ['zip'], 'ZIP 壓縮檔'],
        'text/plain' => ['file', ['txt', 'log', 'md', 'csv'], '文字檔'],
        'text/csv' => ['file', ['csv'], 'CSV'],
    ];

    /** finfo 對部分格式可能回報的別名 */
    private const MIME_ALIASES = [
        'application/x-zip-compressed' => 'application/zip',
        'application/CDFV2' => 'application/msword',
        'application/x-ole-storage' => 'application/msword',
        'application/vnd.ms-office' => 'application/msword',
    ];

    /** 舊版 Office 檔（CDF 格式）finfo 不一定能區分 Word／Excel／PowerPoint，改以副檔名決定 */
    private const LEGACY_OFFICE = ['doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint'];

    /** Office Open XML 實際上是 ZIP，finfo 有時只回報 application/zip */
    private const OOXML = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public const MAX_PIXELS = 40_000_000;
    public const PREVIEW_MAX_EDGE = 1600;

    /**
     * @return array{mime:string, kind:string, ext:string}|string 通過時回傳偵測結果，否則回傳錯誤訊息
     */
    public static function inspect(string $path, string $originalName): array|string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '') {
            return '檔案必須有副檔名。';
        }
        $detected = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = self::MIME_ALIASES[$detected] ?? $detected;

        if ($mime === 'application/msword' && isset(self::LEGACY_OFFICE[$ext])) {
            $mime = self::LEGACY_OFFICE[$ext];
        }
        if (($mime === 'application/zip' || $mime === 'application/octet-stream') && isset(self::OOXML[$ext]) && self::isOoxml($path)) {
            $mime = self::OOXML[$ext];
        }
        if ($mime === 'text/plain' && $ext === 'csv') {
            $mime = 'text/csv';
        }
        if (!isset(self::WHITELIST[$mime])) {
            return '不支援這種檔案格式（偵測到：' . $detected . '）。';
        }
        [$kind, $exts] = self::WHITELIST[$mime];
        if (!in_array($ext, $exts, true)) {
            return '副檔名與檔案實際內容不符，已拒絕上傳。';
        }
        if ($ext === 'docx' || $ext === 'xlsx' || $ext === 'pptx') {
            if (self::hasMacros($path)) {
                return '不接受含巨集的 Office 檔案。';
            }
        }
        if ($mime === 'text/plain' || $mime === 'text/csv') {
            // 文字檔若包含 HTML／腳本標記，可能在某些情況被當成網頁解讀；下載時另會強制 attachment 與 nosniff
            $head = (string)file_get_contents($path, false, null, 0, 4096);
            if (preg_match('/<\s*(script|html|svg|iframe)\b/i', $head)) {
                return '文字檔內含網頁或腳本標記，已拒絕上傳。';
            }
        }
        return ['mime' => $mime, 'kind' => $kind, 'ext' => $ext];
    }

    /**
     * 驗證並重新編碼圖片，產生安全的原圖與預覽圖暫存檔。
     *
     * @return array{original:string, preview:string, preview_mime:string, width:int, height:int}|string
     */
    public static function processImage(string $path, string $mime, string $tmpDir): array|string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return '無法讀取圖片內容。';
        }
        [$w, $h] = $info;
        if ($w <= 0 || $h <= 0 || $w * $h > self::MAX_PIXELS) {
            return '圖片尺寸過大（上限約 4000 萬像素）。';
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
        if ($image === false) {
            return '圖片檔案損壞或格式不正確。';
        }
        if ($mime === 'image/jpeg') {
            $image = self::applyExifOrientation($image, $path);
        }
        $w = imagesx($image);
        $h = imagesy($image);

        // GIF 保留原檔（重新編碼會失去動畫），已通過 GD 解碼驗證；預覽圖另以 PNG 產生
        $original = $path;
        if ($mime !== 'image/gif') {
            $original = self::tempFile($tmpDir);
            if (!self::encode($image, $mime, $original)) {
                imagedestroy($image);
                return '圖片處理失敗。';
            }
        }

        $scale = min(1, self::PREVIEW_MAX_EDGE / max($w, $h));
        $pw = max(1, (int)round($w * $scale));
        $ph = max(1, (int)round($h * $scale));
        $preview = imagecreatetruecolor($pw, $ph);
        imagealphablending($preview, false);
        imagesavealpha($preview, true);
        imagecopyresampled($preview, $image, 0, 0, 0, 0, $pw, $ph, $w, $h);
        $previewMime = $mime === 'image/jpeg' ? 'image/jpeg' : 'image/png';
        $previewPath = self::tempFile($tmpDir);
        $ok = self::encode($preview, $previewMime, $previewPath);
        imagedestroy($preview);
        imagedestroy($image);
        if (!$ok) {
            return '圖片預覽產生失敗。';
        }
        return ['original' => $original, 'preview' => $previewPath, 'preview_mime' => $previewMime, 'width' => $w, 'height' => $h];
    }

    public static function label(string $mime): string
    {
        return self::WHITELIST[$mime][2] ?? $mime;
    }

    /** 允許的副檔名清單（提供給前端檔案選擇視窗的 accept 屬性，僅為使用體驗；實際檢查在伺服器）。 */
    public static function acceptAttribute(): string
    {
        $exts = [];
        foreach (self::WHITELIST as [, $list]) {
            foreach ($list as $e) {
                $exts['.' . $e] = true;
            }
        }
        return implode(',', array_keys($exts));
    }

    private static function encode(\GdImage $image, string $mime, string $target): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $target, 88),
            'image/png' => imagepng($image, $target, 6),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, $target, 85),
            default => false,
        };
    }

    /** 手機拍攝的 JPG 依 EXIF 方向旋轉；重新編碼後 EXIF 會被移除，因此需先轉正。 */
    private static function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated !== false && $rotated !== $image) {
            imagedestroy($image);
            return $rotated;
        }
        return $image;
    }

    private static function isOoxml(string $path): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        $ok = $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();
        return $ok;
    }

    /** OOXML 內含 vbaProject.bin 表示含巨集（例如副檔名被改成 .docx 的 .docm）。 */
    private static function hasMacros(string $path): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return true;
        }
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (stripos((string)$zip->getNameIndex($i), 'vbaproject.bin') !== false) {
                $found = true;
                break;
            }
        }
        $zip->close();
        return $found;
    }

    private static function tempFile(string $dir): string
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . bin2hex(random_bytes(16)) . '.tmp';
    }
}
