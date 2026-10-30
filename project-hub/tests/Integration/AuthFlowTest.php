<?php

declare(strict_types=1);

/**
 * 階段 3 整合測試：以真實 HTTP 請求 + 真實 MariaDB 驗證登入、2FA 與帳號設定流程。
 * 測試依順序執行，前面的步驟會建立後面需要的狀態。
 */

use ProjectHub\Auth\Base32;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Support\Crypto;

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'Initial-Pass-123';
const ADMIN_PASS_NEW = 'Changed-Pass-456';

/** @return array{base:string,pdo:PDO,appKey:string,installToken:string,storage:string} */
function ctx(): array
{
    return $GLOBALS['PH_CTX'];
}

function browser(): Browser
{
    return new Browser(ctx()['base']);
}

function crypto(): Crypto
{
    return new Crypto(ctx()['appKey']);
}

/** 從資料庫取出使用者目前的 2FA Secret（測試扮演使用者手機上的驗證器）。 */
function activeSecret(string $username): string
{
    $enc = ctx()['pdo']->query("SELECT totp_secret_enc FROM users WHERE username = " . ctx()['pdo']->quote($username))->fetchColumn();
    return crypto()->decrypt((string)$enc, 'totp');
}

/** 從綁定頁讀出手動輸入用的金鑰，模擬使用者掃描 QR Code。 */
function secretFromPage(Browser $b): string
{
    assert_true((bool)preg_match('#<code id="totp-secret" class="secret">([A-Z2-7 ]+)</code>#', $b->body, $m), '頁面上找不到 2FA 金鑰');
    return (string)Base32::decode($m[1]);
}

function codeNow(string $secret, int $offset = 0): string
{
    return Totp::code($secret, Totp::step(time()) + $offset);
}

/** 清除重放紀錄，讓下一個測試可以使用目前時間步的驗證碼（避免等待 30 秒）。 */
function allowCurrentCode(string $username): void
{
    ctx()['pdo']->prepare('UPDATE users SET totp_last_used_step = NULL WHERE username = ?')->execute([$username]);
}

function loginFull(Browser $b, string $user, string $pass): void
{
    $b->submit('/login', '/login', ['username' => $user, 'password' => $pass]);
    assert_same('/login/totp', $b->location(), '密碼正確後應進入 2FA 驗證');
    allowCurrentCode($user);
    $b->submit('/login/totp', '/login/totp', ['code' => codeNow(activeSecret($user))]);
    assert_same('/', $b->location(), '2FA 正確後應進入首頁');
}

/** 直接在資料庫建立已綁定 2FA 的使用者（階段 4 才有使用者管理介面）。 */
function seedUser(string $username, string $password): string
{
    $secret = Totp::generateSecret();
    $now = gmdate('Y-m-d H:i:s');
    ctx()['pdo']->prepare(
        'INSERT INTO users (username, username_norm, display_name, password_hash, totp_secret_enc, totp_enabled, must_enroll_2fa, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?)'
    )->execute([$username, strtolower($username), $username, Passwords::hash($password), crypto()->encrypt($secret, 'totp'), $now, $now]);
    return $secret;
}

$browser = null;   // 主要瀏覽器（管理者）
$secret = '';      // 管理者目前的 2FA Secret

test('尚未安裝時，任何頁面都導向安裝頁', function (): void {
    $b = browser()->get('/');
    assert_same(303, $b->status);
    assert_same('/install', $b->location());
});

test('安裝：安裝金鑰錯誤時拒絕，且不建立任何管理者', function (): void {
    $b = browser()->submit('/install', '/install', [
        'install_token' => str_repeat('x', 40), 'username' => ADMIN_USER, 'display_name' => '管理者',
        'email' => 'admin@example.com', 'password' => ADMIN_PASS, 'password_confirm' => ADMIN_PASS,
    ]);
    assert_same(403, $b->status);
    $tables = ctx()['pdo']->query("SHOW TABLES LIKE 'users'")->fetchAll();
    assert_same([], $tables, '金鑰錯誤時不應建立資料表');
});

test('安裝：缺少 CSRF Token 時拒絕', function (): void {
    $b = browser();
    $b->get('/install');
    $b->post('/install', ['install_token' => ctx()['installToken']], false);
    assert_same(400, $b->status);
});

