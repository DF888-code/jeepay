<?php

declare(strict_types=1);

/**
 * 階段 6b 整合測試：隨手記附件（規格七之一）。
 * 重點：附件繼承專案的雙重授權、撤銷即時生效、檔案安全檢查（實際 MIME、白名單、重新編碼、不可執行）。
 */

$ap = 0;          // 附件測試專案
$fx = '';         // 測試檔案目錄
$imageId = 0;     // 已綁定的圖片附件
$pdfId = 0;       // 已綁定的 PDF 附件

function fixture(string $name, string $content): string
{
    $path = $GLOBALS['PH_FIXTURES'] . '/' . $name;
    file_put_contents($path, $content);
    return $path;
}

function pngBytes(int $w = 40, int $h = 30): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string)ob_get_clean();
}

function jpegWithMetadata(): string
{
    $im = imagecreatetruecolor(60, 40);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 200));
    ob_start();
    imagejpeg($im);
    imagedestroy($im);
    $jpeg = (string)ob_get_clean();
    // 在 SOI 後插入 APP1（EXIF）區段，模擬含 GPS 位置的照片
    $payload = "Exif\0\0SECRET-GPS-LOCATION-25.0330N-121.5654E";
    $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
}

function docx(bool $withMacro = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>test</w:t></w:r></w:p></w:body></w:document>');
    if ($withMacro) {
        $zip->addFromString('word/vbaProject.bin', 'macro');
    }
    $zip->close();
    $bytes = (string)file_get_contents($path);
    @unlink($path);
    return $bytes;
}

function uploadAs(string $user, int $projectId, string $file, string $name): array
{
    $b = signedIn($user);
    // 從專案列表取得 CSRF Token（未授權者的專案頁是 404，沒有表單）
    $b->get('/');
    $b->upload("/api/projects/{$projectId}/attachments", $file, $name);
    return ['status' => $b->status, 'json' => json_decode($b->body, true) ?? []];
}

