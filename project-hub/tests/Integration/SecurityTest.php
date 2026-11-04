<?php

declare(strict_types=1);

/**
 * 階段 12 整合測試：全站安全掃描。
 *
 * 以反射讀出 App 的完整路由表逐一檢查，新增路由時會自動納入：
 * - 未登入：所有需要登入的路由都導向登入頁（API 回 401），不會執行任何操作；
 * - 一般員工：所有管理後台路由（GET 與 POST）都回 404；
 * - 缺少 CSRF Token：所有狀態變更請求都回 400，資料不變；
 * 另外檢查安全標頭、Cookie 屬性、Session 更換、注入字串、開放式轉址與 Host 標頭偽造。
 */

use ProjectHub\App;

/** @return array<string, array{0:string,1:string,2:string}> */
function allRoutes(): array
{
    $m = new ReflectionMethod(App::class, 'routes');
    return $m->invoke(null);
}

/** 把路由中的 {id} 換成實際存在的 ID，確保測到的是「權限」而不是「找不到」 */
function concretePath(string $pattern): string
{
    return str_replace('{id}', '1', $pattern);
}

/** 主要資料表的指紋：用來確認被拒絕的請求沒有改到任何資料 */
function dataFingerprint(): string
{
    $pdo = ctx()['pdo'];
    $parts = [];
    foreach (['users', 'user_global_permissions', 'projects', 'project_permissions', 'project_keywords', 'project_notes',
              'note_attachments', 'system_settings', 'secret_settings', 'admin_recovery_tokens', 'project_description_history'] as $t) {
        $parts[] = $t . ':' . json_encode($pdo->query("SELECT * FROM {$t} ORDER BY 1")->fetchAll(PDO::FETCH_NUM));
    }
    return hash('sha256', implode('|', $parts));
}

test('路由表盤點：每條路由都有明確的存取等級', function (): void {
    $levels = [App::ACCESS_GUEST, App::ACCESS_TOTP, App::ACCESS_ENROLL, App::ACCESS_SESSION, App::ACCESS_AUTH,
               App::ACCESS_INSTALL, App::ACCESS_ADMIN, App::ACCESS_PASSWORD_CHANGE, App::ACCESS_PUBLIC];
    $routes = allRoutes();
    assert_true(count($routes) > 70, '路由數量');
    foreach ($routes as $key => [$access]) {
        assert_true(in_array($access, $levels, true), $key);
        if (str_starts_with(explode(' ', $key)[1], '/admin')) {
            assert_same(App::ACCESS_ADMIN, $access, "{$key} 必須是管理後台等級");
        }
    }
    $public = array_keys(array_filter($routes, static fn(array $r): bool => $r[0] === App::ACCESS_PUBLIC));
    assert_same(['GET /recovery/confirm', 'POST /recovery/confirm'], $public, '不需登入狀態的路由只有救援確認頁');
});

test('未登入：所有需要登入的路由（GET 與 POST）都不會執行，導向登入頁或回 401', function (): void {
    $before = dataFingerprint();
    foreach (allRoutes() as $key => [$access]) {
        if (in_array($access, [App::ACCESS_GUEST, App::ACCESS_INSTALL, App::ACCESS_PUBLIC], true)) {
            continue;
        }
        [$method, $pattern] = explode(' ', $key);
        $b = browser();
        $b->get('/login'); // 取得有效的 CSRF Token，確認擋下的是登入檢查而不是 CSRF
        $b->send($method, concretePath($pattern), $method === 'POST' ? ['_csrf' => $b->csrf(), 'name' => 'x', 'content' => 'x'] : null);
        if (str_starts_with($pattern, '/api/')) {
            assert_same(401, $b->status, $key);
        } else {
            assert_true(in_array($b->status, [302, 303], true) && $b->location() === '/login', "{$key} → {$b->status} {$b->location()}");
        }
    }
    assert_same($before, dataFingerprint(), '資料完全沒有改變');
});

test('一般員工（全域權限全開）：所有管理後台路由都回 404 且不改資料', function (): void {
    setGlobal('g1p1', 1, 1, 1, 1);
    $b = signedIn('g1p1');
    $before = dataFingerprint();
    foreach (allRoutes() as $key => [$access]) {
        if ($access !== App::ACCESS_ADMIN) {
            continue;
        }
        [$method, $pattern] = explode(' ', $key);
        $b->get('/account');
        $b->send($method, concretePath($pattern), $method === 'POST' ? ['_csrf' => $b->csrf(), 'code' => '123456', 'status' => 'disabled'] : null);
        assert_same(404, $b->status, $key);
        assert_not_contains('管理後台', $b->body, "{$key} 不可透露管理後台存在");
    }
    assert_same($before, dataFingerprint());
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'admin.access' AND result = 'denied'")->fetchColumn() > 0, '留下拒絕紀錄');
});

