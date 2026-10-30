<?php
/**
 * Project Hub 主機環境檢查工具（階段 1）
 *
 * 用途：上傳到 InfinityFree（或其他候選主機）後實際檢查：
 *   1. PHP 版本與必要擴充套件（PDO、sodium、mbstring、intl……）
 *   2. 被停用的函式（exec、proc_open 等會影響 OpenCC 方案選擇）
 *   3. 密碼雜湊演算法（Argon2id / bcrypt）
 *   4. 對外連線：SMTP（25/465/587）、HTTPS API（443）、Aiven PostgreSQL 自訂 Port
 *   5. 選填：實際連線 MySQL／PostgreSQL 並取得版本
 *
 * 安全注意事項（為什麼這樣設計）：
 *   - 這支程式會揭露主機資訊並能發起對外連線，因此「必須」設定 ACCESS_KEY，
 *     未設定或金鑰錯誤一律回 404，避免被當成公開的探測器或 Port 掃描器。
 *   - 連線目標只能來自下方 $TARGETS 白名單，不接受網址參數任意指定。
 *   - 資料庫帳密只透過 POST 表單輸入、只在本次請求使用，不寫檔、不輸出、不記錄。
 *   - 檢查完成後請立即從主機刪除本檔案。
 */

declare(strict_types=1);

// ===== 上傳前請修改 =====================================================
// 請填入至少 32 字元的隨機字串，例如：php -r "echo bin2hex(random_bytes(24));"
const ACCESS_KEY = '';

// 對外連線測試白名單：[說明, 主機, Port, 是否先走 TLS]
// Aiven PostgreSQL 的主機與 Port 請依 Aiven 主控台實際值填寫。
$TARGETS = [
    ['SMTP 587（STARTTLS）- Gmail 範例', 'smtp.gmail.com', 587, false],
    ['SMTP 465（SMTPS）- Gmail 範例',    'smtp.gmail.com', 465, true],
    ['SMTP 25',                           'smtp.gmail.com', 25,  false],
    ['HTTPS 443 - Email API 範例（Brevo）', 'api.brevo.com', 443, true],
    ['HTTPS 443 - Email API 範例（Resend）', 'api.resend.com', 443, true],
    // ['Aiven PostgreSQL', 'xxxx.aivencloud.com', 12345, false],
];
// ======================================================================

const CONNECT_TIMEOUT = 6;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