function attachmentRow(int $id): array
{
    return (array)ctx()['pdo']->query("SELECT * FROM note_attachments WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
}

function storedPath(string $name): string
{
    return ctx()['storage'] . '/uploads/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . $name;
}

test('準備：附件測試專案與授權（g1p1 全部、editor2 查看＋編輯、viewer 只能查看、g1p0 沒有專案權限）', function () use (&$ap, &$fx): void {
    $fx = ctx()['storage'] . '/../fixtures';
    @mkdir($fx, 0700, true);
    $GLOBALS['PH_FIXTURES'] = $fx;
    $admin = adminLogin();
    $admin->submit('/projects/new', '/projects', ['name' => '附件測試', 'description' => '附件']);
    $ap = projectId('附件測試');
    setProject($ap, 'g1p1', 1, 1, 1);
    setProject($ap, 'editor2', 1, 1, 0);
    setProject($ap, 'viewer', 1, 0, 0);
    assert_true($ap > 0);
});

test('上傳圖片：成為只有上傳者看得到的暫存附件；儲存名稱為隨機值且沒有副檔名', function () use (&$ap): void {
    $r = uploadAs('editor2', $ap, fixture('shot.png', pngBytes()), '錯誤畫面.png');
    assert_same(200, $r['status']);
    assert_same('image', $r['json']['kind']);
    $row = attachmentRow((int)$r['json']['id']);
    assert_same('pending', $row['status']);
    assert_true((bool)preg_match('/^[a-f0-9]{64}$/', $row['stored_name']), '儲存名稱為 64 位隨機十六進位');
    assert_true(is_file(storedPath($row['stored_name'])), '檔案應存在 storage/uploads');
    assert_true(is_file(ctx()['storage'] . '/uploads/.htaccess'), '上傳目錄應有拒絕存取的 .htaccess');
    $id = (int)$r['json']['id'];
    assert_same(200, signedIn('editor2')->get("/attachments/{$id}/preview")->status, '上傳者可預覽');
    assert_same(404, signedIn('viewer')->get("/attachments/{$id}/preview")->status, '暫存附件其他人看不到');
    assert_same(404, signedIn('g1p1')->get("/attachments/{$id}/download")->status);
});

test('新增隨手記：只有附件沒有文字也可以；圖片綁定後依專案權限開放', function () use (&$ap, &$imageId): void {
    $r = uploadAs('editor2', $ap, fixture('shot2.png', pngBytes(800, 600)), '首頁錯誤.png');
    $imageId = (int)$r['json']['id'];
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes", ['content' => '這是網站目前出現的錯誤畫面。', 'attachment_ids[0]' => (string)$imageId]);
    $row = attachmentRow($imageId);
    assert_same('attached', $row['status']);
    $page = signedIn('viewer')->get("/projects/{$ap}")->body;
    assert_contains("/attachments/{$imageId}/preview", $page, '圖片直接顯示在隨手記中');
    assert_contains('這是網站目前出現的錯誤畫面。', $page);
    $prev = signedIn('viewer')->get("/attachments/{$imageId}/preview");
    assert_same(200, $prev->status);
    assert_contains('image/', $prev->header('content-type'));
    assert_same(200, signedIn('viewer')->get("/attachments/{$imageId}/original")->status);
    assert_same(200, signedIn('viewer')->get("/attachments/{$imageId}/download")->status);
});

test('雙重授權：全域有但專案沒有、專案有但全域沒有、兩者皆無，都不能透過圖片或下載網址取得', function () use (&$ap, &$imageId): void {
    setProject($ap, 'g0p1', 1, 1, 1);
    foreach (['g1p0', 'g0p1', 'g0p0'] as $u) {
        foreach (['preview', 'original', 'download'] as $kind) {
            $res = signedIn($u)->get("/attachments/{$imageId}/{$kind}");
            assert_same(404, $res->status, "{$u} {$kind}");
            assert_not_contains("\x89PNG", $res->body, '不得回傳檔案內容');
        }
    }
    assert_same(404, signedIn('g0p0')->get('/attachments/999999/preview')->status);
});

test('撤銷專案權限後，原本可開啟的圖片與下載網址立即失效', function () use (&$ap, &$imageId): void {
    assert_same(200, signedIn('viewer')->get("/attachments/{$imageId}/preview")->status);
    setProject($ap, 'viewer', 0, 0, 0);
    assert_same(404, signedIn('viewer')->get("/attachments/{$imageId}/preview")->status);
    assert_same(404, signedIn('viewer')->get("/attachments/{$imageId}/download")->status);
    setProject($ap, 'viewer', 1, 0, 0);
    setGlobal('viewer', 0, 0, 0, 0);
    assert_same(404, signedIn('viewer')->get("/attachments/{$imageId}/preview")->status, '撤銷全域查看');
    setGlobal('viewer', 1, 0, 0, 0);
});

test('上傳需要編輯權限：只能查看的人與未授權的人都不能上傳', function () use (&$ap): void {
    $file = fixture('x.png', pngBytes());
    assert_same(404, uploadAs('viewer', $ap, $file, 'x.png')['status'], 'viewer');
    assert_same(404, uploadAs('g1p0', $ap, $file, 'x.png')['status'], 'g1p0');
    assert_same(404, uploadAs('g0p0', $ap, $file, 'x.png')['status'], 'g0p0');
});

test('不能把別人的暫存附件、或其他專案的暫存附件綁到自己的隨手記', function () use (&$ap): void {
    $others = (int)uploadAs('g1p1', $ap, fixture('g.png', pngBytes()), 'g1p1 的檔案.png')['json']['id'];
    $other = projectId('公司規章');
    setProject($other, 'editor2', 1, 1, 0);
    $crossProject = (int)uploadAs('editor2', $other, fixture('c.png', pngBytes()), '別的專案.png')['json']['id'];
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes", ['content' => '嘗試盜用', 'attachment_ids[0]' => (string)$others, 'attachment_ids[1]' => (string)$crossProject]);
    assert_same('pending', attachmentRow($others)['status']);
    assert_same('pending', attachmentRow($crossProject)['status']);
    $e->get("/projects/{$ap}");
    assert_same(404, json_decode($e->post("/api/attachments/{$others}/discard", [])->body, true) === null ? 0 : $e->status, '不能捨棄他人的暫存附件');
    assert_same('pending', attachmentRow($others)['status']);
});

test('檔案安全：以實際內容判斷格式，偽裝的程式、網頁、SVG、可執行檔、副檔名不符、含巨集 Office 一律拒絕', function () use (&$ap): void {
    $cases = [
        ['shell.jpg', "<?php system(\$_GET['c']); ?>"],
        ['shell.php', "<?php echo 1; ?>"],
        ['page.txt', "hello <script>alert(1)</script>"],
        ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ['image.pdf', pngBytes()],
        ['tool.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF" . str_repeat("\0", 64)],
        ['macro.docx', docx(true)],
        ['noext', pngBytes()],
    ];
    foreach ($cases as [$name, $content]) {
        $r = uploadAs('editor2', $ap, fixture('bad-' . md5($name), $content), $name);
        assert_same(422, $r['status'], $name);
        assert_true(isset($r['json']['error']), "{$name} 應回傳錯誤訊息");
    }
});

test('檔案安全：圖片重新編碼，移除 EXIF（含 GPS）與夾帶在圖片後的程式碼', function () use (&$ap): void {
    $jpg = (int)uploadAs('editor2', $ap, fixture('photo.jpg', jpegWithMetadata()), '手機照片.jpg')['json']['id'];
    $polyglot = (int)uploadAs('editor2', $ap, fixture('poly.png', pngBytes() . "<?php system('id'); ?>"), '夾帶.png')['json']['id'];
    assert_true($jpg > 0 && $polyglot > 0, '合法圖片應可上傳');
    $e = signedIn('editor2');
    $orig = $e->get("/attachments/{$jpg}/original")->body;
    assert_same("\xFF\xD8", substr($orig, 0, 2), '仍為 JPEG');
    assert_not_contains('SECRET-GPS-LOCATION', $orig, 'EXIF 應被移除');
    assert_not_contains('<?php', $e->get("/attachments/{$polyglot}/original")->body, '夾帶的程式碼應被移除');
});

test('一般附件：PDF、Word 可上傳；下載時強制下載並帶安全標頭；檔名以 UTF-8 編碼', function () use (&$ap, &$pdfId): void {
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $r = uploadAs('editor2', $ap, fixture('spec.pdf', $pdf), '需求規格書 v1.pdf');
    assert_same(200, $r['status']);
    assert_same('file', $r['json']['kind']);
    $pdfId = (int)$r['json']['id'];
    $doc = uploadAs('editor2', $ap, fixture('a.docx', docx()), '會議紀錄.docx');
    assert_same(200, $doc['status'], 'docx 應可上傳');
    assert_same('Word', $doc['json']['label']);
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes", ['content' => '規格書與會議紀錄', 'attachment_ids[0]' => (string)$pdfId, 'attachment_ids[1]' => (string)$doc['json']['id']]);
    $page = signedIn('viewer')->get("/projects/{$ap}")->body;
    assert_contains('需求規格書 v1.pdf', $page);
    assert_contains('PDF', $page);
    $d = signedIn('viewer')->get("/attachments/{$pdfId}/download");
    assert_same(200, $d->status);
    assert_contains('attachment;', $d->header('content-disposition'));
    assert_contains("filename*=UTF-8''" . rawurlencode('需求規格書 v1.pdf'), $d->header('content-disposition'));
    assert_same('nosniff', $d->header('x-content-type-options'));
    assert_contains('sandbox', $d->header('content-security-policy'));
    assert_contains('no-store', $d->header('cache-control'));
    assert_same($pdf, $d->body);
});

test('大小與數量限制：超過單檔上限、超過總容量、超過每則附件數都會被拒絕', function () use (&$ap): void {
    setSetting('attachments.max_file_mb', '1');
    $big = uploadAs('editor2', $ap, fixture('big.txt', str_repeat("a\n", 800000)), 'big.txt');
    assert_same(422, $big['status']);
    assert_contains('1 MB', $big['json']['error']);
    setSetting('attachments.max_file_mb', '10');

    $pdo = ctx()['pdo'];
    $uid = (int)userRow('editor2')['id'];
    $pdo->exec("INSERT INTO note_attachments (project_id, uploader_id, original_name, original_name_norm, kind, mime_type, size_bytes, sha256, storage_driver, stored_name, status, created_at)
                VALUES ({$ap}, {$uid}, 'filler', 'filler', 'file', 'text/plain', 600000000, REPEAT('a', 32), 'local', REPEAT('f', 64), 'attached', NOW())");
    $full = uploadAs('editor2', $ap, fixture('small.png', pngBytes()), 'small.png');
    assert_same(422, $full['status'], '總容量已滿');
    assert_contains('上限', $full['json']['error']);
    $pdo->exec("DELETE FROM note_attachments WHERE original_name = 'filler'");

    setSetting('attachments.max_per_note', '1');
    $a = (int)uploadAs('editor2', $ap, fixture('m1.png', pngBytes()), 'm1.png')['json']['id'];
    $b = (int)uploadAs('editor2', $ap, fixture('m2.png', pngBytes()), 'm2.png')['json']['id'];
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes", ['content' => '兩張圖', 'attachment_ids[0]' => (string)$a, 'attachment_ids[1]' => (string)$b]);
    assert_same(['attached', 'pending'], [attachmentRow($a)['status'], attachmentRow($b)['status']]);
    setSetting('attachments.max_per_note', '20');
});

test('刪除附件：只能查看的人不能刪；作者可刪自己隨手記上的附件且實體檔案一併移除；具刪除權限者可刪他人的', function () use (&$ap, &$imageId, &$pdfId): void {
    $v = signedIn('viewer');
    $v->get("/projects/{$ap}");
    assert_same(404, $v->post("/projects/{$ap}/attachments/{$imageId}/delete", [])->status);
    $stored = attachmentRow($imageId)['stored_name'];
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/attachments/{$imageId}/delete", []);
    assert_same('deleted', attachmentRow($imageId)['status']);
    assert_true(!is_file(storedPath($stored)), '實體檔案應已刪除');
    assert_same(404, $e->get("/attachments/{$imageId}/preview")->status);
    $g = signedIn('g1p1');
    $g->get("/projects/{$ap}");
    $g->post("/projects/{$ap}/attachments/{$pdfId}/delete", []);
    assert_same('deleted', attachmentRow($pdfId)['status'], '具刪除權限可刪他人的附件');
});

test('刪除隨手記後，其附件無法再開啟', function () use (&$ap): void {
    $id = (int)uploadAs('editor2', $ap, fixture('n.png', pngBytes()), 'n.png')['json']['id'];
    $e = signedIn('editor2');
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes", ['content' => '將被刪除', 'attachment_ids[0]' => (string)$id]);
    $noteId = (int)attachmentRow($id)['note_id'];
    assert_same(200, $e->get("/attachments/{$id}/preview")->status);
    $e->get("/projects/{$ap}");
    $e->post("/projects/{$ap}/notes/{$noteId}/delete", []);
    assert_same(404, $e->get("/attachments/{$id}/preview")->status);
});

test('過期的暫存附件：下次上傳時自動清理（主機沒有排程）', function () use (&$ap): void {
    $id = (int)uploadAs('editor2', $ap, fixture('old.png', pngBytes()), 'old.png')['json']['id'];
    $stored = attachmentRow($id)['stored_name'];
    ctx()['pdo']->exec("UPDATE note_attachments SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = {$id}");
    uploadAs('editor2', $ap, fixture('new.png', pngBytes()), 'new.png');
    assert_same('deleted', attachmentRow($id)['status']);
    assert_true(!is_file(storedPath($stored)));
});

test('歷史操作紀錄：上傳、拒絕、檢視原圖、下載、刪除都有記錄，且不含檔案內容', function (): void {
    $pdo = ctx()['pdo'];
    foreach ([['attachment.upload', 'success'], ['attachment.upload', 'failure'], ['attachment.view', 'success'], ['attachment.download', 'success'],
              ['attachment.delete', 'success'], ['attachment.preview', 'denied'], ['attachment.download', 'denied'], ['attachment.upload', 'denied']] as [$action, $result]) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE action = ' . $pdo->quote($action) . ' AND result = ' . $pdo->quote($result))->fetchColumn();
        assert_true($n > 0, "缺少紀錄：{$action} / {$result}");
    }
    $dump = implode("\n", array_map(fn(array $r): string => implode('|', $r), $pdo->query('SELECT * FROM activity_logs')->fetchAll(PDO::FETCH_NUM)));
    assert_not_contains('%PDF-1.4', $dump);
    assert_not_contains('SECRET-GPS', $dump);
});
