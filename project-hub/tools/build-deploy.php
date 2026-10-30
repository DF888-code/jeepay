<?php

declare(strict_types=1);

/**
 * 產生可直接上傳到 InfinityFree 的部署包。
 *
 *   composer install --no-dev
 *   php tools/build-deploy.php
 *
 * 產出 dist/project-hub-htdocs.zip：在 InfinityFree File Manager 進入 htdocs，用「Upload & Unzip」上傳即可。
 *
 * 為什麼把 app/ 等目錄放進 htdocs：主機實測上層目錄不可寫，只能放在網站根目錄內，
 * 因此每個私有目錄都附 .htaccess 拒絕存取，根目錄 .htaccess 也再擋一次。
 * 安全注意：部署包永遠不包含 config/config.php、tests、tools、docs。
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
$dist = $root . '/dist';
$zipPath = $dist . '/project-hub-htdocs.zip';

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "找不到 vendor/，請先執行 composer install --no-dev\n");
    exit(1);
}
@mkdir($dist, 0755, true);
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "無法建立 {$zipPath}\n");
    exit(1);
}

$count = 0;
$add = static function (string $source, string $target) use ($zip, &$count): void {
    $zip->addFile($source, $target);
    $count++;
};
$addDir = static function (string $dir, string $prefix, array $exclude = []) use ($add, $root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $relative = substr($file->getPathname(), strlen($root . '/' . $dir) + 1);
        foreach ($exclude as $pattern) {
            if (fnmatch($pattern, $relative)) {
                continue 2;
            }
        }
        $add($file->getPathname(), $prefix . $relative);
    }
};

// 公開檔案放在 htdocs 根目錄
$addDir('public', '');
// 私有目錄
$addDir('app', 'app/');
$addDir('views', 'views/');
$addDir('database/migrations', 'database/migrations/');
$add($root . '/database/.htaccess', 'database/.htaccess');
// 套件可能以 git 原始碼安裝而帶有 .git 等隱藏目錄（數十 MB），一律排除
$addDir('vendor', 'vendor/', ['.*', '*/.*', '*/tests/*', '*/examples/*', '*/docs/*', '*.md']);
$add($root . '/app/.htaccess', 'vendor/.htaccess');
$add($root . '/config/.htaccess', 'config/.htaccess');
$add($root . '/config/config.example.php', 'config/config.example.php');
$add($root . '/storage/.htaccess', 'storage/.htaccess');

$zip->close();

// 再次確認部署包內沒有機密設定
$check = new ZipArchive();
$check->open($zipPath);
for ($i = 0; $i < $check->numFiles; $i++) {
    $name = (string)$check->getNameIndex($i);
    if ($name === 'config/config.php' || str_starts_with($name, 'tests/') || str_starts_with($name, 'tools/')) {
        fwrite(STDERR, "部署包包含不應上傳的檔案：{$name}\n");
        exit(1);
    }
}
$check->close();

printf("已產生 %s（%d 個檔案，%.1f KB）\n", $zipPath, $count, filesize($zipPath) / 1024);
