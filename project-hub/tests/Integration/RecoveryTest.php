<?php

declare(strict_types=1);

/**
 * 階段 10 整合測試：最高權限管理者 Email 救援（docs/04 第 3 節）。
 * 寄信走假的 Brevo API（tests/Fakes），由信件內容取出救援連結。
 */

use ProjectHub\Auth\Passwords;

$adminPass = ADMIN_PASS_NEW;   // 救援過程中管理者密碼會改變

function mailCount(): int
{
    $f = ctx()['fakeDir'] . '/brevo_all.jsonl';
    return is_file($f) ? count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
}

/** @return array<string, mixed> 最近一封信 */
function lastMail(): array
{
    $lines = file(ctx()['fakeDir'] . '/brevo_all.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return (array)json_decode((string)end($lines), true);
}

function tokenFromMail(array $mail): string
{
    assert_true((bool)preg_match('#/recovery/confirm\?token=([A-Za-z0-9_-]{43})#', (string)$mail['textContent'], $m), '信中找不到救援連結');
    return $m[1];
}

function useBrevo(): void
{
    $pdo = ctx()['pdo'];
    foreach (['mail.driver' => '"brevo_api"', 'mail.from_address' => '"noreply@example.com"'] as $k => $v) {
        setSetting($k, $v);
    }
    $pdo->prepare('REPLACE INTO secret_settings (setting_key, value_enc, updated_at) VALUES (?, ?, NOW())')
        ->execute(['mail.brevo_api_key', crypto()->encrypt('brevo-test-key', 'secret-setting:mail.brevo_api_key')]);
}

function requestRecovery(string $account, string $purpose, bool $countLimit = false): Browser
{
    if (!$countLimit) {
        // 頻率限制另有專門的測試；其他測試每次申請前清除計數，避免互相影響
        ctx()['pdo']->exec("DELETE FROM login_attempts WHERE stage = 'recovery_request'");
    }
    $b = browser();
    $b->get('/recovery');
    $b->post('/recovery', ['account' => $account, 'purpose' => $purpose]);
    return $b;
}

function tokenRow(string $token): array
{
    $st = ctx()['pdo']->prepare('SELECT * FROM admin_recovery_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $token, true)]);
    return (array)$st->fetch(PDO::FETCH_ASSOC);
}

/** 救援後重新綁定 2FA，讓後續測試可以繼續登入 */
function reEnroll(string $password): void
{
    $b = browser();
    $b->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => $password]);
    assert_same('/2fa/setup', $b->location(), '清除 2FA 後登入必須重新綁定');
    $b->get('/2fa/setup');
    $secret = secretFromPage($b);
    $b->post('/2fa/setup', ['code' => codeNow($secret)]);
    assert_same('/', $b->location());
}

test('準備：使用假 Brevo 寄信、清除登入節流紀錄', function (): void {
    useBrevo();
    ctx()['pdo']->exec('DELETE FROM login_attempts');
    // 登入頁平常不顯示救援入口；任何帳號（含不存在、一般員工、管理者）輸入錯誤後都顯示同樣的協助說明，無法藉此判斷誰是管理者
    assert_not_contains('href="/recovery"', browser()->get('/login')->body, '登入頁平常不顯示救援入口');
    $helpBlocks = [];
    foreach (['nobody-at-all', 'g1p1', ADMIN_USER] as $who) {
        $b = browser();
        $b->submit('/login', '/login', ['username' => $who, 'password' => 'definitely-wrong']);
        assert_true((bool)preg_match('#<div class="small mt-3 mb-0 border-top pt-3" data-login-help>.*?</div>#s', $b->body, $m), "{$who} 輸入錯誤後顯示協助說明");
        $helpBlocks[] = $m[0];
    }
    assert_same(1, count(array_unique($helpBlocks)), '所有帳號看到的內容完全相同');
    $t = browser();
    $t->submit('/login', '/login', ['username' => 'g1p1', 'password' => 'Matrix-Pass-001']);
    assert_same('/login/totp', $t->location());
    assert_not_contains('href="/recovery"', $t->get('/login/totp')->body, '2FA 頁平常不顯示');
    $t->post('/login/totp', ['code' => '000000']);
    assert_contains('href="/recovery"', $t->body, '驗證碼錯誤後也顯示');
    ctx()['pdo']->exec('DELETE FROM login_attempts');
    assert_same(200, browser()->get('/recovery')->status);
});

