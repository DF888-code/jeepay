<?php

declare(strict_types=1);

/**
 * 階段 5 整合測試：專案管理與雙重授權（規格第四、五、十四節的權限測試清單）。
 * 接續 AuthFlowTest、AdminTest 的資料。
 */

/** 直接設定全域權限（介面已在 AdminTest 驗證）。 */
function setGlobal(string $username, int $view, int $edit, int $create, int $delete): void
{
    $id = (int)userRow($username)['id'];
    $st = ctx()['pdo']->prepare(
        'INSERT INTO user_global_permissions (user_id, can_view_projects, can_edit_projects, can_create_projects, can_delete_projects, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE can_view_projects = VALUES(can_view_projects), can_edit_projects = VALUES(can_edit_projects),
                                 can_create_projects = VALUES(can_create_projects), can_delete_projects = VALUES(can_delete_projects),
                                 row_version = row_version + 1'
    );
    $st->execute([$id, $view, $edit, $create, $delete]);
}

function setProject(int $projectId, string $username, int $view, int $edit, int $delete): void
{
    $id = (int)userRow($username)['id'];
    $st = ctx()['pdo']->prepare(
        'REPLACE INTO project_permissions (project_id, user_id, can_view, can_edit, can_delete, updated_at) VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$projectId, $id, $view, $edit, $delete]);
}

function projectId(string $name): int
{
    $st = ctx()['pdo']->prepare('SELECT id FROM projects WHERE name = ?');
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

/** 依帳號快取已登入的瀏覽器（每個帳號一個獨立瀏覽器） */
function signedIn(string $username): Browser
{
    static $browsers = [];
    if (!isset($browsers[$username])) {
        $b = browser();
        loginFull($b, $username, 'Matrix-Pass-001');
        $browsers[$username] = $b;
    }
    return $browsers[$username];
}

$adminB = null;

test('準備：建立四種雙重授權組合的員工，並關閉單一裝置登入以便多個瀏覽器同時測試', function () use (&$adminB): void {
    setSetting('security.login_ip_max_failures', '200');
    setSetting('security.single_session', '0');
    ctx()['pdo']->exec('DELETE FROM login_attempts');
    foreach (['g1p1', 'g1p0', 'g0p1', 'g0p0', 'creator'] as $u) {
        seedUser($u, 'Matrix-Pass-001');
    }
    $adminB = adminLogin();
});

test('最高權限管理者建立專案：寫入說明版本 1 的歷史，並記錄 project.create', function () use (&$adminB): void {
    foreach (['公司規章' => '請假與加班規定', '升遷制度' => '晉升條件與評核流程（機密）', '網站開發' => '前端與資料庫'] as $name => $desc) {
        $adminB->submit('/projects/new', '/projects', ['name' => $name, 'description' => $desc]);
        assert_true((bool)preg_match('#^/projects/\d+$#', $adminB->location()), '應導向專案頁');
    }
    $id = projectId('升遷制度');
    $hist = ctx()['pdo']->query("SELECT version_before, version_after, content_after FROM project_description_history WHERE project_id = {$id}")->fetchAll(PDO::FETCH_ASSOC);
    assert_same([['version_before' => null, 'version_after' => 1, 'content_after' => '晉升條件與評核流程（機密）']], $hist);
    assert_same(0, (int)ctx()['pdo']->query("SELECT COUNT(*) FROM project_permissions WHERE project_id = {$id}")->fetchColumn(), '管理者建立的專案預設沒有任何人被授權');
});

test('設定權限：網站開發專案以四種組合授權（查看＋編輯＋刪除）', function (): void {
    $web = projectId('網站開發');
    setGlobal('g1p1', 1, 1, 0, 1); setProject($web, 'g1p1', 1, 1, 1);
    setGlobal('g1p0', 1, 1, 0, 1);
    setGlobal('g0p1', 0, 0, 0, 0); setProject($web, 'g0p1', 1, 1, 1);
    setGlobal('g0p0', 0, 0, 0, 0);
    setProject(projectId('公司規章'), 'g1p1', 1, 0, 0);
    assert_true(true);
});

test('雙重授權（查看）：只有「全域有＋專案有」可開啟專案頁與 API，其餘一律 404', function (): void {
    $web = projectId('網站開發');
    $expect = ['g1p1' => 200, 'g1p0' => 404, 'g0p1' => 404, 'g0p0' => 404];
    foreach ($expect as $u => $status) {
        assert_same($status, signedIn($u)->get("/projects/{$web}")->status, "{$u} 頁面");
        assert_same($status, signedIn($u)->get("/api/projects/{$web}")->status, "{$u} API");
    }
    $denied = signedIn('g1p0')->get("/projects/{$web}")->body;
    assert_not_contains('網站開發', $denied, '拒絕頁面不得洩漏專案名稱');
    assert_not_contains('前端與資料庫', signedIn('g0p1')->get("/api/projects/{$web}")->body, 'API 不得洩漏說明');
});

test('雙重授權（編輯）：改名只有兩層都有編輯權限才成功', function (): void {
    $web = projectId('網站開發');
    foreach (['g1p0', 'g0p1', 'g0p0'] as $u) {
        $b = signedIn($u);
        $b->get('/');
        assert_same(404, $b->post("/projects/{$web}/rename", ['name' => "被 {$u} 改名"])->status, $u);
    }
    assert_same('網站開發', ctx()['pdo']->query("SELECT name FROM projects WHERE id = {$web}")->fetchColumn());
    $ok = signedIn('g1p1');
    $ok->get("/projects/{$web}");
    $ok->post("/projects/{$web}/rename", ['name' => '網站開發（新版）']);
    assert_same("/projects/{$web}", $ok->location());
    assert_same('網站開發（新版）', ctx()['pdo']->query("SELECT name FROM projects WHERE id = {$web}")->fetchColumn());
});

test('雙重授權：只有專案編輯權限、沒有全域編輯時仍不能改名（專案權限不會授予全域權限）', function (): void {
    $rules = projectId('公司規章');
    setProject($rules, 'g1p0', 1, 1, 0);
    setGlobal('g1p0', 1, 0, 0, 0);
    $b = signedIn('g1p0');
    assert_same(200, $b->get("/projects/{$rules}")->status, '兩層都有查看可以看');
    assert_same(404, $b->post("/projects/{$rules}/rename", ['name' => 'x'])->status, '缺全域編輯不可改名');
    assert_not_contains('改名', $b->get("/projects/{$rules}")->body, '畫面不顯示改名按鈕');
    setGlobal('g1p0', 1, 1, 0, 1);
    setProject($rules, 'g1p0', 0, 0, 0);
});

test('專案列表與列表 API：只回傳可查看的專案，筆數也只計算可見專案', function (): void {
    $list = json_decode(signedIn('g1p1')->get('/api/projects')->body, true);
    $names = array_column($list['items'], 'name');
    sort($names);
    assert_same(['公司規章', '網站開發（新版）'], $names);
    assert_same(2, $list['total']);
    $home = signedIn('g1p1')->get('/')->body;
    assert_not_contains('升遷制度', $home, '未授權專案不得出現在列表');
    foreach (['g1p0', 'g0p1', 'g0p0'] as $u) {
        $r = json_decode(signedIn($u)->get('/api/projects')->body, true);
        assert_same(0, $r['total'], "{$u} 不應看到任何專案");
    }
});

test('直接修改網址中的專案 ID：未授權、不存在的專案都回相同的 404', function (): void {
    $secret = projectId('升遷制度');
    $a = signedIn('g1p1')->get("/projects/{$secret}");
    $b = signedIn('g1p1')->get('/projects/999999');
    assert_same(404, $a->status);
    assert_same(404, $b->status);
    assert_same($b->body, $a->body, '未授權與不存在的回應內容必須相同');
    assert_same(404, signedIn('g1p1')->get('/projects/0')->status);
    assert_same(404, signedIn('g1p1')->get('/projects/-1')->status);
    assert_same(404, signedIn('g1p1')->get('/projects/abc')->status);
});

test('撤銷權限後立即生效：撤銷專案權限、撤銷全域權限、停用帳號，下一個請求就被拒絕', function (): void {
    $web = projectId('網站開發（新版）');
    assert_same(200, signedIn('g1p1')->get("/api/projects/{$web}")->status);
    setProject($web, 'g1p1', 0, 0, 0);
    assert_same(404, signedIn('g1p1')->get("/api/projects/{$web}")->status, '撤銷專案權限');
    setProject($web, 'g1p1', 1, 1, 1);
    setGlobal('g1p1', 0, 1, 0, 1);
    assert_same(404, signedIn('g1p1')->get("/api/projects/{$web}")->status, '撤銷全域查看');
    setGlobal('g1p1', 1, 1, 0, 1);
    assert_same(200, signedIn('g1p1')->get("/api/projects/{$web}")->status, '恢復後可再查看');
});

test('建立專案：沒有全域建立權限時頁面與送出都被拒絕，且不建立任何資料', function (): void {
    $b = signedIn('g1p1');
    assert_same(404, $b->get('/projects/new')->status);
    $b->get('/');
    assert_same(404, $b->post('/projects', ['name' => '偷建的專案', 'description' => 'x'])->status);
    assert_same(0, projectId('偷建的專案'));
    assert_not_contains('建立專案', $b->get('/')->body, '沒有權限時不顯示建立按鈕');
});

test('一般員工建立專案：需全域建立權限，建立後自動取得查看＋編輯（不含刪除）', function (): void {
    setGlobal('creator', 1, 1, 1, 1);
    $b = signedIn('creator');
    $b->submit('/projects/new', '/projects', ['name' => '員工自建專案', 'description' => '測試']);
    $id = projectId('員工自建專案');
    assert_true($id > 0);
    assert_same(200, $b->get("/projects/{$id}")->status);
    $perm = ctx()['pdo']->query('SELECT can_view, can_edit, can_delete FROM project_permissions WHERE project_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    assert_same(['can_view' => 1, 'can_edit' => 1, 'can_delete' => 0], array_map('intval', $perm));
    assert_not_contains('data-bs-target="#delete-modal"', $b->body, '沒有專案刪除權限不顯示刪除按鈕');
});

test('建立專案：名稱與說明的驗證', function (): void {
    $b = signedIn('creator');
    $b->submit('/projects/new', '/projects', ['name' => '', 'description' => 'x']);
    assert_same(422, $b->status);
    $b->submit('/projects/new', '/projects', ['name' => str_repeat('長', 201), 'description' => 'x']);
    assert_same(422, $b->status);
    $b->submit('/projects/new', '/projects', ['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);
    $id = projectId('<script>alert(1)</script>');
    $page = $b->get("/projects/{$id}")->body;
    assert_not_contains('<script>alert(1)</script>', $page, '名稱必須跳脫');
    assert_not_contains('<img src=x', $page, '說明必須跳脫');
    assert_contains('&lt;script&gt;', $page);
});

test('雙重授權（刪除）：只有兩層都有刪除權限才成功；需輸入專案名稱確認；刪除後所有人（含列表）都看不到', function (): void {
    $web = projectId('網站開發（新版）');
    foreach (['g1p0', 'g0p1', 'g0p0'] as $u) {
        $b = signedIn($u);
        $b->get('/');
        assert_same(404, $b->post("/projects/{$web}/delete", ['confirm_name' => '網站開發（新版）'])->status, $u);
    }
    $ok = signedIn('g1p1');
    $ok->get("/projects/{$web}");
    $ok->post("/projects/{$web}/delete", ['confirm_name' => '打錯名稱']);
    assert_contains('確認名稱不符', $ok->get("/projects/{$web}")->body);
    $ok->post("/projects/{$web}/delete", ['confirm_name' => '網站開發（新版）']);
    assert_same('/', $ok->location());
    assert_same(404, $ok->get("/projects/{$web}")->status);
    assert_not_contains('網站開發（新版）', $ok->get('/')->body);
    assert_true(ctx()['pdo']->query("SELECT deleted_at FROM projects WHERE id = {$web}")->fetchColumn() !== null, '應為軟刪除，資料保留');
});

test('管理後台專案管理：非管理者 404；管理者可看到回收區並在再驗證後還原', function () use (&$adminB): void {
    $web = projectId('網站開發（新版）');
    assert_same(404, signedIn('g1p1')->get('/admin/projects')->status);
    $adminB->get('/admin/projects');
    assert_contains('回收區', $adminB->body);
    elevate($adminB, '/admin/projects');
    $adminB->get('/admin/projects');
    $adminB->post("/admin/projects/{$web}/restore", []);
    assert_contains('已還原專案', $adminB->get('/admin/projects')->body);
    assert_same(200, signedIn('g1p1')->get("/projects/{$web}")->status, '還原後原本的授權恢復');
});

test('專案授權名單：每一列只更新該員工在此專案的權限，並顯示雙重授權的實際效果', function () use (&$adminB): void {
    $secret = projectId('升遷制度');
    $adminB->get("/admin/projects/{$secret}/permissions");
    assert_same(200, $adminB->status);
    assert_contains('儲存「g1p1」', $adminB->body);
    $g1p0 = (int)userRow('g1p0')['id'];
    $before = ctx()['pdo']->query("SELECT COUNT(*) FROM project_permissions WHERE project_id = {$secret}")->fetchColumn();
    $adminB->post("/admin/projects/{$secret}/permissions/{$g1p0}", ['can_edit' => '1']);
    assert_contains('已自動加上「查看」', $adminB->get("/admin/projects/{$secret}/permissions")->body);
    $rows = ctx()['pdo']->query("SELECT user_id, can_view, can_edit, can_delete FROM project_permissions WHERE project_id = {$secret}")->fetchAll(PDO::FETCH_ASSOC);
    assert_same(1, count($rows), '只新增這一位員工的授權');
    assert_same([$g1p0, 1, 1, 0], array_map('intval', array_values($rows[0])));
    assert_same(0, (int)$before);
    assert_same(200, signedIn('g1p0')->get("/projects/{$secret}")->status, '授權後可查看');

    $adminB->post("/admin/projects/{$secret}/permissions/{$g1p0}", []);
    assert_same(0, (int)ctx()['pdo']->query("SELECT COUNT(*) FROM project_permissions WHERE project_id = {$secret}")->fetchColumn(), '全部取消時移除資料列');
    assert_same(404, signedIn('g1p0')->get("/projects/{$secret}")->status, '撤銷後立即失效');
});

test('專案授權名單：非管理者無法透過網址或 API 修改；管理者 ID 與不存在的專案 404', function () use (&$adminB): void {
    $secret = projectId('升遷制度');
    $self = (int)userRow('g1p1')['id'];
    $b = signedIn('g1p1');
    $b->get('/');
    assert_same(404, $b->post("/admin/projects/{$secret}/permissions/{$self}", ['can_view' => '1'])->status);
    assert_same(404, signedIn('g1p1')->get("/projects/{$secret}")->status, '自行授權不可成功');
    $adminId = (int)userRow(ADMIN_USER)['id'];
    $adminB->get("/admin/projects/{$secret}/permissions");
    assert_same(404, $adminB->post("/admin/projects/{$secret}/permissions/{$adminId}", ['can_view' => '1'])->status);
    $adminB->get("/admin/projects/{$secret}/permissions");
    assert_same(404, $adminB->post("/admin/projects/999999/permissions/{$self}", ['can_view' => '1'])->status);
    assert_same(404, $adminB->get('/admin/projects/999999/permissions')->status);
});

test('歷史操作紀錄：專案進入、拒絕、建立、改名、刪除、還原、授權變更都有記錄', function (): void {
    $pdo = ctx()['pdo'];
    foreach ([['project.view', 'success'], ['project.view', 'denied'], ['project.create', 'success'], ['project.rename', 'success'],
              ['project.rename', 'denied'], ['project.delete', 'success'], ['project.delete', 'denied'], ['project.create', 'denied'],
              ['project.restore', 'success'], ['admin.project_permission.update', 'success']] as [$action, $result]) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM activity_logs WHERE action = ' . $pdo->quote($action) . ' AND result = ' . $pdo->quote($result))->fetchColumn();
        assert_true($n > 0, "缺少紀錄：{$action} / {$result}");
    }
    $withProject = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'project.view' AND result = 'success' AND project_id IS NOT NULL AND project_label IS NOT NULL")->fetchColumn();
    assert_true($withProject > 0, '進入專案的紀錄應包含專案');
});
