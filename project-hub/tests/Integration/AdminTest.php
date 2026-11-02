<?php

declare(strict_types=1);

/**
 * 階段 4 整合測試：管理後台、使用者管理、全域權限、單一裝置登入。
 * 接續 AuthFlowTest 的資料（管理者帳號已存在，密碼為 ADMIN_PASS_NEW）。
 */

$admin = null;      // 已登入的管理者瀏覽器
$aliceTemp = '';    // 管理者建立 Alice 時產生的臨時密碼

function adminLogin(): Browser
{
    $b = browser();
    loginFull($b, ADMIN_USER, ADMIN_PASS_NEW);
    return $b;
}

/** 管理者再驗證（輸入自己的驗證碼）。 */
function elevate(Browser $b, string $return = '/admin/users'): void
{
    allowCurrentCode(ADMIN_USER);
    $b->get($return);
    $b->post('/admin/reauth', ['code' => codeNow(activeSecret(ADMIN_USER)), 'return' => $return]);
}

function userRow(string $username): array
{
    $st = ctx()['pdo']->prepare('SELECT u.*, g.can_view_projects, g.can_edit_projects, g.can_create_projects, g.can_delete_projects, g.row_version
                                   FROM users u LEFT JOIN user_global_permissions g ON g.user_id = u.id WHERE u.username = ?');
    $st->execute([$username]);
    return (array)$st->fetch(PDO::FETCH_ASSOC);
}

function oneTimePassword(Browser $b): string
{
    assert_true((bool)preg_match('#<code class="fs-5 user-select-all">([A-Za-z0-9]+)</code>#', $b->body, $m), '頁面上找不到臨時密碼');
    return $m[1];
}

function setSetting(string $key, string $json): void
{
    $st = ctx()['pdo']->prepare('REPLACE INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, NOW())');
    $st->execute([$key, $json]);
}

test('準備：重置前一組測試留下的 IP 鎖定', function (): void {
    setSetting('security.login_ip_max_failures', '200');
    ctx()['pdo']->exec('DELETE FROM login_attempts');
});

test('未登入存取管理後台：導向登入頁', function (): void {
    assert_same('/login', browser()->get('/admin/users')->location());
});

test('非管理者：管理後台所有頁面與操作都回 404，且不會修改任何資料', function (): void {
    seedUser('plain_staff', 'Plain-Staff-001');
    $target = (int)userRow('plain_staff')['id'];
    ctx()['pdo']->exec("INSERT IGNORE INTO user_global_permissions (user_id, updated_at) VALUES ({$target}, NOW())");
    $b = browser();
    loginFull($b, 'plain_staff', 'Plain-Staff-001');
    foreach (['/admin', '/admin/users', '/admin/permissions', '/admin/migrate'] as $path) {
        assert_same(404, $b->get($path)->status, $path);
    }
    $b->get('/account');
    assert_same(404, $b->post("/admin/permissions/{$target}", ['can_view_projects' => '1', 'can_create_projects' => '1', 'row_version' => '1'])->status);
    assert_same(404, $b->post('/admin/users', ['username' => 'hacker', 'display_name' => 'x'])->status);
    assert_same(0, (int)userRow('plain_staff')['can_create_projects'], '權限不得被修改');
    assert_same([], ctx()['pdo']->query("SELECT id FROM users WHERE username = 'hacker'")->fetchAll());
    $denied = (int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'admin.access' AND result = 'denied'")->fetchColumn();
    assert_true($denied >= 5, '應留下權限拒絕紀錄');
    assert_not_contains('管理後台', $b->get('/')->body, '一般使用者的選單不應出現管理後台');
});

test('管理者：可進入管理後台，選單出現「管理後台」', function () use (&$admin): void {
    $admin = adminLogin();
    assert_contains('管理後台', $admin->get('/')->body);
    assert_same('/admin/users', $admin->get('/admin')->location());
    assert_same(200, $admin->get('/admin/users')->status);
    assert_contains('需要身分驗證', $admin->body);
});

test('未完成管理者再驗證時，不能建立帳號', function () use (&$admin): void {
    $admin->get('/admin/users');
    $admin->post('/admin/users', ['username' => 'alice', 'display_name' => 'Alice']);
    assert_contains('需要先輸入您的驗證碼', $admin->get('/admin/users')->body);
    assert_same([], ctx()['pdo']->query("SELECT id FROM users WHERE username = 'alice'")->fetchAll());
});

test('管理者再驗證：驗證碼錯誤失敗，正確後顯示已驗證', function () use (&$admin): void {
    $admin->get('/admin/users');
    $admin->post('/admin/reauth', ['code' => '000000', 'return' => '/admin/users']);
    assert_contains('驗證碼錯誤', $admin->get('/admin/users')->body);
    elevate($admin);
    assert_contains('已完成身分驗證', $admin->get('/admin/users')->body);
});

test('建立使用者：產生只顯示一次的臨時密碼、預設沒有任何權限、必須綁定 2FA 與變更密碼', function () use (&$admin, &$aliceTemp): void {
    $admin->get('/admin/users');
    $admin->post('/admin/users', ['username' => 'alice', 'display_name' => '員工 A', 'email' => 'alice@example.com']);
    assert_same('/admin/users', $admin->location());
    $admin->get('/admin/users');
    $aliceTemp = oneTimePassword($admin);
    assert_not_contains($aliceTemp, $admin->get('/admin/users')->body, '臨時密碼只能顯示一次');
    $row = userRow('alice');
    assert_same([1, 1, 0, 0, 0, 0, 0], array_map('intval', [$row['must_enroll_2fa'], $row['must_change_password'], $row['is_super_admin'],
        $row['can_view_projects'], $row['can_edit_projects'], $row['can_create_projects'], $row['can_delete_projects']]));
});

test('建立使用者：帳號不分大小寫不可重複', function () use (&$admin): void {
    $admin->get('/admin/users');
    $admin->post('/admin/users', ['username' => 'ALICE', 'display_name' => '重複']);
    assert_same(422, $admin->status);
    assert_contains('此帳號已存在', $admin->body);
});

test('新員工首次登入：綁定 2FA 後必須先變更臨時密碼，期間其他頁面與 API 都被阻擋', function () use (&$aliceTemp): void {
    $b = browser()->submit('/login', '/login', ['username' => 'alice', 'password' => $aliceTemp]);
    assert_same('/2fa/setup', $b->location());
    $b->get('/2fa/setup');
    $b->post('/2fa/setup', ['code' => codeNow(secretFromPage($b))]);
    assert_same('/account/password-required', $b->get('/')->location());
    assert_same('/account/password-required', $b->get('/account')->location());
    assert_same(403, $b->get('/api/me')->status);

    $b->get('/account/password-required');
    $b->post('/account/password-required', ['current_password' => $aliceTemp, 'new_password' => $aliceTemp, 'new_password_confirm' => $aliceTemp]);
    assert_contains('不可與臨時密碼相同', $b->get('/account/password-required')->body);
    $b->post('/account/password-required', ['current_password' => $aliceTemp, 'new_password' => 'Alice-Own-Pass-1', 'new_password_confirm' => 'Alice-Own-Pass-1']);
    assert_same('/', $b->location());
    assert_same(200, $b->get('/')->status);
    assert_same(0, (int)userRow('alice')['must_change_password']);
});

test('全域權限：每一列只更新該員工，不影響其他員工', function () use (&$admin): void {
    elevate($admin, '/admin/permissions');
    $admin->get('/admin/users');
    $admin->post('/admin/users', ['username' => 'bob', 'display_name' => '員工 B']);
    $bobBefore = userRow('bob');
    $alice = userRow('alice');

    $admin->get('/admin/permissions');
    assert_contains('儲存「員工 A」的權限', $admin->body);
    assert_contains('儲存「員工 B」的權限', $admin->body);
    $admin->post('/admin/permissions/' . $alice['id'], ['can_view_projects' => '1', 'can_edit_projects' => '1', 'row_version' => (string)$alice['row_version']]);
    assert_same('/admin/permissions', $admin->location());
    assert_contains('已儲存「員工 A」的全域權限', $admin->get('/admin/permissions')->body);

    $aliceAfter = userRow('alice');
    assert_same([1, 1, 0, 0], array_map('intval', [$aliceAfter['can_view_projects'], $aliceAfter['can_edit_projects'], $aliceAfter['can_create_projects'], $aliceAfter['can_delete_projects']]));
    $bobAfter = userRow('bob');
    assert_same($bobBefore['row_version'], $bobAfter['row_version'], '員工 B 的資料列不應被更新');
    assert_same(0, (int)$bobAfter['can_view_projects']);
});

test('全域權限：只勾選「刪除」時，伺服器自動加上「查看」', function () use (&$admin): void {
    $bob = userRow('bob');
    $admin->get('/admin/permissions');
    $admin->post('/admin/permissions/' . $bob['id'], ['can_delete_projects' => '1', 'row_version' => (string)$bob['row_version']]);
    assert_contains('已自動加上「查看」', $admin->get('/admin/permissions')->body);
    $after = userRow('bob');
    assert_same([1, 1], [(int)$after['can_view_projects'], (int)$after['can_delete_projects']]);
});

test('全域權限：版本號過期（另一位管理者已修改）時拒絕覆蓋', function () use (&$admin): void {
    $alice = userRow('alice');
    $admin->get('/admin/permissions');
    $admin->post('/admin/permissions/' . $alice['id'], ['can_create_projects' => '1', 'row_version' => (string)((int)$alice['row_version'] - 1)]);
    assert_contains('已被其他管理者修改', $admin->get('/admin/permissions')->body);
    assert_same(0, (int)userRow('alice')['can_create_projects']);
});

test('全域權限：不能透過網址修改最高權限管理者或不存在的使用者', function () use (&$admin): void {
    $adminId = (int)userRow(ADMIN_USER)['id'];
    $admin->get('/admin/permissions');
    assert_same(404, $admin->post("/admin/permissions/{$adminId}", ['can_view_projects' => '1', 'row_version' => '1'])->status);
    $admin->get('/admin/permissions');
    assert_same(404, $admin->post('/admin/permissions/99999', ['can_view_projects' => '1', 'row_version' => '1'])->status);
    $admin->get('/admin/permissions');
    assert_same(404, $admin->post('/admin/permissions/abc', ['can_view_projects' => '1'])->status);
    $admin->get('/admin/users');
    assert_same(404, $admin->post("/admin/users/{$adminId}/status", ['status' => 'disabled'])->status, '不能停用最高權限管理者');
});

test('停用帳號：登入中的員工立即被登出且無法再登入；重新啟用後可登入', function () use (&$admin): void {
    $alice = browser();
    loginFull($alice, 'alice', 'Alice-Own-Pass-1');
    $id = (int)userRow('alice')['id'];
    $admin->get('/admin/users');
    $admin->post("/admin/users/{$id}/status", ['status' => 'disabled']);
    assert_contains('已停用「員工 A」', $admin->get('/admin/users')->body);
    assert_same(401, $alice->get('/api/me')->status);
    assert_same(401, browser()->submit('/login', '/login', ['username' => 'alice', 'password' => 'Alice-Own-Pass-1'])->status);

    $admin->get('/admin/users');
    $admin->post("/admin/users/{$id}/status", ['status' => 'active']);
    $again = browser();
    loginFull($again, 'alice', 'Alice-Own-Pass-1');
    assert_same(200, $again->get('/api/me')->status);
});

test('重設 2FA（員工遺失手機）：員工所有裝置登出，下次登入必須重新綁定', function () use (&$admin): void {
    $alice = browser();
    loginFull($alice, 'alice', 'Alice-Own-Pass-1');
    $id = (int)userRow('alice')['id'];
    $admin->get('/admin/users');
    $admin->post("/admin/users/{$id}/reset-2fa", []);
    assert_contains('已解除「員工 A」的 2FA 綁定', $admin->get('/admin/users')->body);
    assert_same(401, $alice->get('/api/me')->status);
    $b = browser()->submit('/login', '/login', ['username' => 'alice', 'password' => 'Alice-Own-Pass-1']);
    assert_same('/2fa/setup', $b->location(), '解除綁定不代表可以跳過 2FA');
    assert_same(403, $b->get('/api/me')->status);
});

test('重設密碼：舊密碼失效、產生新臨時密碼、下次登入必須變更', function () use (&$admin): void {
    $id = (int)userRow('bob')['id'];
    $before = userRow('bob')['password_hash'];
    $admin->get('/admin/users');
    $admin->post("/admin/users/{$id}/reset-password", []);
    $admin->get('/admin/users');
    $temp = oneTimePassword($admin);
    $row = userRow('bob');
    assert_true($row['password_hash'] !== $before && password_verify($temp, (string)$row['password_hash']));
    assert_same(1, (int)$row['must_change_password']);
});

test('管理操作的再驗證 10 分鐘後失效', function () use (&$admin): void {
    // 以修改 Session 中的期限模擬逾時：直接在伺服器 Session 檔中把期限改為過去時間
    foreach (glob(ctx()['storage'] . '/sessions/sess_*') ?: [] as $file) {
        $data = (string)file_get_contents($file);
        if (str_contains($data, 'admin_reauth_until')) {
            file_put_contents($file, preg_replace('/admin_reauth_until\|i:\d+;/', 'admin_reauth_until|i:1;', $data));
        }
    }
    $id = (int)userRow('bob')['id'];
    $admin->get('/admin/users');
    $admin->post("/admin/users/{$id}/status", ['status' => 'disabled']);
    assert_contains('需要先輸入您的驗證碼', $admin->get('/admin/users')->body);
    assert_same('active', userRow('bob')['status']);
});

test('單一裝置登入（預設開啟）：在新瀏覽器登入後，舊瀏覽器立即登出', function (): void {
    setSetting('security.single_session', '1');
    seedUser('mobile_user', 'Mobile-User-001');
    $first = browser();
    loginFull($first, 'mobile_user', 'Mobile-User-001');
    assert_same(200, $first->get('/api/me')->status);
    $second = browser();
    loginFull($second, 'mobile_user', 'Mobile-User-001');
    assert_same(401, $first->get('/api/me')->status, '舊瀏覽器應被登出');
    assert_same(200, $second->get('/api/me')->status, '新瀏覽器保持登入');
    $detail = ctx()['pdo']->query("SELECT detail FROM activity_logs WHERE action = 'login.success' AND actor_label = 'mobile_user' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assert_contains('其他裝置已登出', (string)$detail);
});

test('登出其他所有裝置：關閉單一裝置登入時，可手動登出其他裝置', function (): void {
    setSetting('security.single_session', '0');
    $first = browser();
    loginFull($first, 'mobile_user', 'Mobile-User-001');
    $second = browser();
    loginFull($second, 'mobile_user', 'Mobile-User-001');
    assert_same(200, $first->get('/api/me')->status, '關閉單一裝置登入時可同時登入');
    $second->get('/account');
    $second->post('/account/sessions/revoke-others', []);
    assert_contains('已登出其他所有裝置', $second->get('/account')->body);
    assert_same(401, $first->get('/api/me')->status);
    assert_same(200, $second->get('/api/me')->status);
});

test('資料庫有待套用的更新時，管理後台先導向更新頁；套用後恢復', function () use (&$admin): void {
    $pdo = ctx()['pdo'];
    $pdo->exec("DELETE FROM schema_migrations WHERE version = '0002_user_must_change_password'");
    $pdo->exec('ALTER TABLE users DROP COLUMN must_change_password');
    $admin = adminLogin();
    assert_same(200, $admin->get('/')->status, '一般頁面在欄位不存在時仍可使用');
    assert_same('/admin/migrate', $admin->get('/admin/users')->location());
    $admin->get('/admin/migrate');
    assert_contains('0002_user_must_change_password', $admin->body);
    $admin->post('/admin/migrate', []);
    assert_same('/admin/users', $admin->location());
    assert_same(200, $admin->get('/admin/users')->status);
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetchAll();
    assert_same(1, count($cols));
});

test('歷史操作紀錄：管理操作都有記錄對象，且不含臨時密碼', function () use (&$aliceTemp): void {
    $pdo = ctx()['pdo'];
    foreach (['admin.user.create', 'admin.permission.update', 'admin.user.disable', 'admin.user.enable', 'admin.user.2fa_reset',
              'admin.user.password_reset', 'admin.reauth', 'system.migrate', 'sessions.revoke_others'] as $action) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = " . $pdo->quote($action))->fetchColumn();
        assert_true($n > 0, "缺少紀錄：{$action}");
    }
    $withTarget = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'admin.permission.update' AND target_user_id IS NOT NULL")->fetchColumn();
    assert_true($withTarget > 0, '權限修改紀錄應包含對象');
    $detail = (string)$pdo->query("SELECT detail FROM activity_logs WHERE action = 'admin.permission.update' AND result = 'success' ORDER BY id LIMIT 1")->fetchColumn();
    assert_contains('查看：無→有', $detail);
    $dump = implode("\n", array_map(fn(array $r): string => implode('|', $r), $pdo->query('SELECT * FROM activity_logs')->fetchAll(PDO::FETCH_NUM)));
    assert_not_contains($aliceTemp, $dump, '紀錄中不得出現臨時密碼');
    assert_not_contains('Alice-Own-Pass-1', $dump);
});
