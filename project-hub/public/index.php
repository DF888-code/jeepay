<?php

declare(strict_types=1);

// 開發用 PHP 內建伺服器：靜態檔案直接交給伺服器處理
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

// 為什麼這樣找根目錄：開發時 index.php 在 public/ 內；部署到 InfinityFree 時
// public/ 的內容會與 app/ 等目錄一起放在 htdocs/（上層目錄不可寫），兩種結構都要能運作。
$base = is_dir(__DIR__ . '/app') ? __DIR__ : dirname(__DIR__);

// 主機預設時區為 America/New_York；系統一律以 UTC 運作，顯示時再依設定轉換
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require $base . '/vendor/autoload.php';

$app = new ProjectHub\App($base);
$app->handle(ProjectHub\Http\Request::fromGlobals())->send();
