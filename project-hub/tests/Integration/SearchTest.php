<?php

declare(strict_types=1);

/**
 * 階段 7 整合測試：全站搜尋與簡繁轉換（規格第八節）。
 */

$sp = ['tw' => 0, 'cn' => 0, 'secret' => 0];

function searchApi(string $user, string $q): array
{
    static $admin = null;
    if ($user === ADMIN_USER) {
        $admin ??= adminLogin();
        $b = $admin;
    } else {
        $b = signedIn($user);
    }
    $b->get('/api/search?q=' . rawurlencode($q));
    return ['status' => $b->status, 'json' => json_decode($b->body, true) ?? [], 'body' => $b->body];
}

/** 只比對本檔建立的專案（先前測試的專案也可能含有相同文字） */
function names(array $json): array
{
    $n = array_values(array_intersect(array_column($json['items'] ?? [], 'name'), ['資料庫設計規範', '数据库运维', '機密：晉升名單']));
    sort($n);
    return $n;
}

test('準備：繁體專案、簡體專案、未授權的機密專案', function () use (&$sp): void {
    $admin = adminLogin();
    $admin->submit('/projects/new', '/projects', ['name' => '資料庫設計規範', 'description' => "使用資料庫與軟體開發的規範\n第二段"]);
    $sp['tw'] = projectId('資料庫設計規範');
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/keywords", ['keyword' => '記憶體']);
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/notes", ['content' => '資料庫連線錯誤的解法：檢查連線字串']);
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/notes", ['content' => '已刪除的筆記 ＸＹＺ特殊字']);
    $deleted = (int)ctx()['pdo']->query("SELECT MAX(id) FROM project_notes WHERE project_id = {$sp['tw']}")->fetchColumn();
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/notes/{$deleted}/delete", []);

    $admin->submit('/projects/new', '/projects', ['name' => '数据库运维', 'description' => '服务器与网络维护']);
    $sp['cn'] = projectId('数据库运维');
    $admin->submit('/projects/new', '/projects', ['name' => '機密：晉升名單', 'description' => '資料庫帳號與晉升條件']);
    $sp['secret'] = projectId('機密：晉升名單');
    $admin->get("/projects/{$sp['secret']}");
    $admin->post("/projects/{$sp['secret']}/notes", ['content' => '資料庫密碼 hunter2']);

    setProject($sp['tw'], 'g1p1', 1, 0, 0);
    setProject($sp['cn'], 'g1p1', 1, 0, 0);
    setGlobal('g1p1', 1, 1, 0, 1);
    assert_true($sp['tw'] > 0 && $sp['cn'] > 0 && $sp['secret'] > 0);
});

test('簡繁與兩岸用語互搜：「資料庫」找到「数据库」，「数据库」也找到「資料庫」', function (): void {
    assert_same(['数据库运维', '資料庫設計規範'], names(searchApi('g1p1', '資料庫')['json']));
    assert_same(['数据库运维', '資料庫設計規範'], names(searchApi('g1p1', '数据库')['json']));
    assert_same(['資料庫設計規範'], names(searchApi('g1p1', '软件开发')['json']), '简体「软件开发」找到繁體「軟體開發」');
    assert_same(['数据库运维'], names(searchApi('g1p1', '伺服器')['json']), '「伺服器」找到「服务器」');
    assert_same(['数据库运维'], names(searchApi('g1p1', '網路')['json']), '「網路」找到「网络」');
    assert_same(['資料庫設計規範'], names(searchApi('g1p1', '内存')['json']), '「内存」找到關鍵字「記憶體」');
});

