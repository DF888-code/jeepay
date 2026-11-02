<?php

declare(strict_types=1);

/**
 * 階段 6a 整合測試：專案說明（樂觀併發、版本歷史）、關鍵字、隨手記。
 * 接續 ProjectTest 的帳號（g1p1：全域查看／編輯／刪除）。
 */

$cp = 0; // 本檔使用的專案 ID

test('準備：建立專案並設定授權（g1p1 全部、editor2 查看＋編輯、viewer 只能查看）', function () use (&$cp): void {
    seedUser('editor2', 'Matrix-Pass-001');
    seedUser('viewer', 'Matrix-Pass-001');
    setGlobal('editor2', 1, 1, 0, 0);
    setGlobal('viewer', 1, 0, 0, 0);
    $admin = adminLogin();
    $admin->submit('/projects/new', '/projects', ['name' => '內容測試', 'description' => "第一版說明\n第二行"]);
    $cp = projectId('內容測試');
    setProject($cp, 'g1p1', 1, 1, 1);
    setProject($cp, 'editor2', 1, 1, 0);
    setProject($cp, 'viewer', 1, 0, 0);
    assert_true($cp > 0);
});

test('編輯說明：需要雙重授權的編輯權限；只能查看的人與未授權的人都是 404', function () use (&$cp): void {
    assert_same(200, signedIn('editor2')->get("/projects/{$cp}/description/edit")->status);
    assert_same(404, signedIn('viewer')->get("/projects/{$cp}/description/edit")->status);
    assert_same(404, signedIn('g0p0')->get("/projects/{$cp}/description/edit")->status);
    $v = signedIn('viewer');
    $v->get("/projects/{$cp}");
    assert_same(404, $v->post("/projects/{$cp}/description", ['description' => '偷改', 'version' => '1'])->status);
    assert_not_contains('編輯說明', $v->get("/projects/{$cp}")->body, '只能查看的人不顯示編輯按鈕');
});

test('多人同時編輯：先儲存者成功；後儲存者（持舊版本號）收到 409，內容不被覆蓋，且保留其未儲存的文字', function () use (&$cp): void {
    $a = signedIn('g1p1');
    $b = signedIn('editor2');
    $a->get("/projects/{$cp}/description/edit");
    $b->get("/projects/{$cp}/description/edit");
    $a->post("/projects/{$cp}/description", ['description' => 'A 的修改', 'version' => '1']);
    assert_same("/projects/{$cp}", $a->location());

    $b->post("/projects/{$cp}/description", ['description' => 'B 尚未儲存的重要文字', 'version' => '1']);
    assert_same(409, $b->status);
    assert_contains('專案說明已被其他使用者修改，請重新載入頁面後再編輯。', $b->body);
    assert_contains('B 尚未儲存的重要文字', $b->body, '必須保留使用者尚未儲存的文字');
    assert_contains('A 的修改', $b->body, '提供最新內容供對照');
    $row = ctx()['pdo']->query("SELECT description, description_version FROM projects WHERE id = {$cp}")->fetch(PDO::FETCH_ASSOC);
    assert_same(['description' => 'A 的修改', 'description_version' => 2], $row, '不可覆蓋、不可自動合併');
});

test('竄改版本號（送出比目前更新的版本）同樣被拒絕', function () use (&$cp): void {
    $a = signedIn('g1p1');
    $a->get("/projects/{$cp}/description/edit");
    $a->post("/projects/{$cp}/description", ['description' => '竄改', 'version' => '99']);
    assert_same(409, $a->status);
    assert_same(2, (int)ctx()['pdo']->query("SELECT description_version FROM projects WHERE id = {$cp}")->fetchColumn());
});

test('版本歷史：保存修改前與修改後的完整內容、修改者；可查看的人才能看', function () use (&$cp): void {
    $rows = ctx()['pdo']->query("SELECT version_before, version_after, content_before, content_after FROM project_description_history WHERE project_id = {$cp} ORDER BY version_after")->fetchAll(PDO::FETCH_ASSOC);
    assert_same(2, count($rows));
    assert_same(['version_before' => 1, 'version_after' => 2, 'content_before' => "第一版說明\n第二行", 'content_after' => 'A 的修改'], $rows[1]);
    $page = signedIn('viewer')->get("/projects/{$cp}/history");
    assert_same(200, $page->status);
    assert_contains('第一版說明', $page->body);
    assert_contains('A 的修改', $page->body);
    assert_same(404, signedIn('g0p0')->get("/projects/{$cp}/history")->status);
    assert_same(404, signedIn('g1p0')->get("/projects/{$cp}/history")->status);
});