test('安裝：成功建立資料表與最高權限管理者，並要求綁定 2FA', function (): void {
    $b = browser()->submit('/install', '/install', [
        'install_token' => ctx()['installToken'], 'username' => ADMIN_USER, 'display_name' => '系統管理者',
        'email' => 'admin@example.com', 'password' => ADMIN_PASS, 'password_confirm' => ADMIN_PASS,
    ]);
    assert_same('/login', $b->location(), '安裝完成應導向登入頁');
    $row = ctx()['pdo']->query("SELECT is_super_admin, must_enroll_2fa, totp_enabled FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
    assert_same(['is_super_admin' => 1, 'must_enroll_2fa' => 1, 'totp_enabled' => 0], array_map('intval', $row));
    assert_true(is_file(ctx()['storage'] . '/installed.lock'), '應建立 installed.lock');
    // 放寬 IP 失敗上限，避免本測試的大量失敗嘗試互相影響；IP 鎖定另於最後測試
    ctx()['pdo']->exec("INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES ('security.login_ip_max_failures', '200', NOW())");
});

test('安裝完成後安裝頁永久停用（GET 與 POST 都回 404）', function (): void {
    assert_same(404, browser()->get('/install')->status);
    $b = browser();
    $b->get('/login');
    assert_same(404, $b->post('/install', ['install_token' => ctx()['installToken']])->status);
});

test('未登入：頁面導向登入、API 回 401', function (): void {
    assert_same('/login', browser()->get('/account')->location());
    $api = browser()->get('/api/me');
    assert_same(401, $api->status);
    assert_contains('application/json', $api->header('content-type'));
});

test('安全標頭：CSP、禁止嵌入、不快取、Cookie 為 HttpOnly + SameSite', function (): void {
    $b = browser()->get('/login');
    assert_contains("default-src 'self'", $b->header('content-security-policy'));
    assert_contains("frame-ancestors 'none'", $b->header('content-security-policy'));
    assert_same('DENY', $b->header('x-frame-options'));
    assert_same('no-store', $b->header('cache-control'));
    assert_contains('HttpOnly', $b->header('set-cookie'));
    assert_contains('SameSite=Lax', $b->header('set-cookie'));
});

test('登入：密碼錯誤與帳號不存在回應相同訊息（不洩漏帳號是否存在）', function (): void {
    $wrong = browser()->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => 'wrong-password']);
    $unknown = browser()->submit('/login', '/login', ['username' => 'nobody', 'password' => 'wrong-password']);
    assert_same(401, $wrong->status);
    assert_same(401, $unknown->status);
    assert_contains('帳號或密碼錯誤', $wrong->body);
    assert_contains('帳號或密碼錯誤', $unknown->body);
});