test('搜尋範圍：名稱、說明、關鍵字、隨手記都會找到；同一專案只出現一次並提示符合的隨手記', function () use (&$sp): void {
    $r = searchApi('g1p1', '資料庫')['json'];
    $tw = array_values(array_filter($r['items'], fn(array $i): bool => $i['id'] === $sp['tw']))[0];
    assert_same(true, $tw['matched']['name']);
    assert_same(true, $tw['matched']['description']);
    assert_same(1, $tw['matched']['notes']);
    assert_same(1, count(array_filter($r['items'], fn(array $i): bool => $i['id'] === $sp['tw'])), '同一專案只出現一次');
    $page = signedIn('g1p1')->get('/search?q=' . rawurlencode('連線錯誤'))->body;
    assert_contains('隨手記 1 則', $page);
    assert_contains('<mark>連線錯誤</mark>', $page, '標示符合的文字');
    assert_contains("/projects/{$sp['tw']}#note-", $page, '可直接前往該則隨手記');
});

test('搜尋權限：未授權專案的名稱、說明、關鍵字、隨手記、數量、摘要都不得出現', function () use (&$sp): void {
    foreach (['晉升', '机密', 'hunter2', '名單'] as $q) {
        $r = searchApi('g1p1', $q);
        assert_same(0, $r['json']['total'], "搜尋「{$q}」的筆數");
        assert_same([], $r['json']['items']);
        $html = signedIn('g1p1')->get('/search?q=' . rawurlencode($q))->body;
        // 結果頁會回顯使用者自己輸入的搜尋文字，因此檢查的是未授權專案「其他」的內容
        foreach (array_diff(['機密', '晉升名單', '資料庫密碼', '資料庫帳號', 'hunter2'], [$q]) as $leak) {
            assert_not_contains($leak, $html, "「{$q}」的結果頁不得洩漏「{$leak}」");
        }
        assert_contains('找到 <strong>0</strong> 個專案', $html);
    }
    $r = searchApi('g1p1', '資料庫');
    assert_same(count($r['json']['items']), $r['json']['total'], '筆數只計算可查看的專案');
    assert_true(!in_array($sp['secret'], array_column($r['json']['items'], 'id'), true), '結果不含機密專案');
    assert_not_contains('hunter2', $r['body']);
    assert_not_contains('機密', $r['body']);
    assert_same(['数据库运维', '機密：晉升名單', '資料庫設計規範'], names(searchApi(ADMIN_USER, '資料庫')['json']), '最高權限管理者可搜尋全部');
});

test('沒有任何權限的使用者搜尋不到任何專案；撤銷權限後立即從結果消失', function () use (&$sp): void {
    assert_same(0, searchApi('g0p0', '資料庫')['json']['total']);
    assert_same(0, searchApi('g0p1', '資料庫')['json']['total'], '只有專案權限、沒有全域查看');
    setProject($sp['cn'], 'g1p1', 0, 0, 0);
    assert_same(['資料庫設計規範'], names(searchApi('g1p1', '資料庫')['json']));
    setProject($sp['cn'], 'g1p1', 1, 0, 0);
});

test('已刪除的隨手記與已刪除的專案不會被搜尋到；全形英數可用半形搜尋', function () use (&$sp): void {
    assert_same(0, searchApi('g1p1', 'xyz')['json']['total'], '已刪除的隨手記');
    assert_same(0, searchApi(ADMIN_USER, 'ＸＹＺ')['json']['total']);
    ctx()['pdo']->exec("UPDATE projects SET deleted_at = NOW() WHERE id = {$sp['cn']}");
    assert_same(['資料庫設計規範'], names(searchApi('g1p1', '数据库')['json']));
    ctx()['pdo']->exec("UPDATE projects SET deleted_at = NULL WHERE id = {$sp['cn']}");
});

test('特殊字元：%、_ 等 SQL 萬用字元只當一般文字比對', function (): void {
    assert_same(0, searchApi('g1p1', '%')['json']['total']);
    assert_same(0, searchApi('g1p1', '_')['json']['total']);
    assert_same(0, searchApi('g1p1', "' OR 1=1 -- ")['json']['total']);
});