test('關鍵字：編輯者可新增；全形／大小寫不同視為相同不重複建立，並保留原文', function () use (&$cp): void {
    $e = signedIn('editor2');
    $e->get("/projects/{$cp}");
    $e->post("/projects/{$cp}/keywords", ['keyword' => 'API 設計']);
    $e->get("/projects/{$cp}");
    $e->post("/projects/{$cp}/keywords", ['keyword' => 'ａｐｉ　設計']);
    assert_contains('已有相同的關鍵字「API 設計」', $e->get("/projects/{$cp}")->body);
    $rows = ctx()['pdo']->query("SELECT keyword FROM project_keywords WHERE project_id = {$cp}")->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['API 設計'], $rows);
});

test('關鍵字：只能查看的人不能新增或刪除；不能用其他專案的關鍵字 ID 刪除', function () use (&$cp): void {
    $kid = (int)ctx()['pdo']->query("SELECT id FROM project_keywords WHERE project_id = {$cp}")->fetchColumn();
    $v = signedIn('viewer');
    $v->get("/projects/{$cp}");
    assert_same(404, $v->post("/projects/{$cp}/keywords", ['keyword' => '偷加'])->status, 'viewer 新增');
    $v->get("/projects/{$cp}");
    assert_same(404, $v->post("/projects/{$cp}/keywords/{$kid}/delete", [])->status, 'viewer 刪除');
    $other = projectId('公司規章');
    $e = signedIn('g1p1');
    $e->get('/');
    assert_same(404, $e->post("/projects/{$other}/keywords/{$kid}/delete", [])->status, '關鍵字不屬於該專案');
    assert_same(1, (int)ctx()['pdo']->query("SELECT COUNT(*) FROM project_keywords WHERE id = {$kid}")->fetchColumn());
    $e->get("/projects/{$cp}");
    $e->post("/projects/{$cp}/keywords/{$kid}/delete", []);
    assert_same(0, (int)ctx()['pdo']->query("SELECT COUNT(*) FROM project_keywords WHERE id = {$kid}")->fetchColumn());
});