test('登入：缺少或錯誤的 CSRF Token 時拒絕', function (): void {
    $b = browser();
    $b->get('/login');
    assert_same(400, $b->post('/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS], false)->status);
    assert_same(400, $b->post('/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS, '_csrf' => str_repeat('a', 64)])->status);
});

test('登入：密碼正確後更換 Session ID（防 Session 固定），並強制進入 2FA 綁定', function () use (&$browser): void {
    $browser = browser();
    $browser->get('/login');
    $before = $browser->cookieValue('phsid');
    $browser->post('/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    assert_same('/2fa/setup', $browser->location());
    assert_true($before !== '' && $browser->cookieValue('phsid') !== $before, 'Session ID 應更換');
});

test('尚未完成 2FA 綁定：其他頁面導回綁定頁、API 回 403', function () use (&$browser): void {
    assert_same('/2fa/setup', $browser->get('/')->location());
    assert_same('/2fa/setup', $browser->get('/account')->location());
    assert_same('/2fa/setup', $browser->get('/login/totp')->location(), '不可跳到一般 2FA 驗證頁');
    $api = $browser->get('/api/me');
    assert_same(403, $api->status);
    assert_not_contains('"username"', $api->body, '不得回傳使用者資料');
});

test('2FA 綁定頁：QR Code 由伺服器產生 SVG，且重新整理沿用同一把金鑰', function () use (&$browser, &$secret): void {
    $browser->get('/2fa/setup');
    assert_same(200, $browser->status);
    assert_contains('<svg', $browser->body);
    assert_not_contains('chart.googleapis', $browser->body);
    assert_not_contains('api.qrserver', $browser->body);
    $secret = secretFromPage($browser);
    $browser->get('/2fa/setup');
    assert_same($secret, secretFromPage($browser), '重新整理不應更換金鑰');
});

test('2FA 綁定：驗證碼錯誤時不啟用', function () use (&$browser, &$secret): void {
    $browser->post('/2fa/setup', ['code' => codeNow($secret, 5)]);
    assert_same('/2fa/setup', $browser->location());
    $enabled = (int)ctx()['pdo']->query("SELECT totp_enabled FROM users WHERE username = 'admin'")->fetchColumn();
    assert_same(0, $enabled);
});

test('2FA 綁定：驗證碼正確後啟用並完成登入', function () use (&$browser, &$secret): void {
    $browser->get('/2fa/setup');
    $browser->post('/2fa/setup', ['code' => codeNow($secret)]);
    assert_same('/', $browser->location());
    assert_same(200, $browser->get('/')->status);
    $me = json_decode($browser->get('/api/me')->body, true);
    assert_same(ADMIN_USER, $me['username']);
    assert_same(true, $me['is_super_admin']);
    assert_same($secret, activeSecret(ADMIN_USER), '資料庫中的金鑰應為使用者掃描的那一把');
    $raw = ctx()['pdo']->query("SELECT totp_secret_enc FROM users WHERE username = 'admin'")->fetchColumn();
    assert_true(!str_contains((string)$raw, $secret) && !str_contains((string)$raw, Base32::encode($secret)), '金鑰必須加密保存');
});

test('登出後無法再存取', function () use (&$browser): void {
    $browser->get('/');
    $browser->post('/logout', []);
    assert_same('/login', $browser->location());
    assert_same('/login', $browser->get('/')->location());
});

test('2FA 防重放：同一組驗證碼不能登入第二次', function () use (&$secret): void {
    $b = browser()->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    assert_same('/login/totp', $b->location());
    $used = (int)ctx()['pdo']->query("SELECT totp_last_used_step FROM users WHERE username = 'admin'")->fetchColumn();
    $b->get('/login/totp');
    $b->post('/login/totp', ['code' => Totp::code($secret, $used)]);
    assert_same(401, $b->status, '已使用過的驗證碼應被拒絕');
    $b->post('/login/totp', ['code' => Totp::code($secret, $used + 1)]);
    assert_same('/', $b->location(), '下一組驗證碼應可登入');
});

test('鎖定：同一帳號連續 5 次密碼錯誤後，正確密碼也被暫時拒絕', function (): void {
    seedUser('locktest', 'Lock-Test-Pass-1');
    for ($i = 0; $i < 5; $i++) {
        browser()->submit('/login', '/login', ['username' => 'locktest', 'password' => 'bad-' . $i]);
    }
    $b = browser()->submit('/login', '/login', ['username' => 'locktest', 'password' => 'Lock-Test-Pass-1']);
    assert_same(429, $b->status);
    assert_contains('嘗試次數過多', $b->body);
});

test('鎖定：已知密碼時，反覆「密碼成功 → 猜驗證碼」不會重置失敗計數', function (): void {
    seedUser('locktest2', 'Lock-Test-Pass-2');
    for ($round = 0; $round < 3; $round++) {
        $b = browser()->submit('/login', '/login', ['username' => 'locktest2', 'password' => 'Lock-Test-Pass-2']);
        if ($b->status === 429) {
            break;
        }
        assert_same('/login/totp', $b->location());
        $b->get('/login/totp');
        $b->post('/login/totp', ['code' => '000000']);
        $b->post('/login/totp', ['code' => '000001']);
    }
    $final = browser()->submit('/login', '/login', ['username' => 'locktest2', 'password' => 'Lock-Test-Pass-2']);
    assert_same(429, $final->status, '累計 5 次驗證碼錯誤後應鎖定');
});

test('修改密碼：目前密碼錯誤時拒絕', function () use (&$browser): void {
    $browser = browser();
    loginFull($browser, ADMIN_USER, ADMIN_PASS);
    $browser->submit('/account', '/account/password', [
        'current_password' => 'wrong', 'new_password' => ADMIN_PASS_NEW, 'new_password_confirm' => ADMIN_PASS_NEW,
    ]);
    assert_same('/account', $browser->location());
    assert_contains('目前密碼錯誤', $browser->get('/account')->body);
});

test('修改密碼：成功後其他裝置立即登出、本裝置保持登入、舊密碼失效', function () use (&$browser): void {
    $other = browser();
    loginFull($other, ADMIN_USER, ADMIN_PASS);
    assert_same(200, $other->get('/api/me')->status);

    $browser->submit('/account', '/account/password', [
        'current_password' => ADMIN_PASS, 'new_password' => ADMIN_PASS_NEW, 'new_password_confirm' => ADMIN_PASS_NEW,
    ]);
    assert_contains('密碼已更新', $browser->get('/account')->body);
    assert_same(200, $browser->get('/api/me')->status, '本裝置應保持登入');
    assert_same(401, $other->get('/api/me')->status, '其他裝置應立即失效');

    $old = browser()->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    assert_same(401, $old->status, '舊密碼不可再登入');
});

test('更換 2FA：沒有目前驗證器的驗證碼時無法開始', function () use (&$browser): void {
    $browser->submit('/account', '/account/2fa/change', ['current_password' => ADMIN_PASS_NEW, 'code' => '123456']);
    assert_same('/account', $browser->location());
    assert_same('/account', $browser->get('/account/2fa/change')->location(), '未通過驗證不可看到新 QR Code');
});

test('更換 2FA：新驗證器確認前舊的仍有效，確認後舊的立即失效', function () use (&$browser, &$secret): void {
    allowCurrentCode(ADMIN_USER);
    $browser->submit('/account', '/account/2fa/change', ['current_password' => ADMIN_PASS_NEW, 'code' => codeNow($secret)]);
    assert_same('/account/2fa/change', $browser->location());
    $browser->get('/account/2fa/change');
    $newSecret = secretFromPage($browser);
    assert_true($newSecret !== $secret);
    assert_same($secret, activeSecret(ADMIN_USER), '確認前資料庫仍使用舊金鑰');

    $browser->post('/account/2fa/confirm', ['code' => codeNow($secret, 1)]);
    assert_same('/account/2fa/change', $browser->location(), '用舊驗證器的碼確認應失敗');

    $browser->get('/account/2fa/change');
    $browser->post('/account/2fa/confirm', ['code' => codeNow($newSecret)]);
    assert_same('/account', $browser->location());
    assert_contains('已更換 2FA 驗證器', $browser->get('/account')->body);
    assert_same($newSecret, activeSecret(ADMIN_USER));

    $b = browser()->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS_NEW]);
    allowCurrentCode(ADMIN_USER);
    $b->get('/login/totp');
    $b->post('/login/totp', ['code' => codeNow($secret)]);
    assert_same(401, $b->status, '舊驗證器的碼應失效');
    $secret = $newSecret;
});

test('帳號在登入中被停用：下一個請求立即登出', function (): void {
    seedUser('staff', 'Staff-Pass-0001');
    $b = browser();
    loginFull($b, 'staff', 'Staff-Pass-0001');
    ctx()['pdo']->exec("UPDATE users SET status = 'disabled' WHERE username = 'staff'");
    assert_same('/login', $b->get('/')->location());
    assert_contains('登入狀態已失效', $b->get('/login')->body);
    $again = browser()->submit('/login', '/login', ['username' => 'staff', 'password' => 'Staff-Pass-0001']);
    assert_same(401, $again->status, '停用帳號不可登入');
});

test('管理者重設 2FA：該員工所有 Session 失效，下次登入必須重新綁定', function (): void {
    seedUser('staff2', 'Staff-Pass-0002');
    $b = browser();
    loginFull($b, 'staff2', 'Staff-Pass-0002');
    $id = (int)ctx()['pdo']->query("SELECT id FROM users WHERE username = 'staff2'")->fetchColumn();
    (new ProjectHub\Auth\UserRepository(new ProjectHub\Support\Database(ctx()['db'])))->resetTotp($id);

    assert_same('/login', $b->get('/')->location(), '舊 Session 應失效');
    $b2 = browser()->submit('/login', '/login', ['username' => 'staff2', 'password' => 'Staff-Pass-0002']);
    assert_same('/2fa/setup', $b2->location(), '應強制重新綁定');
    assert_same(403, $b2->get('/api/me')->status, '綁定前不可使用 API');
});

test('歷史操作紀錄：有記錄重要事件，且不含密碼、驗證碼或 2FA 金鑰', function () use (&$secret): void {
    $pdo = ctx()['pdo'];
    $actions = $pdo->query('SELECT DISTINCT action FROM activity_logs')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['system.install', 'login.success', 'login.failure', '2fa.enroll', 'logout', 'password.change', '2fa.change'] as $a) {
        assert_true(in_array($a, $actions, true), "缺少紀錄：{$a}");
    }
    $dump = implode("\n", array_map(fn(array $r): string => implode('|', $r), $pdo->query('SELECT * FROM activity_logs')->fetchAll(PDO::FETCH_NUM)));
    foreach ([ADMIN_PASS, ADMIN_PASS_NEW, 'wrong-password', 'Lock-Test-Pass-1', Base32::encode($secret), ctx()['installToken']] as $s) {
        assert_not_contains($s, $dump, '紀錄中出現機密資料');
    }
    assert_not_contains('nobody', $dump, '不存在的帳號字串不應被記錄');
    $unknown = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'login.failure' AND actor_label = '(未知帳號)'")->fetchColumn();
    assert_true($unknown >= 1);
});

test('登入節流表不保存原始帳號字串', function (): void {
    $rows = ctx()['pdo']->query('SELECT account_key FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($rows) > 0);
    foreach ($rows as $k) {
        assert_same(32, strlen((string)$k));
        assert_not_contains('admin', (string)$k);
    }
});

test('IP 鎖定：同一 IP 失敗次數達上限後，任何帳號都暫時拒絕', function (): void {
    ctx()['pdo']->exec("UPDATE system_settings SET value_json = '5' WHERE setting_key = 'security.login_ip_max_failures'");
    $b = browser()->submit('/login', '/login', ['username' => 'brand-new-user', 'password' => 'whatever-pass']);
    assert_same(429, $b->status);
});
