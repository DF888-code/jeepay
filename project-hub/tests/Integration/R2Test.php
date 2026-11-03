<?php

declare(strict_types=1);

/**
 * Cloudflare R2 附件儲存整合測試（假 R2 服務會驗證 SigV4 簽章與內容雜湊）。
 * 接續 SettingsTest（$setAdmin 管理者瀏覽器、雲端附件專案）與 AttachmentTest 的輔助函式。
 */

$r2Admin = null;
$r2FileId = 0;

function r2Object(string $key): ?string
{
    $f = ctx()['fakeDir'] . '/r2/' . hash('sha256', $key);
    clearstatcache(); // 假服務在另一個行程刪除檔案，PHP 的檔案狀態快取可能過期
    return is_file($f) ? (string)file_get_contents($f) : null;
}

test('R2 設定：格式錯誤時不儲存；金鑰加密保存、不回顯；未完成設定不能選 R2', function () use (&$r2Admin): void {
    $r2Admin = adminLogin();
    elevate($r2Admin, '/admin/settings/storage');
    $page = $r2Admin->get('/admin/settings/storage')->body;
    assert_contains('data-r2-status="missing"', $page);
    assert_contains('Object Read &amp; Write', $page, '有申請與設定步驟');
    assert_contains('不要開啟「公開存取」', $page);
    $r2Admin->post('/admin/settings/storage', ['attachments__storage' => 'r2', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    assert_same(422, $r2Admin->status);
    assert_contains('請先完成 Cloudflare R2 設定', $r2Admin->body);

    $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => 'https://evil.example.com', 'storage__r2_bucket' => 'Bad_Bucket', 'r2_access_key_id' => 'r2-test-access']);
    assert_same(422, $r2Admin->status);
    assert_same(null, secretRow('r2.access_key_id'));

    $r2Admin->post('/admin/settings/storage/r2', [
        'storage__r2_endpoint' => 'https://' . str_repeat('a1', 16) . '.r2.cloudflarestorage.com', 'storage__r2_bucket' => 'ph-bucket',
        'r2_access_key_id' => 'r2testaccess', 'r2_secret_access_key' => 'r2testsecret',
    ]);
    assert_same('/admin/settings/storage', $r2Admin->location());
    $enc = (string)secretRow('r2.secret_access_key');
    assert_true($enc !== '' && !str_contains($enc, 'r2testsecret'), '只存密文');
    $page = $r2Admin->get('/admin/settings/storage')->body;
    assert_not_contains('r2testsecret', $page);
    assert_contains('data-r2-status="configured"', $page);
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE detail LIKE '%r2testsecret%' OR detail LIKE '%r2testaccess%'")->fetchColumn() === 0, '金鑰不入紀錄');
});

test('R2 端點：貼上含 bucket 的 S3 API 網址會自動拆開；接受歐盟儲存區；拒絕非 Cloudflare 的網址', function () use (&$r2Admin): void {
    $acct = str_repeat('b2', 16);
    $r2Admin->get('/admin/settings/storage');
    $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => "https://{$acct}.eu.r2.cloudflarestorage.com/eu-bucket/", 'storage__r2_bucket' => '']);
    assert_same('/admin/settings/storage', $r2Admin->location());
    assert_same(json_encode("https://{$acct}.eu.r2.cloudflarestorage.com"), settingRow('storage.r2_endpoint'));
    assert_same('"eu-bucket"', settingRow('storage.r2_bucket'), '從網址取出 bucket');
    foreach (['http://' . $acct . '.r2.cloudflarestorage.com', 'https://' . $acct . '.r2.cloudflarestorage.com.evil.example', 'https://127.0.0.1', 'https://' . $acct . '.r2.cloudflarestorage.com:8443', 'https://x@' . $acct . '.r2.cloudflarestorage.com'] as $bad) {
        $r2Admin->get('/admin/settings/storage');
        $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => $bad, 'storage__r2_bucket' => 'ph-bucket']);
        assert_same(422, $r2Admin->status, $bad);
        assert_contains('端點格式不正確', $r2Admin->body, $bad);
    }
    $r2Admin->get('/admin/settings/storage');
    $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => "https://{$acct}.r2.cloudflarestorage.com/one", 'storage__r2_bucket' => 'two']);
    assert_contains('與 bucket 欄位（two）不同', $r2Admin->body);
});