test('隨手記：作者一律取自登入帳號，表單中偽造的作者欄位被忽略；內容會跳脫 HTML', function () use (&$cp): void {
    $e = signedIn('editor2');
    $e->get("/projects/{$cp}");
    $adminId = (int)userRow(ADMIN_USER)['id'];
    $e->post("/projects/{$cp}/notes", ['content' => "<script>alert(1)</script>\n第二行", 'author_id' => (string)$adminId]);
    $note = ctx()['pdo']->query("SELECT author_id, content FROM project_notes WHERE project_id = {$cp} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assert_same((int)userRow('editor2')['id'], (int)$note['author_id']);
    $page = $e->get("/projects/{$cp}")->body;
    assert_not_contains('<script>alert(1)</script>', $page);
    assert_contains('&lt;script&gt;', $page);
});

test('隨手記：只能查看的人看得到但不能新增；未授權的人頁面與 API 都 404', function () use (&$cp): void {
    $v = signedIn('viewer');
    $page = $v->get("/projects/{$cp}")->body;
    assert_contains('&lt;script&gt;', $page, '可查看隨手記');
    assert_not_contains('新增隨手記', $page);
    assert_same(404, $v->post("/projects/{$cp}/notes", ['content' => '偷寫'])->status);
    assert_same(200, $v->get("/api/projects/{$cp}/notes")->status);
    assert_same(404, signedIn('g0p0')->get("/api/projects/{$cp}/notes")->status);
    assert_same(404, signedIn('g1p0')->get("/api/projects/{$cp}/notes")->status);
});

test('隨手記：最新在上，超過 20 筆時分頁載入更早的紀錄', function () use (&$cp): void {
    $e = signedIn('g1p1');
    for ($i = 1; $i <= 22; $i++) {
        $e->get("/projects/{$cp}");
        $e->post("/projects/{$cp}/notes", ['content' => "批次紀錄 {$i}"]);
    }
    $first = json_decode($e->get("/api/projects/{$cp}/notes")->body, true);
    assert_same(20, count($first['items']));
    assert_same('批次紀錄 22', $first['items'][0]['content'], '最新的在最上方');
    assert_true(is_int($first['next_before']));
    $second = json_decode($e->get("/api/projects/{$cp}/notes?before={$first['next_before']}")->body, true);
    assert_same(3, count($second['items']), '第二頁為剩下的 3 筆（含先前的 1 筆）');
    assert_same(null, $second['next_before']);
    $html = $e->get("/projects/{$cp}")->body;
    assert_contains('載入更早的隨手記', $html);
    assert_not_contains('批次紀錄 2<', $e->get("/projects/{$cp}")->body, '第一頁不應載入全部紀錄');
});

test('隨手記：只能修改自己的；他人的隨手記不能修改', function () use (&$cp): void {
    $mine = (int)ctx()['pdo']->query("SELECT id FROM project_notes WHERE project_id = {$cp} AND content LIKE '批次紀錄 1' LIMIT 1")->fetchColumn();
    $g = signedIn('g1p1');
    $g->get("/projects/{$cp}");
    $g->post("/projects/{$cp}/notes/{$mine}/update", ['content' => '我自己的修改']);
    assert_same('我自己的修改', ctx()['pdo']->query("SELECT content FROM project_notes WHERE id = {$mine}")->fetchColumn());
    $e = signedIn('editor2');
    $e->get("/projects/{$cp}");
    assert_same(404, $e->post("/projects/{$cp}/notes/{$mine}/update", ['content' => '冒改他人'])->status);
    assert_same('我自己的修改', ctx()['pdo']->query("SELECT content FROM project_notes WHERE id = {$mine}")->fetchColumn());
});

test('隨手記刪除：沒有刪除權限不能刪他人的；有刪除權限可以；不能跨專案以 ID 操作', function () use (&$cp): void {
    $gNote = (int)ctx()['pdo']->query("SELECT id FROM project_notes WHERE project_id = {$cp} AND content = '批次紀錄 2'")->fetchColumn();
    $eNote = (int)ctx()['pdo']->query("SELECT id FROM project_notes WHERE project_id = {$cp} AND author_id = " . (int)userRow('editor2')['id'] . ' LIMIT 1')->fetchColumn();
    $e = signedIn('editor2');
    $e->get("/projects/{$cp}");
    assert_same(404, $e->post("/projects/{$cp}/notes/{$gNote}/delete", [])->status, 'editor2 沒有刪除權限');
    $other = projectId('公司規章');
    $g = signedIn('g1p1');
    $g->get('/');
    assert_same(404, $g->post("/projects/{$other}/notes/{$eNote}/delete", [])->status, '隨手記不屬於該專案');
    $g->get("/projects/{$cp}");
    $g->post("/projects/{$cp}/notes/{$eNote}/delete", []);
    assert_true(ctx()['pdo']->query("SELECT deleted_at FROM project_notes WHERE id = {$eNote}")->fetchColumn() !== null, '有刪除權限可刪他人的');
    $e->get("/projects/{$cp}");
    $e->post("/projects/{$cp}/notes", ['content' => 'editor2 自己要刪的']);
    $own = (int)ctx()['pdo']->query("SELECT MAX(id) FROM project_notes WHERE project_id = {$cp}")->fetchColumn();
    $e->get("/projects/{$cp}");
    $e->post("/projects/{$cp}/notes/{$own}/delete", []);
    assert_true(ctx()['pdo']->query("SELECT deleted_at FROM project_notes WHERE id = {$own}")->fetchColumn() !== null, '作者可刪自己的');
});

test('撤銷專案權限後，隨手記與版本歷史立即無法存取', function () use (&$cp): void {
    setProject($cp, 'viewer', 0, 0, 0);
    assert_same(404, signedIn('viewer')->get("/api/projects/{$cp}/notes")->status);
    assert_same(404, signedIn('viewer')->get("/projects/{$cp}/history")->status);
});

test('歷史操作紀錄：記錄說明修改與衝突、關鍵字、隨手記操作，但不含說明或隨手記內文', function (): void {
    $pdo = ctx()['pdo'];
    foreach ([['project.description.update', 'success'], ['project.description.update', 'failure'], ['keyword.add', 'success'],
              ['keyword.delete', 'success'], ['note.create', 'success'], ['note.update', 'success'], ['note.delete', 'success'],
              ['note.create', 'denied'], ['note.update', 'denied'], ['project.history.view', 'success']] as [$action, $result]) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE action = ' . $pdo->quote($action) . ' AND result = ' . $pdo->quote($result))->fetchColumn();
        assert_true($n > 0, "缺少紀錄：{$action} / {$result}");
    }
    $dump = implode("\n", array_map(fn(array $r): string => implode('|', $r), $pdo->query('SELECT * FROM activity_logs')->fetchAll(PDO::FETCH_NUM)));
    // 專案名稱屬於紀錄的必要資訊；這裡檢查的是說明與隨手記「內文」
    foreach (['B 尚未儲存的重要文字', 'A 的修改', '我自己的修改', "<script>alert(1)</script>\n第二行", '批次紀錄'] as $secret) {
        assert_not_contains($secret, $dump, '紀錄不得包含內文');
    }
});