test('CSRF：管理者登入狀態下，所有 POST 路由缺少或帶錯 Token 都回 400 且不改資料', function (): void {
    $admin = adminLogin();
    $admin->get('/admin/users');
    $admin->post('/admin/reauth', ['code' => codeNow(activeSecret(ADMIN_USER), 1), 'return' => '/admin/users']); // 再驗證狀態下也要擋
    $before = dataFingerprint();
    $checked = 0;
    foreach (allRoutes() as $key => [$access]) {
        [$method, $pattern] = explode(' ', $key);
        if ($method !== 'POST' || !in_array($access, [App::ACCESS_AUTH, App::ACCESS_ADMIN, App::ACCESS_PUBLIC], true)) {
            continue;
        }
        $path = concretePath($pattern);
        foreach ([null, str_repeat('0', 64)] as $token) {
            $data = ['name' => 'csrf-attack', 'content' => 'x', 'status' => 'disabled'];
            if ($token !== null) {
                $data['_csrf'] = $token;
            }
            $admin->send('POST', $path, $data);
            assert_same(400, $admin->status, "{$key}（Token：" . ($token === null ? '無' : '錯誤') . '）');
            $checked++;
        }
    }
    assert_true($checked >= 60, "共檢查 {$checked} 個請求");
    assert_same($before, dataFingerprint());
    // 未登入的表單（登入、救援申請）同樣檢查 CSRF
    foreach (['/login' => ['username' => ADMIN_USER, 'password' => 'x'], '/recovery' => ['account' => ADMIN_USER, 'purpose' => 'reset_password']] as $path => $data) {
        assert_same(400, browser()->send('POST', $path, $data)->status, $path);
    }
});

test('不支援的 HTTP 方法、超大或非數字 ID、路徑變形都回 404', function (): void {
    $admin = adminLogin();
    foreach (['PUT /projects/1', 'DELETE /projects/1', 'PATCH /admin/users', 'OPTIONS /', 'GET /projects/99999999999',
              'GET /projects/0', 'GET /projects/-1', 'GET /projects/1abc', 'GET /projects/01', 'GET /index.php/admin'] as $req) {
        [$m, $p] = explode(' ', $req);
        assert_same(404, $admin->send($m, $p, null)->status, $req);
    }
});

test('安全標頭：一般頁面、JSON API、404、轉址、附件都帶有 CSP 等標頭，且不允許快取', function (): void {
    $admin = adminLogin();
    $pages = ['/' => 200, '/api/projects' => 200, '/no-such-page' => 404, '/admin/users' => 200];
    foreach ($pages as $path => $status) {
        $admin->get($path);
        assert_same($status, $admin->status, $path);
        assert_contains("default-src 'self'", $admin->header('content-security-policy'), $path);
        assert_contains("frame-ancestors 'none'", $admin->header('content-security-policy'), $path);
        assert_not_contains('unsafe-inline', $admin->header('content-security-policy'), "{$path} 不允許 inline script／style");
        assert_same('nosniff', $admin->header('x-content-type-options'), $path);
        assert_same('DENY', $admin->header('x-frame-options'), $path);
        assert_same('same-origin', $admin->header('referrer-policy'), $path);
        assert_same('no-store', $admin->header('cache-control'), $path);
        assert_same('', $admin->header('x-powered-by'), "{$path} 不透露 PHP 版本");
    }
    $login = browser()->get('/login');
    assert_contains('/login', browser()->get('/')->location());
    assert_contains("form-action 'self'", $login->header('content-security-policy'));
    // 所有頁面都沒有 inline script（CSP 會擋，這裡確認原始碼也沒有）
    foreach (['/', '/account', '/admin/users', '/admin/logs', '/admin/settings', '/search?q=a'] as $p) {
        $html = $admin->get($p)->body;
        assert_true(!preg_match('#<script(?![^>]*\ssrc=)[^>]*>#i', $html), "{$p} 含 inline script");
        assert_true(!preg_match('#\sstyle="#i', $html), "{$p} 含 inline style");
        assert_true(!preg_match('#\son[a-z]+="#i', $html), "{$p} 含 inline 事件處理器");
    }
});