test('原文不受搜尋標準化影響', function () use (&$sp): void {
    $desc = ctx()['pdo']->query("SELECT description FROM projects WHERE id = {$sp['tw']}")->fetchColumn();
    assert_same("使用資料庫與軟體開發的規範\n第二段", $desc);
    assert_contains('資料庫設計規範', signedIn('g1p1')->get("/projects/{$sp['tw']}")->body);
});

test('關鍵字：「資料庫」與「数据库」視為相同，不重複建立', function () use (&$sp): void {
    $admin = adminLogin();
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/keywords", ['keyword' => '資料庫']);
    $admin->get("/projects/{$sp['tw']}");
    $admin->post("/projects/{$sp['tw']}/keywords", ['keyword' => '数据库']);
    assert_contains('已有相同的關鍵字「資料庫」', $admin->get("/projects/{$sp['tw']}")->body);
    $admin->post("/projects/{$sp['tw']}/keywords", ['keyword' => '内存']);
    assert_contains('已有相同的關鍵字「記憶體」', $admin->get("/projects/{$sp['tw']}")->body);
});

test('輸入檢查：未登入導向登入；過長的搜尋字串被拒絕', function (): void {
    assert_same('/login', browser()->get('/search?q=a')->location());
    assert_same(401, browser()->get('/api/search?q=a')->status);
    assert_same(422, searchApi('g1p1', str_repeat('長', 101))['status']);
    assert_contains('不可超過', signedIn('g1p1')->get('/search?q=' . rawurlencode(str_repeat('長', 101)))->body);
});

test('歷史操作紀錄：記錄搜尋時間、類型與結果數量，預設不保存搜尋原文（決議 #5）', function (): void {
    $pdo = ctx()['pdo'];
    $rows = $pdo->query("SELECT detail FROM activity_logs WHERE action = 'search.query'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($rows) > 0);
    assert_contains('結果：', $rows[0]);
    foreach ($rows as $d) {
        assert_not_contains('hunter2', (string)$d);
        assert_not_contains('晉升', (string)$d);
    }
});

test('重建搜尋索引：舊規則的資料在重建後可被簡繁互搜找到，且原文不變', function () use (&$sp): void {
    $pdo = ctx()['pdo'];
    // 模擬升級前（階段 6）的資料：只有 NFKC＋小寫，沒有繁轉簡
    $pdo->exec("UPDATE projects SET name_norm = name, description_norm = description WHERE id = {$sp['tw']}");
    $pdo->exec("UPDATE project_notes SET content_norm = content WHERE project_id = {$sp['tw']}");
    $pdo->exec("UPDATE project_keywords SET keyword_norm = keyword WHERE project_id = {$sp['tw']}");
    $pdo->exec("UPDATE system_settings SET value_json = '{\"version\":1,\"table\":null,\"last_id\":0,\"conflicts\":[]}' WHERE setting_key = 'internal.search_index'");
    assert_same(['数据库运维'], names(searchApi('g1p1', '数据库')['json']), '重建前找不到繁體專案');

    $admin = adminLogin();
    $page = $admin->get('/admin/projects')->body;
    assert_contains('搜尋索引需要重建', $page);
    $admin->post('/admin/search-index/rebuild', []);
    assert_contains('搜尋索引已重建完成', $admin->get('/admin/projects')->body);
    assert_not_contains('搜尋索引需要重建', $admin->body);
    assert_same(['数据库运维', '資料庫設計規範'], names(searchApi('g1p1', '数据库')['json']), '重建後可互搜');
    assert_same('資料庫設計規範', $pdo->query("SELECT name FROM projects WHERE id = {$sp['tw']}")->fetchColumn(), '原文不變');
    $n = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'search.reindex'")->fetchColumn();
    assert_true($n > 0);
    assert_same(404, signedIn('g1p1')->get('/')->post('/admin/search-index/rebuild', [])->status, '非管理者不可觸發重建');
});