test('申請：帳號不存在、一般員工、寄信未設定時，畫面回應完全相同且不寄信', function (): void {
    $before = mailCount();
    $unknown = requestRecovery('nobody', 'reset_password');
    $msgUnknown = $unknown->get('/recovery')->body;
    $employee = requestRecovery('g1p1', 'reset_password');
    $msgEmployee = $employee->get('/recovery')->body;
    assert_contains('若帳號與 Email 正確，系統已寄出救援信', $msgUnknown);
    assert_contains('若帳號與 Email 正確，系統已寄出救援信', $msgEmployee);
    setSetting('mail.driver', '"none"');
    requestRecovery(ADMIN_USER, 'reset_password');
    useBrevo();
    assert_same($before, mailCount(), '都不應寄信');
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'recovery.request' AND result = 'denied' AND actor_label = '(未知帳號)'")->fetchColumn() >= 2);
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'recovery.request' AND result = 'failure' AND detail LIKE '%無法寄信%'")->fetchColumn() === 1);
    assert_same(0, (int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE detail LIKE '%nobody%'")->fetchColumn(), '不保存輸入的原文');
});

test('忘記密碼：以 Email（不分大小寫）申請，信中連結只存雜湊；開啟連結不改任何狀態', function (): void {
    $before = mailCount();
    requestRecovery('ADMIN@example.com', 'reset_password');
    assert_same($before + 1, mailCount());
    $mail = lastMail();
    assert_same('admin@example.com', $mail['to'][0]['email']);
    $token = tokenFromMail($mail);
    $row = tokenRow($token);
    assert_same('reset_password', $row['purpose']);
    assert_same(0, (int)ctx()['pdo']->query('SELECT COUNT(*) FROM admin_recovery_tokens WHERE token_hash = ' . ctx()['pdo']->quote($token))->fetchColumn(), '資料庫不存明文');
    $b = browser();
    for ($i = 0; $i < 2; $i++) {
        $b->get('/recovery/confirm?token=' . $token);
        assert_same(200, $b->status);
        assert_contains('data-recovery-state="ok"', $b->body);
    }
    assert_same('no-referrer', $b->header('referrer-policy'));
    assert_same(null, tokenRow($token)['used_at'], 'GET 不可使用 Token（郵件掃描器開啟連結不會完成救援）');
    $GLOBALS['RC_TOKEN'] = $token;
});

test('忘記密碼：驗證碼錯誤或新密碼不合規定時不變更；正確後密碼更新、所有登入失效、需重新登入', function () use (&$adminPass): void {
    $token = $GLOBALS['RC_TOKEN'];
    $loggedIn = adminLogin();
    $b = browser();
    $b->get('/recovery/confirm?token=' . $token);
    $b->post('/recovery/confirm', ['token' => $token, 'code' => '000000', 'new_password' => 'Recovered-Pass-789', 'new_password_confirm' => 'Recovered-Pass-789']);
    assert_same(422, $b->status);
    assert_contains('驗證碼錯誤', $b->body);
    $b->post('/recovery/confirm', ['token' => $token, 'code' => '000000', 'new_password' => 'short', 'new_password_confirm' => 'short']);
    assert_contains('至少需要', $b->body);
    assert_same(null, tokenRow($token)['used_at']);

    allowCurrentCode(ADMIN_USER);
    $b->post('/recovery/confirm', ['token' => $token, 'code' => codeNow(activeSecret(ADMIN_USER)), 'new_password' => 'Recovered-Pass-789', 'new_password_confirm' => 'Recovered-Pass-789']);
    assert_same('/login', $b->location(), '救援完成不建立登入狀態');
    $adminPass = 'Recovered-Pass-789';
    assert_true(tokenRow($token)['used_at'] !== null);
    assert_contains('密碼已重設', $b->get('/login')->body);
    assert_same('/login', $loggedIn->get('/')->location(), '原本登入中的裝置立即失效');
    assert_contains('帳號救援已完成', (string)lastMail()['subject'], '寄送完成通知');

    $old = browser();
    $old->submit('/login', '/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS_NEW]);
    assert_true($old->location() !== '/login/totp', '舊密碼失效');
    loginFull(browser(), ADMIN_USER, $adminPass);
});

test('同一個連結不能再用；新的申請讓同用途的舊連結失效；過期連結無效', function (): void {
    $token = $GLOBALS['RC_TOKEN'];
    $b = browser();
    $b->get('/recovery/confirm?token=' . $token);
    assert_contains('data-recovery-state="invalid"', $b->body);

    requestRecovery(ADMIN_USER, 'reset_password');
    $first = tokenFromMail(lastMail());
    requestRecovery(ADMIN_USER, 'reset_password');
    $second = tokenFromMail(lastMail());
    assert_contains('data-recovery-state="invalid"', browser()->get('/recovery/confirm?token=' . $first)->body);
    assert_contains('data-recovery-state="ok"', browser()->get('/recovery/confirm?token=' . $second)->body);
    ctx()['pdo']->prepare('UPDATE admin_recovery_tokens SET expires_at = ? WHERE token_hash = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', $second, true)]);
    assert_contains('data-recovery-state="invalid"', browser()->get('/recovery/confirm?token=' . $second)->body);
    assert_contains('data-recovery-state="invalid"', browser()->get('/recovery/confirm?token=' . str_repeat('A', 43))->body);
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'recovery.complete' AND result = 'denied' AND detail = '連結已過期'")->fetchColumn() >= 1);
});

test('遺失驗證器：密碼錯誤時不變更；正確後清除 2FA，登入時必須重新綁定', function () use (&$adminPass): void {
    requestRecovery(ADMIN_USER, 'reset_2fa');
    $token = tokenFromMail(lastMail());
    $b = browser();
    $b->get('/recovery/confirm?token=' . $token);
    assert_contains('name="current_password"', $b->body);
    $b->post('/recovery/confirm', ['token' => $token, 'current_password' => 'wrong-password']);
    assert_same(422, $b->status);
    assert_same(1, (int)ctx()['pdo']->query("SELECT totp_enabled FROM users WHERE username = 'admin'")->fetchColumn());
    $b->post('/recovery/confirm', ['token' => $token, 'current_password' => $adminPass]);
    assert_same('/login', $b->location());
    assert_same(1, (int)ctx()['pdo']->query("SELECT must_enroll_2fa FROM users WHERE username = 'admin'")->fetchColumn());
    reEnroll($adminPass);
});

test('兩者皆遺失：冷卻時間內連結不能使用；時間到後可設定新密碼並重新綁定 2FA', function () use (&$adminPass): void {
    requestRecovery(ADMIN_USER, 'reset_both');
    $mail = lastMail();
    assert_contains('需等待 30 分鐘', (string)$mail['textContent']);
    $token = tokenFromMail($mail);
    $b = browser();
    $b->get('/recovery/confirm?token=' . $token);
    assert_contains('data-recovery-state="cooling"', $b->body);
    // 冷卻中即使送出表單也不會生效（冷卻頁沒有表單，先從申請頁取得 CSRF Token）
    $b->get('/recovery');
    $b->post('/recovery/confirm', ['token' => $token, 'new_password' => 'Both-Lost-Pass-1', 'new_password_confirm' => 'Both-Lost-Pass-1']);
    assert_contains('data-recovery-state="cooling"', $b->body);
    assert_same(null, tokenRow($token)['used_at']);

    ctx()['pdo']->prepare('UPDATE admin_recovery_tokens SET created_at = ? WHERE token_hash = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 31 * 60), hash('sha256', $token, true)]);
    $b->get('/recovery/confirm?token=' . $token);
    assert_contains('data-recovery-state="ok"', $b->body);
    $b->post('/recovery/confirm', ['token' => $token, 'new_password' => 'Both-Lost-Pass-1', 'new_password_confirm' => 'Both-Lost-Pass-1']);
    assert_same('/login', $b->location());
    $adminPass = 'Both-Lost-Pass-1';
    reEnroll($adminPass);
});

test('申請頻率限制：同一帳號每小時超過上限後不再寄信，畫面回應不變', function (): void {
    ctx()['pdo']->exec("DELETE FROM login_attempts WHERE stage = 'recovery_request'");
    $before = mailCount();
    for ($i = 0; $i < 5; $i++) {
        $b = requestRecovery(ADMIN_USER, 'reset_2fa', true);
        assert_same('/recovery', $b->location());
    }
    assert_same($before + 3, mailCount(), '預設每小時 3 次');
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'recovery.request' AND detail LIKE '%申請次數超過上限%'")->fetchColumn() === 2);
    ctx()['pdo']->exec("DELETE FROM login_attempts WHERE stage = 'recovery_request'");
});

test('帳號設定：可看到並取消待處理的救援申請；一般員工沒有救援設定', function () use (&$adminPass): void {
    $token = tokenFromMail(lastMail());
    $admin = browser();
    loginFull($admin, ADMIN_USER, $adminPass);
    $page = $admin->get('/account')->body;
    assert_contains('data-recovery-settings', $page);
    assert_true((bool)preg_match('/data-recovery-pending="([1-9]\d*)"/', $page), '列出待處理申請');
    $admin->post('/account/recovery/cancel', []);
    assert_contains('data-recovery-state="invalid"', browser()->get('/recovery/confirm?token=' . $token)->body);
    assert_contains('data-recovery-pending="0"', $admin->get('/account')->body);

    $emp = signedIn('g1p1');
    assert_not_contains('data-recovery-settings', $emp->get('/account')->body);
    $emp->post('/account/recovery-email', ['email' => 'evil@example.com', 'code' => '123456']);
    assert_same(404, $emp->status);
    $emp->get('/account');
    $emp->post('/account/recovery/cancel', []);
    assert_same(404, $emp->status);
});

test('變更救援 Email：每次都要驗證碼；完成後通知新舊 Email、舊連結失效，紀錄只存遮蔽後的 Email', function () use (&$adminPass): void {
    requestRecovery(ADMIN_USER, 'reset_password');
    $pendingToken = tokenFromMail(lastMail());
    $admin = browser();
    loginFull($admin, ADMIN_USER, $adminPass);
    $admin->get('/account');
    $admin->post('/account/recovery-email', ['email' => 'rescue@example.org', 'code' => '000000']);
    assert_contains('驗證碼錯誤', $admin->get('/account')->body);
    assert_same('admin@example.com', userRow(ADMIN_USER)['email']);

    $before = mailCount();
    allowCurrentCode(ADMIN_USER);
    $admin->post('/account/recovery-email', ['email' => 'rescue@example.org', 'code' => codeNow(activeSecret(ADMIN_USER))]);
    assert_same('rescue@example.org', userRow(ADMIN_USER)['email']);
    assert_same($before + 2, mailCount(), '通知新舊兩個 Email');
    assert_contains('data-recovery-state="invalid"', browser()->get('/recovery/confirm?token=' . $pendingToken)->body);
    $log = (string)ctx()['pdo']->query("SELECT detail FROM activity_logs WHERE action = 'recovery.email_change' AND result = 'success' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assert_contains('re****@example.org', $log);
    assert_not_contains('rescue@example.org', $log);

    // 之後的救援信寄到新的 Email；用舊 Email 申請不會寄出
    $before = mailCount();
    requestRecovery('admin@example.com', 'reset_password');
    assert_same($before, mailCount());
    requestRecovery('rescue@example.org', 'reset_password');
    assert_same('rescue@example.org', lastMail()['to'][0]['email']);
});

test('機密不外洩：紀錄中沒有救援 Token、新密碼或驗證碼', function (): void {
    $pdo = ctx()['pdo'];
    $token = tokenFromMail(lastMail());
    foreach ([$token, 'Recovered-Pass-789', 'Both-Lost-Pass-1', 'recovery/confirm'] as $needle) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE detail LIKE ' . $pdo->quote('%' . $needle . '%') . ' OR actor_label LIKE ' . $pdo->quote('%' . $needle . '%'))->fetchColumn();
        assert_same(0, $n, $needle);
    }
    // 還原管理者密碼與 Email，避免影響之後新增的測試
    $pdo->prepare('UPDATE users SET password_hash = ?, email = ? WHERE username = ?')->execute([Passwords::hash(ADMIN_PASS_NEW), 'admin@example.com', ADMIN_USER]);
    $pdo->exec('UPDATE admin_recovery_tokens SET invalidated_at = NOW() WHERE used_at IS NULL AND invalidated_at IS NULL');
});