test('R2 測試連線：Access Key 或 Secret 錯誤時顯示原因；正確時寫入、讀回、刪除都成功', function () use (&$r2Admin): void {
    $save = function (string $access, string $secret) use (&$r2Admin): void {
        $r2Admin->get('/admin/settings/storage');
        $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => 'https://' . str_repeat('a1', 16) . '.r2.cloudflarestorage.com', 'storage__r2_bucket' => 'ph-bucket',
            'r2_access_key_id' => $access, 'r2_secret_access_key' => $secret]);
        $r2Admin->get('/admin/settings/storage');
        $r2Admin->post('/admin/settings/storage/r2/test', []);
    };
    $save('wrongaccess', 'r2testsecret');
    assert_contains('InvalidAccessKeyId', $r2Admin->get('/admin/settings/storage')->body);
    $save('r2testaccess', 'wrongsecret');
    assert_contains('SignatureDoesNotMatch', $r2Admin->get('/admin/settings/storage')->body, 'Secret 錯誤時簽章不符');
    $save('r2testaccess', 'r2testsecret');
    assert_contains('Cloudflare R2 連線測試成功', $r2Admin->get('/admin/settings/storage')->body);
    $log = (string)@file_get_contents(ctx()['fakeDir'] . '/r2_requests.log');
    assert_true(str_contains($log, 'PUT .ph-connection-test-') && str_contains($log, 'DELETE .ph-connection-test-'), '測試檔寫入後刪除');
    assert_true((int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'storage.r2.test' AND result = 'failure'")->fetchColumn() === 2);
});

test('R2 附件：上傳存到 R2（隨機名稱、主機不留副本），經 PHP 代理讀取並維持雙重授權；刪除時一併刪除', function () use (&$r2Admin, &$r2FileId): void {
    $r2Admin->get('/admin/settings/storage');
    $r2Admin->post('/admin/settings/storage', ['attachments__storage' => 'r2', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    assert_same('"r2"', settingRow('attachments.storage'));
    $project = projectId('雲端附件');
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . random_bytes(32);
    $r = uploadAs('editor2', $project, fixture('r2.pdf', $pdf), 'R2報告.pdf');
    assert_same(200, $r['status'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    $r2FileId = (int)$r['json']['id'];
    $row = attachmentRow($r2FileId);
    assert_same('r2', $row['storage_driver']);
    assert_same($pdf, r2Object($row['stored_name']), '物件名稱即隨機名稱，內容一致');
    assert_true(!is_file(storedPath($row['stored_name'])), '主機上沒有副本');
    $e = signedIn('editor2');
    $e->get("/projects/{$project}");
    $e->post("/projects/{$project}/notes", ['content' => 'R2 附件', 'attachment_ids[0]' => (string)$r2FileId]);

    $dl = signedIn('viewer')->get("/attachments/{$r2FileId}/download");
    assert_same(200, $dl->status);
    assert_same($pdf, $dl->body);
    foreach (['g1p0', 'g0p0'] as $u) {
        assert_same(404, signedIn($u)->get("/attachments/{$r2FileId}/download")->status, $u);
    }
    assert_same([], glob(ctx()['storage'] . '/tmp/dl-*') ?: [], '下載暫存檔已刪除');
    assert_same(200, signedIn('viewer')->get('/attachments/' . (int)attachmentRow($r2FileId)['id'] . '/download')->status);
});

test('R2：已有附件時不能更換 bucket；切回本機後 R2 舊檔仍可讀取', function () use (&$r2Admin, &$r2FileId): void {
    $r2Admin->get('/admin/settings/storage');
    $r2Admin->post('/admin/settings/storage/r2', ['storage__r2_endpoint' => 'https://' . str_repeat('a1', 16) . '.r2.cloudflarestorage.com', 'storage__r2_bucket' => 'another-bucket']);
    assert_same(422, $r2Admin->status);
    assert_contains('不能更換端點或 bucket', $r2Admin->body);
    $r2Admin->get('/admin/settings/storage');
    $r2Admin->post('/admin/settings/storage', ['attachments__storage' => 'local', 'attachments__drive_quota_mb' => '10240', 'attachments__r2_quota_mb' => '9216']);
    assert_same(null, settingRow('attachments.storage'));
    assert_same(200, signedIn('viewer')->get("/attachments/{$r2FileId}/download")->status, '舊檔仍從 R2 讀取');
});

test('R2：刪除附件時同時刪除 R2 上的物件', function () use (&$r2FileId): void {
    $row = attachmentRow($r2FileId);
    $project = (int)$row['project_id'];
    $e = signedIn('editor2');
    $e->get("/projects/{$project}");
    $e->post("/projects/{$project}/attachments/{$r2FileId}/delete", []);
    assert_same(null, r2Object($row['stored_name']));
});