if (PHP_SAPI !== 'cli') {
    $given = (string)($_POST['key'] ?? $_GET['key'] ?? '');
    if (strlen(ACCESS_KEY) < 32 || !hash_equals(ACCESS_KEY, $given)) {
        http_response_code(404);
        exit;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 錯誤訊息可能夾帶主機路徑或帳號，只保留前 200 字並去除換行。 */
function safe_error(string $msg): string
{
    return mb_substr(preg_replace('/\s+/', ' ', $msg) ?? '', 0, 200);
}

function check_extensions(): array
{
    // [擴充, 是否必要, 用途]
    $list = [
        ['pdo',        true,  '資料庫抽象層'],
        ['pdo_mysql',  true,  'MySQL／MariaDB 方案'],
        ['pdo_pgsql',  false, 'PostgreSQL（Aiven）方案；缺少時 Aiven 方案無法使用'],
        ['openssl',    true,  'SMTP TLS、HTTPS'],
        ['sodium',     true,  'TOTP Secret 加密（缺少時需改用 openssl AES-256-GCM）'],
        ['mbstring',   true,  '多位元組字串處理'],
        ['intl',       false, 'Normalizer（NFKC 標準化）；缺少時需退回自製全形轉半形'],
        ['json',       true,  'API 與設定值'],
        ['hash',       true,  'HMAC-SHA1（TOTP）、SHA-256（Token）'],
        ['session',    true,  '登入狀態'],
        ['curl',       false, 'HTTPS Email API'],
        ['gd',         false, '伺服器端產生 QR Code（亦可改用純 SVG 產生）'],
        ['fileinfo',   false, '日後若有檔案上傳'],
        ['zip',        false, '匯出壓縮'],
    ];
    $rows = [];
    foreach ($list as [$ext, $required, $purpose]) {
        $ok = extension_loaded($ext);
        $rows[] = [$ext, $ok ? 'OK' : ($required ? '缺少（必要）' : '缺少（選用）'), $purpose, $ok || !$required];
    }
    return $rows;
}

function check_runtime(): array
{
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    $interesting = ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru',
                    'fsockopen', 'stream_socket_client', 'mail', 'set_time_limit', 'ini_set'];
    $blocked = array_values(array_intersect($interesting, $disabled));

    $rows = [];
    $rows[] = ['PHP 版本', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
    $rows[] = ['SAPI', PHP_SAPI, true];
    $rows[] = ['OS', PHP_OS_FAMILY, true];
    $rows[] = ['Argon2id', defined('PASSWORD_ARGON2ID') ? '支援' : '不支援（將使用 bcrypt）', true];
    try {
        random_bytes(32);
        $rows[] = ['random_bytes()', '可用', true];
    } catch (Throwable $e) {
        $rows[] = ['random_bytes()', '不可用：' . safe_error($e->getMessage()), false];
    }
    $rows[] = ['停用的關鍵函式', $blocked ? implode(', ', $blocked) : '無', !in_array('stream_socket_client', $blocked, true)];
    $rows[] = ['allow_url_fopen', ini_get('allow_url_fopen') ? 'On' : 'Off', true];
    $rows[] = ['max_execution_time', (string)ini_get('max_execution_time'), true];
    $rows[] = ['memory_limit', (string)ini_get('memory_limit'), true];
    $rows[] = ['post_max_size', (string)ini_get('post_max_size'), true];
    $rows[] = ['upload_max_filesize', (string)ini_get('upload_max_filesize'), true];
    $rows[] = ['session.save_handler', (string)ini_get('session.save_handler'), true];
    $rows[] = ['date.timezone', (string)ini_get('date.timezone') ?: '(未設定)', true];
    $rows[] = ['display_errors', (string)ini_get('display_errors'), true];
    $rows[] = ['HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '是' : '否／未知（可能在反向代理後）', true];

    // 檢查網站根目錄的上一層是否可寫：決定設定檔與金鑰能否放在 Web 根目錄之外
    $parent = dirname(__DIR__, 1);
    $rows[] = ['目前目錄可寫', is_writable(__DIR__) ? '是' : '否', true];
    $rows[] = ['上層目錄可寫（放置機密設定用）', is_writable($parent) ? '是' : '否（機密設定需放在 Web 根目錄內並以 .htaccess 拒絕存取）', true];
    return $rows;
}

/**
 * 測試 TCP／TLS 連線並讀取伺服器開頭訊息（SMTP banner）。
 * 只做連線與讀取第一行，不送出任何帳密。
 */
function probe(string $host, int $port, bool $tls): array
{
    if (!function_exists('stream_socket_client')) {
        return [false, 'stream_socket_client 被停用'];
    }
    $start = microtime(true);
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $uri = ($tls ? 'tls://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($uri, $errno, $errstr, CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    $ms = (int)round((microtime(true) - $start) * 1000);
    if ($fp === false) {
        return [false, "連線失敗（{$ms} ms）：" . safe_error("[$errno] $errstr")];
    }
    stream_set_timeout($fp, CONNECT_TIMEOUT);
    $banner = '';
    if ($port !== 443) {
        $banner = (string)fgets($fp, 256);
    }
    fclose($fp);
    $msg = "連線成功（{$ms} ms）";
    if ($banner !== '') {
        $msg .= '，回應：' . safe_error($banner);
    }
    return [true, $msg];
}

function db_probe(string $driver, string $host, string $port, string $name, string $user, string $pass, bool $sslRequire): array
{
    if (!in_array($driver, ['mysql', 'pgsql'], true)) {
        return [false, '不支援的 driver'];
    }
    if (!extension_loaded('pdo_' . $driver)) {
        return [false, "pdo_{$driver} 擴充未安裝"];
    }
    $dsn = $driver === 'mysql'
        ? "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4"
        : "pgsql:host={$host};port={$port};dbname={$name}" . ($sslRequire ? ';sslmode=require' : '');
    $start = microtime(true);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => CONNECT_TIMEOUT,
        ]);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $connMs = (int)round((microtime(true) - $start) * 1000);
        // 量測 10 次簡單查詢的平均延遲：跨區資料庫每頁多次查詢時會被放大
        $t = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            $pdo->query('SELECT 1')->fetchColumn();
        }
        $avg = round((microtime(true) - $t) * 100, 1);
        $extra = '';
        if ($driver === 'mysql') {
            $extra = '；ngram_token_size=' . (string)($pdo->query("SHOW VARIABLES LIKE 'ngram_token_size'")->fetch(PDO::FETCH_NUM)[1] ?? '(無 ngram，可能是 MariaDB)');
        }
        return [true, "連線成功（{$connMs} ms），版本：" . safe_error($version) . "；單次查詢平均 {$avg} ms" . $extra];
    } catch (Throwable $e) {
        // PDO 錯誤訊息可能包含主機與帳號，僅輸出錯誤代碼避免洩漏
        return [false, '連線失敗，錯誤代碼：' . h((string)$e->getCode())];
    }
}

// ----------------------------------------------------------------------
$extRows = check_extensions();
$rtRows = check_runtime();
$netRows = [];
$dbResult = null;

