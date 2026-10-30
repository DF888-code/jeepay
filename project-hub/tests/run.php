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
$appKey = random_bytes(32);
$installToken = bin2hex(random_bytes(20));
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'base_url' => '',
    'force_https' => false,
    'app_key' => base64_encode($appKey),
    'install_token' => $installToken,
    'db' => $db,
    'storage_dir' => $storage,
    'session_cookie' => 'phsid',
], true) . ';');

$port = 18000 + random_int(0, 999);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'w'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    null,
    array_merge(getenv(), ['PH_CONFIG_FILE' => $tmp . '/config.php'])
);
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port);
    if ($fp !== false) {
        fclose($fp);
        break;
    }
    usleep(100_000);
}

$GLOBALS['PH_CTX'] = [
    'base' => "http://127.0.0.1:{$port}",
    'pdo' => $pdo,
    'db' => $db,
    'appKey' => $appKey,
    'installToken' => $installToken,
    'storage' => $storage,
];

try {
    require __DIR__ . '/Integration/AuthFlowTest.php';
    $failures += run_registered_tests('整合測試：登入、2FA 與帳號設定');
} finally {
    proc_terminate($server);
    @unlink($tmp . '/config.php');
}

$serverLog = (string)@file_get_contents($tmp . '/server.log');
if (preg_match_all('/\[project-hub\][^\n]*/', $serverLog, $m) && $m[0] !== []) {
    echo "伺服器錯誤日誌：\n  " . implode("\n  ", array_unique($m[0])) . "\n";
}

echo $failures === 0 ? "全部通過\n" : "共 {$failures} 項失敗\n";
exit($failures > 0 ? 1 : 0);
