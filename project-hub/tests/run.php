<?php

declare(strict_types=1);

/**
 * 測試入口。
 *
 *   php tests/run.php            只跑單元測試（不需資料庫）
 *   PH_TEST_DB_HOST=127.0.0.1 PH_TEST_DB_NAME=ph_it_test PH_TEST_DB_USER=ph PH_TEST_DB_PASS=*** php tests/run.php
 *                                另外跑整合測試：會清空並重建該資料庫的所有資料表，名稱必須含 test
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/Browser.php';

date_default_timezone_set('UTC');
$failures = 0;

require __DIR__ . '/Unit/AuthPrimitivesTest.php';
require __DIR__ . '/Unit/SearchNormalizerTest.php';
require __DIR__ . '/Unit/S3SignerTest.php';
require __DIR__ . '/Unit/TrustedProxyTest.php';
$failures += run_registered_tests('單元測試');

$dbName = (string)getenv('PH_TEST_DB_NAME');
if ($dbName === '') {
    echo "未設定 PH_TEST_DB_*，略過整合測試。\n";
    exit($failures > 0 ? 1 : 0);
}
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "整合測試會清空資料庫，PH_TEST_DB_NAME 必須包含 test。\n");
    exit(2);
}

$db = [
    'host' => (string)(getenv('PH_TEST_DB_HOST') ?: '127.0.0.1'),
    'port' => (int)(getenv('PH_TEST_DB_PORT') ?: 3306),
    'name' => $dbName,
    'user' => (string)getenv('PH_TEST_DB_USER'),
    'pass' => (string)getenv('PH_TEST_DB_PASS'),
];
$pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("DROP TABLE `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// 獨立的設定檔與 storage 目錄，不影響開發者本機的設定
$tmp = __DIR__ . '/tmp';
$storage = $tmp . '/storage';
@mkdir($storage, 0700, true);
@unlink($storage . '/installed.lock');
$port = 18000 + random_int(0, 999);

// 假外部服務：Google OAuth／Drive、Brevo（HTTP）與 SMTP，讓整合測試不需連網也不需真實帳號
$fakeDir = $tmp . '/fake';
if (is_dir($fakeDir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fakeDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
@mkdir($fakeDir, 0700, true);
$fakePort = 19000 + random_int(0, 499);
$smtpPort = 19500 + random_int(0, 499);
$fakeServer = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$fakePort}", __DIR__ . '/Fakes/fake_services.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/fake.log', 'w'], 2 => ['file', $tmp . '/fake.log', 'a']],
    $fakePipes, null, array_merge(getenv(), ['FAKE_STATE_DIR' => $fakeDir])
);
$smtpServer = proc_open(
    [PHP_BINARY, __DIR__ . '/Fakes/fake_smtp.php', (string)$smtpPort, $fakeDir],
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/fake_smtp.log', 'w'], 2 => ['file', $tmp . '/fake_smtp.log', 'a']],
    $smtpPipes
);
$fakeBase = "http://127.0.0.1:{$fakePort}";

$appKey = random_bytes(32);
$installToken = bin2hex(random_bytes(20));
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'base_url' => "http://127.0.0.1:{$port}",
    'force_https' => false,
    'app_key' => base64_encode($appKey),
    'install_token' => $installToken,
    'db' => $db,
    'storage_dir' => $storage,
    'session_cookie' => 'phsid',
    'service_endpoints' => [
        'google_auth' => $fakeBase . '/auth',
        'google_token' => $fakeBase . '/token',
        'google_revoke' => $fakeBase . '/revoke',
        'google_api' => $fakeBase . '/drive/v3',
        'google_upload' => $fakeBase . '/upload/drive/v3',
        'brevo' => $fakeBase . '/brevo',
        'r2' => $fakeBase . '/r2',
    ],
], true) . ';');



$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'w'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    null,
    array_merge(getenv(), ['PH_CONFIG_FILE' => $tmp . '/config.php'])
);
foreach ([$port, $fakePort, $smtpPort] as $p) {
    for ($i = 0; $i < 50; $i++) {
        // SMTP 假伺服器一次只服務一個連線，因此只確認 HTTP 伺服器；SMTP 等待其啟動即可
        if ($p === $smtpPort) {
            usleep(300_000);
            break;
        }
        $fp = @fsockopen('127.0.0.1', $p);
        if ($fp !== false) {
            fclose($fp);
            break;
        }
        usleep(100_000);
    }
}

$GLOBALS['PH_CTX'] = [
    'base' => "http://127.0.0.1:{$port}",
    'pdo' => $pdo,
    'db' => $db,
    'appKey' => $appKey,
    'installToken' => $installToken,
    'storage' => $storage,
    'fakeDir' => $fakeDir,
    'fakeBase' => $fakeBase,
    'smtpPort' => $smtpPort,
];

try {
    require __DIR__ . '/Integration/AuthFlowTest.php';
    $failures += run_registered_tests('整合測試：登入、2FA 與帳號設定');
    require __DIR__ . '/Integration/AdminTest.php';
    $failures += run_registered_tests('整合測試：管理後台與全域權限（階段 4）');
    require __DIR__ . '/Integration/ProjectTest.php';
    $failures += run_registered_tests('整合測試：專案與雙重授權（階段 5）');
    require __DIR__ . '/Integration/ContentTest.php';
    $failures += run_registered_tests('整合測試：專案說明、關鍵字、隨手記（階段 6a）');
    require __DIR__ . '/Integration/AttachmentTest.php';
    $failures += run_registered_tests('整合測試：隨手記附件（階段 6b）');
    require __DIR__ . '/Integration/SearchTest.php';
    $failures += run_registered_tests('整合測試：全站搜尋與簡繁轉換（階段 7）');
    require __DIR__ . '/Integration/LogTest.php';
    $failures += run_registered_tests('整合測試：歷史操作紀錄（階段 8）');
    require __DIR__ . '/Integration/SettingsTest.php';
    $failures += run_registered_tests('整合測試：系統設定中心、寄信、Google Drive（階段 9）');
    require __DIR__ . '/Integration/R2Test.php';
    $failures += run_registered_tests('整合測試：Cloudflare R2 附件儲存');
    require __DIR__ . '/Integration/RecoveryTest.php';
    $failures += run_registered_tests('整合測試：最高權限管理者 Email 救援（階段 10）');
    require __DIR__ . '/Integration/SecurityTest.php';
    $failures += run_registered_tests('整合測試：全站安全掃描（階段 12）');
} finally {
    proc_terminate($server);
    proc_terminate($fakeServer);
    proc_terminate($smtpServer);
    @unlink($tmp . '/config.php');
}

$serverLog = (string)@file_get_contents($tmp . '/server.log');
if (preg_match_all('/\[project-hub\][^\n]*/', $serverLog, $m) && $m[0] !== []) {
    echo "伺服器錯誤日誌：\n  " . implode("\n  ", array_unique($m[0])) . "\n";
}

echo $failures === 0 ? "全部通過\n" : "共 {$failures} 項失敗\n";
exit($failures > 0 ? 1 : 0);