$runNet = PHP_SAPI === 'cli' || isset($_POST['run_net']);
if ($runNet) {
    foreach ($TARGETS as [$label, $host, $port, $tls]) {
        [$ok, $msg] = probe($host, (int)$port, (bool)$tls);
        $netRows[] = [$label, "{$host}:{$port}", $msg, $ok];
    }
}
if (PHP_SAPI !== 'cli' && isset($_POST['run_db'])) {
    $dbResult = db_probe(
        (string)($_POST['driver'] ?? ''),
        (string)($_POST['host'] ?? ''),
        (string)($_POST['port'] ?? ''),
        (string)($_POST['dbname'] ?? ''),
        (string)($_POST['user'] ?? ''),
        (string)($_POST['pass'] ?? ''),
        isset($_POST['ssl'])
    );
}

if (PHP_SAPI === 'cli') {
    // CLI 模式：方便在本機或有 SSH 的主機上快速比對
    foreach ([['擴充套件', $extRows], ['執行環境', $rtRows]] as [$title, $rows]) {
        echo "== {$title} ==\n";
        foreach ($rows as $r) {
            echo ' [' . (end($r) ? ' OK ' : 'FAIL') . '] ' . $r[0] . ': ' . $r[1] . "\n";
        }
    }
    echo "== 對外連線 ==\n";
    foreach ($netRows as $r) {
        echo ' [' . ($r[3] ? ' OK ' : 'FAIL') . "] {$r[0]} ({$r[1]}): {$r[2]}\n";
    }
    exit;
}

$key = h(ACCESS_KEY);
?><!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>主機環境檢查</title>
<style>
body{font-family:system-ui,-apple-system,"Noto Sans TC",sans-serif;margin:0;padding:16px;background:#f6f7f9;color:#1d2330}
h1{font-size:1.3rem}h2{font-size:1.05rem;margin-top:28px}
table{border-collapse:collapse;width:100%;background:#fff}
th,td{border:1px solid #d8dce3;padding:6px 8px;text-align:left;vertical-align:top;word-break:break-word}
.ok{color:#137333;font-weight:600}.ng{color:#b3261e;font-weight:600}.opt{color:#a15c00;font-weight:600}
form{background:#fff;border:1px solid #d8dce3;padding:12px;margin-top:8px}
label{display:block;margin:6px 0}input,select{padding:6px;max-width:100%}
.warn{background:#fff4e5;border:1px solid #f0c27b;padding:10px}
</style>
</head>
<body>
<h1>Project Hub 主機環境檢查</h1>
<p class="warn">檢查完成後請立即從主機刪除本檔案。結果請截圖或複製後，回報到開發紀錄。</p>

<h2>1. PHP 擴充套件</h2>
<table><tr><th>擴充</th><th>狀態</th><th>用途</th></tr>
<?php foreach ($extRows as [$n, $s, $p, $ok]): ?>
<tr><td><?= h($n) ?></td><td class="<?= $s === 'OK' ? 'ok' : ($ok ? 'opt' : 'ng') ?>"><?= h($s) ?></td><td><?= h($p) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>2. 執行環境</h2>
<table><tr><th>項目</th><th>值</th></tr>
<?php foreach ($rtRows as [$n, $v, $ok]): ?>
<tr><td><?= h($n) ?></td><td class="<?= $ok ? '' : 'ng' ?>"><?= h($v) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>3. 對外連線（SMTP／HTTPS API／外部資料庫 Port）</h2>
<form method="post">
<input type="hidden" name="key" value="<?= $key ?>">
<button type="submit" name="run_net" value="1">執行對外連線測試</button>
</form>
<?php if ($netRows): ?>
<table><tr><th>目標</th><th>位址</th><th>結果</th></tr>
<?php foreach ($netRows as [$l, $a, $m, $ok]): ?>
<tr><td><?= h($l) ?></td><td><?= h($a) ?></td><td class="<?= $ok ? 'ok' : 'ng' ?>"><?= h($m) ?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<h2>4. 資料庫連線測試（選填）</h2>
<form method="post" autocomplete="off">
<input type="hidden" name="key" value="<?= $key ?>">
<label>Driver <select name="driver"><option value="mysql">MySQL／MariaDB</option><option value="pgsql">PostgreSQL（Aiven）</option></select></label>
<label>Host <input name="host" required></label>
<label>Port <input name="port" value="3306" required></label>
<label>Database <input name="dbname" required></label>
<label>User <input name="user" required></label>
<label>Password <input name="pass" type="password"></label>
<label><input type="checkbox" name="ssl" checked> PostgreSQL 使用 sslmode=require</label>
<button type="submit" name="run_db" value="1">測試連線</button>
</form>
<?php if ($dbResult !== null): ?>
<p class="<?= $dbResult[0] ? 'ok' : 'ng' ?>"><?= $dbResult[0] ? h($dbResult[1]) : $dbResult[1] ?></p>
<?php endif; ?>
</body>
</html>
