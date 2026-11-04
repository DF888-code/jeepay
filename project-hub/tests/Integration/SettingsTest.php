<?php

declare(strict_types=1);

/**
 * 階段 9 整合測試：系統設定中心、寄信設定（SMTP／Brevo）、Google Drive 附件儲存。
 * 外部服務以 tests/Fakes 的假服務取代（Google、Brevo 走 HTTP 假伺服器，SMTP 走假 SMTP 伺服器）。
 */

$setAdmin = null;
$driveProject = 0;
$driveFileId = 0;   // 存在 Google Drive 的附件
$driveImageId = 0;

function fakeDir(): string
{
    return ctx()['fakeDir'];
}

function secretRow(string $key): ?string
{
    $st = ctx()['pdo']->prepare('SELECT value_enc FROM secret_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function settingRow(string $key): ?string
{
    $st = ctx()['pdo']->prepare('SELECT value_json FROM system_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function logCount(string $where, array $params = []): int
{
    $st = ctx()['pdo']->prepare('SELECT COUNT(*) FROM activity_logs WHERE ' . $where);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** 走完整的 OAuth 流程：連結 → 取得 state → 模擬 Google 帶授權碼導回 */
function connectDrive(Browser $b, string $code = 'good-code'): Browser
{
    $b->get('/admin/settings/storage/google/connect');
    assert_same(302, $b->status, '連結按鈕應導向 Google');
    parse_str((string)parse_url($b->location(), PHP_URL_QUERY), $q);
    return $b->get('/admin/settings/storage/google/callback?' . http_build_query(['state' => $q['state'] ?? '', 'code' => $code]));
}

test('權限：非管理者看不到設定中心（404），未登入導向登入頁', function (): void {
    foreach (['/admin/settings', '/admin/settings/mail', '/admin/settings/storage', '/admin/settings/storage/google/connect'] as $path) {
        assert_same(404, signedIn('g1p1')->get($path)->status, $path);
    }
    assert_same('/login', browser()->get('/admin/settings')->location());
    $b = signedIn('g1p1');
    $b->get('/');
    $b->post('/admin/settings', ['system__site_name' => '被竄改']);
    assert_same(404, $b->status);
    assert_same(null, settingRow('system.site_name'));
});

test('一般設定：未完成管理者驗證時不顯示設定內容、不能儲存；驗證後顯示所有設定', function () use (&$setAdmin): void {
    $setAdmin = adminLogin();
    foreach (['/admin/settings', '/admin/settings/mail', '/admin/settings/storage', '/admin/users', '/admin/permissions'] as $locked) {
        $page = $setAdmin->get($locked)->body;
        assert_contains('reauth-code', $page, "{$locked} 顯示驗證框");
        assert_not_contains('data-setting=', $page, "{$locked} 不輸出設定內容");
        assert_not_contains('data-perm-row', $page, "{$locked} 不輸出權限內容");
        assert_not_contains('新增使用者', $page, "{$locked} 不輸出使用者管理內容");
    }
    $setAdmin->get('/admin/settings');
    $setAdmin->post('/admin/settings', ['system__site_name' => '未驗證']);
    assert_same('/admin/settings', $setAdmin->location());
    assert_same(null, settingRow('system.site_name'));
    elevate($setAdmin, '/admin/settings');
    $html = $setAdmin->get('/admin/settings')->body;
    foreach (['system.site_name', 'system.timezone', 'security.single_session', 'security.login_max_failures', 'attachments.quota_mb', 'logs.retention_days', 'search.log_keywords'] as $key) {
        assert_contains('data-setting="' . $key . '"', $html, $key);
    }
    assert_not_contains('data-setting="mail.driver"', $html, '寄信設定在獨立頁面');
});

test('一般設定：任一欄位不合法時全部不儲存（422）；換行字元被拒絕', function () use (&$setAdmin): void {
    elevate($setAdmin, '/admin/settings');
    $setAdmin->get('/admin/settings');
    $setAdmin->post('/admin/settings', [
        'system__site_name' => '新名稱', 'system__timezone' => 'Asia/Taipei', 'security__login_max_failures' => '999',
    ]);
    assert_same(422, $setAdmin->status);
    assert_contains('請輸入 3–20 之間的整數', $setAdmin->body);
    assert_same(null, settingRow('system.site_name'), '不可部分儲存');
    $setAdmin->post('/admin/settings', ['system__site_name' => "名稱\r\nBcc: evil@example.com"]);
    assert_same(422, $setAdmin->status);
    assert_same(null, settingRow('system.site_name'));
});

test('一般設定：儲存後立即生效，紀錄修改前後的值；改回預設值時刪除覆寫', function () use (&$setAdmin): void {
    $current = function () use (&$setAdmin): array {
        $html = $setAdmin->get('/admin/settings')->body;
        $data = [];
        preg_match_all('#<input type="(?:text|number|email)"[^>]* name="([a-z_]+)" value="([^"]*)"#', $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $data[$x[1]] = html_entity_decode($x[2]);
        }
        preg_match_all('#name="([a-z_]+)" value="1" checked#', $html, $c);
        foreach ($c[1] as $name) {
            $data[$name] = '1';
        }
        $data['system__timezone'] = 'Asia/Taipei';
        $data['system__theme_color'] = 'default'; // 下拉選單不在上面的解析範圍
        return $data;
    };
    $data = $current();
    assert_same('專案管理平台', $data['system__site_name']);
    // 前面的測試已關閉單一裝置登入；切換成相反狀態以驗證開關可儲存
    $initialRow = settingRow('security.single_session');
    $wasOn = isset($data['security__single_session']);
    $data['system__site_name'] = '測試平台';
    if ($wasOn) {
        unset($data['security__single_session']); // 未勾選的開關只送出隱藏欄位 0
    } else {
        $data['security__single_session'] = '1';
    }
    $setAdmin->post('/admin/settings', $data);
    assert_same('/admin/settings', $setAdmin->location());
    assert_same('"測試平台"', settingRow('system.site_name'));
    assert_same($wasOn ? 'false' : null, settingRow('security.single_session'), '開啟為預設值，不保留覆寫列');
    assert_contains('測試平台', $setAdmin->get('/admin/settings')->body, '網站名稱立即套用');
    assert_true(logCount("action = 'settings.update' AND detail LIKE ?", ['%網站名稱：專案管理平台 → 測試平台%']) === 1);
    $flip = $wasOn ? '開啟 → 關閉' : '關閉 → 開啟';
    assert_true(logCount("action = 'settings.update' AND detail LIKE ?", ["%單一裝置登入：{$flip}%"]) === 1);

    $data = $current();
    $data['system__site_name'] = '專案管理平台';
    if ($wasOn) {
        $data['security__single_session'] = '1';
    } else {
        unset($data['security__single_session']);
    }
    $setAdmin->post('/admin/settings', $data);
    assert_same(null, settingRow('system.site_name'), '與預設值相同時不保留覆寫列');
    assert_same((bool)json_decode((string)($initialRow ?? 'true')), (bool)json_decode((string)(settingRow('security.single_session') ?? 'true')), '還原為原本的狀態');
});

test('寄信設定：密碼加密保存、不回顯；必要欄位檢查', function () use (&$setAdmin): void {
    elevate($setAdmin, '/admin/settings/mail');
    $setAdmin->get('/admin/settings/mail');
    $setAdmin->post('/admin/settings/mail', ['mail__driver' => 'smtp', 'mail__from_address' => '', 'mail__smtp_host' => '', 'mail__smtp_port' => '587', 'mail__smtp_encryption' => 'tls']);
    assert_same(422, $setAdmin->status);
    assert_contains('必須填寫寄件者 Email', $setAdmin->body);
    $setAdmin->post('/admin/settings/mail', [
        'mail__driver' => 'smtp', 'mail__from_address' => 'noreply@example.com', 'mail__from_name' => '專案平台通知',
        'mail__smtp_host' => '127.0.0.1', 'mail__smtp_port' => (string)ctx()['smtpPort'], 'mail__smtp_encryption' => 'none',
        'mail__smtp_username' => 'smtp-user', 'smtp_password' => 'smtp-pass',
    ]);
    assert_same('/admin/settings/mail', $setAdmin->location());
    $enc = secretRow('mail.smtp_password');
    assert_true($enc !== null && !str_contains($enc, 'smtp-pass'), '資料庫中只有密文');
    $page = $setAdmin->get('/admin/settings/mail')->body;
    assert_not_contains('smtp-pass', $page, '密碼不回顯到畫面');
    assert_contains('已設定（留空表示不變更）', $page);
    // 留空送出：密碼維持原值
    $setAdmin->post('/admin/settings/mail', [
        'mail__driver' => 'smtp', 'mail__from_address' => 'noreply@example.com', 'mail__from_name' => '專案平台通知',
        'mail__smtp_host' => '127.0.0.1', 'mail__smtp_port' => (string)ctx()['smtpPort'], 'mail__smtp_encryption' => 'none',
        'mail__smtp_username' => 'smtp-user', 'smtp_password' => '',
    ]);
    assert_same($enc, secretRow('mail.smtp_password'));
    assert_true(logCount("action = 'settings.update' AND detail LIKE ?", ['%SMTP 密碼：已更新%']) === 1);
});

test('寄信測試：SMTP 連線測試與測試信；中文主旨正確編碼；對話紀錄遮蔽帳號密碼', function () use (&$setAdmin): void {
    $setAdmin->get('/admin/settings/mail');
    $setAdmin->post('/admin/settings/mail/test', ['mode' => 'check']);
    $page = $setAdmin->get('/admin/settings/mail')->body;
    assert_contains('連線測試成功', $page);
    assert_contains('235 Authentication successful', $page);
    assert_contains('[密碼已遮蔽]', $page);
    assert_not_contains(base64_encode('smtp-pass'), $page);

    $setAdmin->post('/admin/settings/mail/test', ['mode' => 'send']);
    assert_contains('測試信已送出到 admin@example.com', $setAdmin->get('/admin/settings/mail')->body);
    $files = glob(fakeDir() . '/smtp_message_*.eml') ?: [];
    assert_same(1, count($files));
    $eml = (string)file_get_contents($files[0]);
    assert_contains('Subject: =?UTF-8?B?', $eml);
    assert_contains('From: =?UTF-8?B?' . base64_encode('專案平台通知') . '?= <noreply@example.com>', $eml);
    [, $body] = explode("\r\n\r\n", $eml, 2);
    assert_contains('收到這封信表示寄信設定正確', base64_decode(str_replace("\r\n", '', $body)));
    assert_true(logCount("action = 'mail.test' AND result = 'success'") === 2);
});

test('寄信測試：密碼錯誤時顯示伺服器拒絕原因並記錄失敗；Brevo API 寄送', function () use (&$setAdmin): void {
    $setAdmin->get('/admin/settings/mail');
    $base = ['mail__driver' => 'smtp', 'mail__from_address' => 'noreply@example.com', 'mail__from_name' => '專案平台通知',
        'mail__smtp_host' => '127.0.0.1', 'mail__smtp_port' => (string)ctx()['smtpPort'], 'mail__smtp_encryption' => 'none', 'mail__smtp_username' => 'smtp-user'];
    $setAdmin->post('/admin/settings/mail', $base + ['smtp_password' => 'wrong-pass']);
    $setAdmin->get('/admin/settings/mail');
    $setAdmin->post('/admin/settings/mail/test', ['mode' => 'check']);
    assert_contains('535 Authentication failed', $setAdmin->get('/admin/settings/mail')->body);
    assert_true(logCount("action = 'mail.test' AND result = 'failure'") === 1);

    $setAdmin->get('/admin/settings/mail');
    $setAdmin->post('/admin/settings/mail', array_merge($base, ['mail__driver' => 'brevo_api', 'brevo_api_key' => 'brevo-test-key']));
    $setAdmin->get('/admin/settings/mail');
    $setAdmin->post('/admin/settings/mail/test', ['mode' => 'send']);
    assert_contains('測試信已送出', $setAdmin->get('/admin/settings/mail')->body);
    $sent = json_decode((string)file_get_contents(fakeDir() . '/brevo_last.json'), true);
    assert_same('admin@example.com', $sent['to'][0]['email']);
    assert_same('noreply@example.com', $sent['sender']['email']);
    assert_contains('測試信', $sent['subject']);
});

test('機密不外洩：歷史紀錄與設定表都不含任何密碼或金鑰明文', function (): void {
    foreach (['smtp-pass', 'wrong-pass', 'brevo-test-key'] as $secret) {
        assert_same(0, logCount('detail LIKE ? OR actor_label LIKE ?', ["%{$secret}%", "%{$secret}%"]), $secret);
        $n = (int)ctx()['pdo']->query('SELECT COUNT(*) FROM system_settings WHERE value_json LIKE ' . ctx()['pdo']->quote("%{$secret}%"))->fetchColumn();
        assert_same(0, $n, $secret);
    }
});

test('附件儲存：尚未連結 Google 前不能選 Google Drive', function () use (&$setAdmin): void {
    elevate($setAdmin, '/admin/settings/storage');
    $html = $setAdmin->get('/admin/settings/storage')->body;
    assert_contains('data-drive-status="disconnected"', $html);
    assert_contains('/admin/settings/storage/google/callback', $html, '顯示要貼到 Google Cloud Console 的重新導向 URI');
    $setAdmin->post('/admin/settings/storage', ['attachments__storage' => 'google_drive', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    assert_same(422, $setAdmin->status);
    assert_contains('請先完成「連結 Google 帳號」', $setAdmin->body);
    assert_same(null, settingRow('attachments.storage'));
    $setAdmin->get('/admin/settings/storage/google/connect');
    assert_same('/admin/settings/storage', $setAdmin->location(), '尚未設定用戶端時不可開始授權');
});

test('Google 連結：state 錯誤、取消授權、授權碼錯誤都不會建立連線', function () use (&$setAdmin): void {
    $setAdmin->get('/admin/settings/storage');
    $setAdmin->post('/admin/settings/storage/google', ['storage__google_client_id' => 'test-client-id.apps.googleusercontent.com', 'google_client_secret' => 'test-client-secret']);
    assert_same('/admin/settings/storage', $setAdmin->location());
    assert_true(secretRow('google.client_secret') !== null);

    $setAdmin->get('/admin/settings/storage/google/connect');
    $loc = $setAdmin->location();
    assert_true(str_starts_with($loc, ctx()['fakeBase'] . '/auth?'), '導向 Google 授權頁');
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    assert_same('https://www.googleapis.com/auth/drive.file', $q['scope'], '只要求 drive.file 權限');
    assert_same('offline', $q['access_type']);
    assert_same('consent', $q['prompt']);
    assert_true(strlen($q['state']) === 64);

    $setAdmin->get('/admin/settings/storage/google/callback?state=forged&code=good-code');
    assert_contains('授權已過期或來源不明', $setAdmin->get('/admin/settings/storage')->body);
    // state 只能用一次：上一次的 state 已被清除
    $setAdmin->get('/admin/settings/storage/google/callback?' . http_build_query(['state' => $q['state'], 'code' => 'good-code']));
    assert_contains('授權已過期或來源不明', $setAdmin->get('/admin/settings/storage')->body);

    $setAdmin->get('/admin/settings/storage/google/connect');
    parse_str((string)parse_url($setAdmin->location(), PHP_URL_QUERY), $q);
    $setAdmin->get('/admin/settings/storage/google/callback?' . http_build_query(['state' => $q['state'], 'error' => 'access_denied']));
    assert_contains('已取消 Google 授權', $setAdmin->get('/admin/settings/storage')->body);

    connectDrive($setAdmin, 'bad-code');
    assert_contains('連結失敗', $setAdmin->get('/admin/settings/storage')->body);
    assert_same(null, secretRow('google.connection'));
});

test('Google 連結成功：Token 加密保存、建立專用資料夾、顯示帳號', function () use (&$setAdmin): void {
    connectDrive($setAdmin);
    $html = $setAdmin->get('/admin/settings/storage')->body;
    assert_contains('data-drive-status="connected"', $html);
    assert_contains('owner@example.com', $html);
    $refresh = (string)file_get_contents(fakeDir() . '/valid_refresh_token');
    assert_true(!str_contains((string)secretRow('google.connection'), $refresh), 'Refresh Token 只以密文保存');
    assert_not_contains($refresh, $html);
    assert_same(1, count(glob(fakeDir() . '/folder_*') ?: []), '建立一個專用資料夾');
    assert_true(logCount("action = 'storage.google.connect' AND result = 'success' AND detail LIKE '%owner@example.com%'") === 1);
    $setAdmin->post('/admin/settings/storage', ['attachments__storage' => 'google_drive', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    assert_same('"google_drive"', settingRow('attachments.storage'));
});

test('Google Drive 附件：檔案上傳到雲端（隨機檔名、主機不留副本），經 PHP 代理讀取並維持雙重授權', function () use (&$driveProject, &$driveFileId, &$driveImageId): void {
    $admin = adminLogin();
    $admin->submit('/projects/new', '/projects', ['name' => '雲端附件', 'description' => 'drive']);
    $driveProject = projectId('雲端附件');
    setProject($driveProject, 'editor2', 1, 1, 0);
    setProject($driveProject, 'viewer', 1, 0, 0);

    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . random_bytes(64);
    $r = uploadAs('editor2', $driveProject, fixture('drive.pdf', $pdf), '雲端報告.pdf');
    assert_same(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $driveFileId = (int)$r['json']['id'];
    $row = attachmentRow($driveFileId);
    assert_same('google_drive', $row['storage_driver']);
    assert_true(is_string($row['storage_ref']) && is_file(fakeDir() . '/files/' . $row['storage_ref']), '檔案存在雲端');
    assert_true(!is_file(storedPath($row['stored_name'])), '主機上沒有副本');
    $meta = json_decode((string)file_get_contents(fakeDir() . '/files/' . $row['storage_ref'] . '.meta'), true);
    assert_same($row['stored_name'], $meta['name'], '雲端檔名是隨機名稱，不含原始檔名');
    assert_true(str_starts_with($meta['parents'][0], 'folder'), '放在專用資料夾');

    $img = uploadAs('editor2', $driveProject, fixture('drive.png', pngBytes(300, 200)), '雲端截圖.png');
    $driveImageId = (int)$img['json']['id'];
    assert_true(attachmentRow($driveImageId)['preview_storage_ref'] !== null, '預覽圖也存雲端');
    $e = signedIn('editor2');
    $e->get("/projects/{$driveProject}");
    $e->post("/projects/{$driveProject}/notes", ['content' => '雲端附件測試', 'attachment_ids[0]' => (string)$driveFileId, 'attachment_ids[1]' => (string)$driveImageId]);

    $dl = signedIn('viewer')->get("/attachments/{$driveFileId}/download");
    assert_same(200, $dl->status);
    assert_same($pdf, $dl->body, '下載內容與上傳相同');
    assert_contains('attachment', $dl->header('content-disposition'));
    assert_same(200, signedIn('viewer')->get("/attachments/{$driveImageId}/preview")->status);
    assert_contains('image/', signedIn('viewer')->get("/attachments/{$driveImageId}/original")->header('content-type'));
    foreach (['g1p0', 'g0p0', 'g1p1'] as $u) {
        assert_same(404, signedIn($u)->get("/attachments/{$driveFileId}/download")->status, $u);
    }
    $tmpLeft = glob(ctx()['storage'] . '/tmp/dl-*') ?: [];
    assert_same([], $tmpLeft, '下載用的暫存檔已刪除');
});

test('Google Drive：Access Token 失效時自動以 Refresh Token 換新', function () use (&$driveFileId): void {
    file_put_contents(fakeDir() . '/valid_access_token', 'rotated-' . bin2hex(random_bytes(4)));
    assert_same(200, signedIn('viewer')->get("/attachments/{$driveFileId}/download")->status);
    $last = json_decode((string)file_get_contents(fakeDir() . '/last_token_request.json'), true);
    assert_same('refresh_token', $last['grant_type']);
});

test('切換儲存位置：新附件改存本機，雲端舊檔仍可讀取；雲端空間已滿時顯示一般訊息', function () use (&$setAdmin, &$driveProject, &$driveFileId): void {
    touch(fakeDir() . '/flag_quota');
    $r = uploadAs('editor2', $driveProject, fixture('full.pdf', "%PDF-1.4\n%%EOF\n"), '滿了.pdf');
    @unlink(fakeDir() . '/flag_quota');
    assert_same(422, $r['status']);
    assert_contains('暫時無法使用', $r['json']['error']);
    assert_true(logCount("action = 'attachment.upload' AND result = 'failure' AND detail LIKE '%Google Drive 空間已滿%'") === 1, '詳細原因留在紀錄給管理者');

    elevate($setAdmin, '/admin/settings/storage');
    $setAdmin->get('/admin/settings/storage');
    $setAdmin->post('/admin/settings/storage', ['attachments__storage' => 'local', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    $r = uploadAs('editor2', $driveProject, fixture('local.pdf', "%PDF-1.4\n%%EOF\n"), '本機.pdf');
    $row = attachmentRow((int)$r['json']['id']);
    assert_same('local', $row['storage_driver']);
    assert_same(null, $row['storage_ref']);
    assert_true(is_file(storedPath($row['stored_name'])));
    assert_same(200, signedIn('viewer')->get("/attachments/{$driveFileId}/download")->status, '舊檔仍從 Google Drive 讀取');
});

test('刪除雲端附件：同時刪除 Google Drive 上的原檔與預覽圖', function () use (&$driveProject, &$driveImageId): void {
    $row = attachmentRow($driveImageId);
    $e = signedIn('editor2');
    $e->get("/projects/{$driveProject}");
    $e->post("/projects/{$driveProject}/attachments/{$driveImageId}/delete", []);
    assert_true(!is_file(fakeDir() . '/files/' . $row['storage_ref']));
    assert_true(!is_file(fakeDir() . '/files/' . $row['preview_storage_ref']));
});

test('中斷連結：撤銷授權、雲端檔案保留但暫時無法讀取；換成其他帳號會被拒絕，同一帳號重新連結即恢復', function () use (&$setAdmin, &$driveFileId): void {
    $setAdmin->get('/admin/settings/storage');
    $setAdmin->post('/admin/settings/storage', ['attachments__storage' => 'google_drive', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    $setAdmin->get('/admin/settings/storage');
    $setAdmin->post('/admin/settings/storage/google', ['storage__google_client_id' => 'other-client.apps.googleusercontent.com']);
    assert_contains('請先「中斷連結」', $setAdmin->body, '連結中不可更換用戶端');

    $setAdmin->get('/admin/settings/storage');
    $setAdmin->post('/admin/settings/storage/google/disconnect', []);
    assert_same(null, secretRow('google.connection'));
    assert_same(null, settingRow('attachments.storage'), '新附件改回本機（預設值）');
    assert_contains('token=rt-', (string)@file_get_contents(fakeDir() . '/revoked.log'), '向 Google 撤銷授權');
    $row = attachmentRow($driveFileId);
    assert_true(is_file(fakeDir() . '/files/' . $row['storage_ref']), '雲端檔案沒有被刪除');
    assert_same(502, signedIn('viewer')->get("/attachments/{$driveFileId}/download")->status);
    assert_contains('owner@example.com', $setAdmin->get('/admin/settings/storage')->body, '提示用先前的帳號重新連結');

    file_put_contents(fakeDir() . '/account_email', 'someone-else@example.com');
    connectDrive($setAdmin);
    assert_contains('請改用該帳號連結', $setAdmin->get('/admin/settings/storage')->body);
    assert_same(null, secretRow('google.connection'));

    file_put_contents(fakeDir() . '/account_email', 'owner@example.com');
    connectDrive($setAdmin);
    assert_same(1, count(glob(fakeDir() . '/folder_*') ?: []), '同一帳號沿用原資料夾');
    assert_same(200, signedIn('viewer')->get("/attachments/{$driveFileId}/download")->status);
    assert_true(logCount("action = 'storage.google.disconnect'") === 1);
});

test('設定頁導覽：管理後台出現「系統設定」，三個設定頁都可開啟', function () use (&$setAdmin): void {
    assert_contains('href="/admin/settings"', $setAdmin->get('/admin/users')->body);
    foreach (['/admin/settings', '/admin/settings/mail', '/admin/settings/storage'] as $p) {
        assert_same(200, $setAdmin->get($p)->status, $p);
    }
});

test('站台顏色與標籤：選擇後全站（含登入頁）套用；只接受固定選項；標籤跳脫 HTML', function (): void {
    $admin = adminLogin();
    elevate($admin, '/admin/settings');
    $page = $admin->get('/admin/settings')->body;
    assert_contains('data-setting="system.theme_color"', $page);
    assert_contains('class="bg-body-tertiary theme-default"', $page, '預設樣式');
    preg_match_all('#<input type="(?:text|number|email)"[^>]* name="([a-z_]+)" value="([^"]*)"#', $page, $m, PREG_SET_ORDER);
    $data = [];
    foreach ($m as $x) {
        $data[$x[1]] = html_entity_decode($x[2]);
    }
    preg_match_all('#name="([a-z_]+)" value="1" checked#', $page, $c);
    foreach ($c[1] as $name) {
        $data[$name] = '1';
    }
    $data['system__timezone'] = 'Asia/Taipei';

    $admin->post('/admin/settings', $data + ['system__theme_color' => 'url(evil)']);
    assert_same(422, $admin->status, '不接受清單以外的值');
    $admin->get('/admin/settings');
    $admin->post('/admin/settings', array_merge($data, ['system__theme_color' => 'green', 'system__site_label' => '<b>測試站</b>']));
    assert_same('/admin/settings', $admin->location());
    $home = $admin->get('/')->body;
    assert_contains('class="bg-body-tertiary theme-green"', $home);
    assert_contains('&lt;b&gt;測試站&lt;/b&gt;', $home, '標籤跳脫 HTML');
    $login = browser()->get('/login')->body;
    assert_contains('theme-green', $login, '登入頁也套用，未登入就能分辨站台');
    assert_contains('data-site-label', $login);
    assert_contains('body.theme-green', (string)file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.css'));

    $admin->get('/admin/settings');
    $admin->post('/admin/settings', array_merge($data, ['system__theme_color' => 'default', 'system__site_label' => '']));
    assert_same(null, settingRow('system.theme_color'));
    assert_not_contains('data-site-label', browser()->get('/login')->body);
});