test('Cookie 與 Session：HttpOnly、SameSite=Lax；登入後更換 Session ID；登出後舊 Session 無法使用', function (): void {
    $b = browser();
    $b->get('/login');
    $cookie = $b->header('set-cookie');
    assert_contains('HttpOnly', $cookie);
    assert_contains('SameSite=Lax', $cookie);
    $before = $b->cookieValue('phsid');
    loginFull($b, ADMIN_USER, ADMIN_PASS_NEW);
    $after = $b->cookieValue('phsid');
    assert_true($before !== '' && $after !== '' && $before !== $after, '登入後必須更換 Session ID（防止 Session 固定攻擊）');

    // 拿舊的（登入前的）Session ID 不能進入
    $attacker = browser();
    $attacker->send('GET', '/', null, ['Cookie: phsid=' . $before]);
    assert_same('/login', $attacker->location());
    // 登出後，原本的 Session ID 失效
    $b->get('/');
    $b->post('/logout', []);
    $stolen = browser();
    $stolen->send('GET', '/', null, ['Cookie: phsid=' . $after]);
    assert_same('/login', $stolen->location());
});

test('注入字串：搜尋、紀錄篩選、表單欄位放入 SQL／HTML／控制字元，不會出錯也不會被執行', function (): void {
    $admin = adminLogin();
    $payloads = ["' OR 1=1 -- ", '" OR ""="', '1; DROP TABLE users; --', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>',
                 "%' UNION SELECT password_hash FROM users -- ", "\x00\x1f", str_repeat('長', 5000), '../../config/config.php', '${7*7}{{7*7}}'];
    foreach ($payloads as $p) {
        $q = rawurlencode($p);
        foreach (["/search?q={$q}", "/api/search?q={$q}", "/admin/logs?mode=day&date={$q}&user={$q}&category={$q}", "/admin/logs/export?mode=range&from={$q}&to={$q}"] as $url) {
            $admin->get($url);
            assert_true($admin->status < 500, "{$url} → {$admin->status}");
            assert_not_contains('<script>alert(1)</script>', $admin->body, $url);
            assert_not_contains('<img src=x', $admin->body, $url);
            assert_not_contains('$argon2', $admin->body, $url);
            assert_not_contains('$2y$', $admin->body, $url);
        }
    }
    $usersBefore = (int)ctx()['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $admin->submit('/projects/new', '/projects', ['name' => "<script>alert(1)</script>' OR 1=1 --", 'description' => '<img src=x onerror=alert(1)>']);
    $id = projectId("<script>alert(1)</script>' OR 1=1 --");
    $html = $admin->get("/projects/{$id}")->body;
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, '名稱以文字顯示');
    assert_not_contains('<img src=x', $html);
    assert_same($usersBefore, (int)ctx()['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn());
});

test('開放式轉址：再驗證的返回網址只接受站內管理頁', function (): void {
    $admin = adminLogin();
    foreach (['https://evil.example/', '//evil.example', '/\\evil.example', '/admin/users@evil.example', "/admin/users\r\nSet-Cookie: x=1"] as $target) {
        $admin->get('/admin/users');
        $admin->post('/admin/reauth', ['code' => '000000', 'return' => $target]);
        assert_same('/admin/users', $admin->location(), $target);
    }
});

test('Host 標頭偽造：救援信的連結一律使用 config.php 的 base_url', function (): void {
    useBrevo();
    ctx()['pdo']->exec("DELETE FROM login_attempts WHERE stage = 'recovery_request'");
    $b = browser();
    $b->get('/recovery');
    $b->send('POST', '/recovery', ['_csrf' => $b->csrf(), 'account' => ADMIN_USER, 'purpose' => 'reset_password'],
        ['Host: evil.example', 'X-Forwarded-Host: evil.example']);
    $text = (string)lastMail()['textContent'];
    assert_contains(ctx()['base'] . '/recovery/confirm?token=', $text);
    assert_not_contains('evil.example', $text);
    ctx()['pdo']->exec('UPDATE admin_recovery_tokens SET invalidated_at = NOW() WHERE used_at IS NULL AND invalidated_at IS NULL');
});

test('錯誤訊息不外洩：不存在的頁面與錯誤頁不含路徑、SQL 或 PHP 版本', function (): void {
    foreach (['/no-such-page', '/api/no-such', '/projects/abc'] as $p) {
        $body = adminLogin()->get($p)->body;
        foreach (['/home/', 'Stack trace', 'PDOException', 'SQLSTATE', 'Warning:', 'Fatal error', PHP_VERSION] as $leak) {
            assert_not_contains($leak, $body, "{$p} 含 {$leak}");
        }
    }
});

test('系統錯誤紀錄：錯誤頁的追蹤代碼可在管理後台查到原因；不記錄網址參數；只有管理者能看', function (): void {
    $pdo = ctx()['pdo'];
    $admin = adminLogin();
    $pid = projectId('附件測試');
    // 暫時讓資料表消失，製造一個真實的伺服器錯誤
    $pdo->exec('RENAME TABLE project_keywords TO project_keywords_tmp');
    try {
        $admin->get("/projects/{$pid}?token=secret-in-query");
    } finally {
        $pdo->exec('RENAME TABLE project_keywords_tmp TO project_keywords');
    }
    assert_same(500, $admin->status);
    assert_true((bool)preg_match('/追蹤代碼提供給管理者：([a-f0-9]{12})/u', $admin->body, $m), '錯誤頁顯示追蹤代碼');
    assert_not_contains('project_keywords', $admin->body, '錯誤頁不透露細節');
    $id = $m[1];

    $page = $admin->get('/admin/errors?id=' . $id)->body;
    assert_contains('data-error-count="1"', $page);
    assert_contains('PDOException', $page);
    assert_contains("/projects/{$pid}", $page);
    assert_not_contains('secret-in-query', $page, '不記錄查詢字串');
    assert_not_contains('/home/', $page, '只顯示專案內的相對路徑');
    assert_contains('data-error-count="0"', $admin->get('/admin/errors?id=000000000000')->body);
    assert_same(200, $admin->get('/admin/errors?id=<script>')->status);
    assert_same(404, signedIn('g1p1')->get('/admin/errors')->status);
    $files = glob(ctx()['storage'] . '/logs/errors-*.log') ?: [];
    assert_same(1, count($files), '寫在 storage/logs');
});

test('猜帳號封鎖：同一 IP 輸入不存在的帳號達 5 次，暫時封鎖該 IP 的所有登入（含正確帳號）與救援申請', function (): void {
    $pdo = ctx()['pdo'];
    $pdo->exec('DELETE FROM login_attempts');
    $guess = function (string $user): Browser {
        $b = browser();
        $b->submit('/login', '/login', ['username' => $user, 'password' => 'guess-' . $user]);
        return $b;
    };
    for ($i = 1; $i <= 4; $i++) {
        assert_same(401, $guess("ghost{$i}")->status, "第 {$i} 次");
    }
    // 4 次時尚未封鎖：正確帳號仍可登入
    $ok = browser();
    $ok->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS_NEW]);
    assert_same('/login/totp', $ok->location(), '未達上限前不影響正常登入');

    assert_same(401, $guess('ghost5')->status, '第 5 次仍回一般錯誤訊息');
    $blocked = browser();
    $blocked->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS_NEW]);
    assert_same(429, $blocked->status, '達上限後，同一 IP 連正確帳號也暫時不能登入');
    assert_contains('嘗試次數過多', $blocked->body);
    $log = $pdo->query("SELECT ip, detail FROM activity_logs WHERE action = 'login.ip_locked' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($log) && $log['ip'] === '127.0.0.1', '留下封鎖紀錄（含 IP）');
    assert_contains('連續 5 次輸入不存在的帳號', (string)$log['detail']);
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'login.ip_locked'")->fetchColumn(), '只在達到上限時記錄一次');

    // 封鎖期間救援申請也不寄信（畫面回應不變）
    useBrevo();
    $before = mailCount();
    $r = browser();
    $r->get('/recovery');
    $r->post('/recovery', ['account' => ADMIN_USER, 'purpose' => 'reset_password']);
    assert_same('/recovery', $r->location());
    assert_same($before, mailCount());

    // 鎖定時間過後（以刪除紀錄模擬）恢復
    $pdo->exec('DELETE FROM login_attempts');
    $again = browser();
    $again->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS_NEW]);
    assert_same('/login/totp', $again->location());
    $pdo->exec('DELETE FROM login_attempts');
});
