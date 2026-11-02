<?php

declare(strict_types=1);

/**
 * 階段 8 整合測試：歷史操作紀錄查詢、篩選、統計、匯出、保存期限（規格第九節）。
 * 以 SQL 寫入時間已知的紀錄，驗證日期邊界、台灣時區與上午／下午統計。
 */

$logAdmin = null;

function insertLog(string $utc, ?int $userId, string $label, string $category, string $action, string $result = 'success', ?string $detail = null): void
{
    $st = ctx()['pdo']->prepare('INSERT INTO activity_logs (occurred_at, actor_user_id, actor_label, category, action, result, detail) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$utc, $userId, $label, $category, $action, $result, $detail]);
}

function logPage(Browser $b, string $query): string
{
    return $b->get('/admin/logs?' . $query)->body;
}

function statValue(string $html, string $label): string
{
    $q = preg_quote($label, '#');
    return preg_match('#data-stat="' . $q . '">([^<]*)<#u', $html, $m) ? $m[1] : '';
}

test('準備：寫入 2026-10-30（台灣時間）前後的已知紀錄', function () use (&$logAdmin): void {
    $alice = (int)userRow('alice')['id'];
    $bob = (int)userRow('bob')['id'];
    // UTC → 台灣時間（+8）
    insertLog('2026-10-29 15:59:59', $alice, 'alice', 'auth', 'login.success');      // 10-29 23:59:59（前一天）
    insertLog('2026-10-29 16:30:00', $alice, 'alice', 'auth', 'login.success');      // 10-30 00:30 上午
    insertLog('2026-10-30 01:00:00', $alice, 'alice', 'project', 'project.view');    // 10-30 09:00 上午
    insertLog('2026-10-30 06:00:00', $alice, 'alice', 'data', 'note.create');        // 10-30 14:00 下午
    insertLog('2026-10-30 15:59:59', $alice, 'alice', 'auth', 'logout');             // 10-30 23:59:59 下午
    insertLog('2026-10-30 16:00:00', $alice, 'alice', 'auth', 'login.success');      // 10-31 00:00（隔天）
    insertLog('2026-10-30 03:00:00', $bob, 'bob', 'data', 'note.create', 'success', '=HYPERLINK("http://evil.example")'); // 10-30 11:00 上午
    insertLog('2026-10-30 04:00:00', null, '(未知帳號)', 'auth', 'login.failure', 'failure', '帳號不存在');               // 10-30 12:00 下午
    $logAdmin = adminLogin();
    assert_true(true);
});

test('非管理者：紀錄頁與匯出都回 404；未登入導向登入頁', function (): void {
    assert_same(404, signedIn('g1p1')->get('/admin/logs')->status);
    assert_same(404, signedIn('g1p1')->get('/admin/logs/export')->status);
    assert_same('/login', browser()->get('/admin/logs')->location());
});

test('單日篩選：以台灣時間的日期界定，前一天 23:59:59 與隔天 00:00 都不算', function () use (&$logAdmin): void {
    $alice = (int)userRow('alice')['id'];
    $html = logPage($logAdmin, "mode=day&date=2026-10-30&user={$alice}");
    assert_same('4', statValue($html, '操作總數'));
    assert_same('00:30:00', statValue($html, '首次操作'));
    assert_same('23:59:59', statValue($html, '最後操作'));
    assert_same('2', statValue($html, '上午（00:00–11:59）'));
    assert_same('2', statValue($html, '下午（12:00–23:59）'));
    assert_same(4, substr_count($html, 'data-log-row'));
});

test('員工篩選與所有員工：查看所有員工時包含其他人與未知帳號', function () use (&$logAdmin): void {
    $html = logPage($logAdmin, 'mode=day&date=2026-10-30');
    assert_same('6', statValue($html, '操作總數'), 'alice 4 筆＋bob 1 筆＋未知帳號 1 筆');
    assert_same('3', statValue($html, '上午（00:00–11:59）'));
    $unknown = logPage($logAdmin, 'mode=day&date=2026-10-30&user=unknown');
    assert_same('1', statValue($unknown, '操作總數'));
    assert_contains('登入失敗', $unknown);
});

test('日期範圍與組合篩選：範圍＋員工＋類型可同時使用', function () use (&$logAdmin): void {
    $alice = (int)userRow('alice')['id'];
    assert_same('6', statValue(logPage($logAdmin, "mode=range&from=2026-10-29&to=2026-10-31&user={$alice}"), '操作總數'));
    assert_same('4', statValue(logPage($logAdmin, "mode=range&from=2026-10-29&to=2026-10-31&user={$alice}&category=auth"), '操作總數'), '登入、登出共 4 筆');
    assert_same('5', statValue(logPage($logAdmin, "mode=range&from=2026-10-30&to=2026-10-31&user={$alice}"), '操作總數'));
});

test('不合法的輸入不會造成錯誤：日期格式錯誤、起迄顛倒、範圍過長、員工參數非數字', function () use (&$logAdmin): void {
    $html = logPage($logAdmin, 'mode=day&date=2026-13-45');
    assert_same(200, $logAdmin->status);
    assert_contains('日期格式不正確', $html);
    assert_contains('已自動對調', logPage($logAdmin, 'mode=range&from=2026-10-31&to=2026-10-29'));
    assert_contains('最多 366 天', logPage($logAdmin, 'mode=range&from=2020-01-01&to=2026-10-31'));
    logPage($logAdmin, "mode=day&date=2026-10-30&user=1%20OR%201=1&category=x'");
    assert_same(200, $logAdmin->status);
});

test('明細分頁：每頁 50 筆，可往前載入更早的紀錄', function () use (&$logAdmin): void {
    $bob = (int)userRow('bob')['id'];
    for ($i = 0; $i < 60; $i++) {
        insertLog(sprintf('2026-10-28 02:%02d:00', $i), $bob, 'bob', 'project', 'project.view');
    }
    $html = logPage($logAdmin, "mode=day&date=2026-10-28&user={$bob}");
    assert_same('60', statValue($html, '操作總數'));
    assert_same(50, substr_count($html, 'data-log-row'));
    assert_true((bool)preg_match('#href="/admin/logs\?([^"]*before=\d+)"#', $html, $m), '應有「更早的紀錄」連結');
    $older = logPage($logAdmin, html_entity_decode($m[1]));
    assert_same(10, substr_count($older, 'data-log-row'));
});

test('匯出 CSV：套用相同篩選、中文標題、UTF-8 BOM、防止公式注入，並記錄匯出操作', function () use (&$logAdmin): void {
    $logAdmin->get('/admin/logs/export?mode=day&date=2026-10-30');
    assert_same(200, $logAdmin->status);
    assert_contains('text/csv', $logAdmin->header('content-type'));
    assert_contains('attachment', $logAdmin->header('content-disposition'));
    $csv = $logAdmin->body;
    assert_same("\xEF\xBB\xBF", substr($csv, 0, 3));
    $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
    assert_same('時間,員工,類別,操作,結果,相關專案,說明,IP', $lines[0]);
    assert_same(7, count($lines), '標題＋6 筆');
    assert_contains('2026-10-30 00:30:00', $csv, '時間以台灣時區輸出');
    assert_contains("'=HYPERLINK", $csv, '以 = 開頭的儲存格前加上單引號');
    assert_not_contains(',=HYPERLINK', $csv);
    $n = (int)ctx()['pdo']->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'logs.export'")->fetchColumn();
    assert_true($n > 0);
});

test('保存期限：開啟紀錄頁時刪除超過保存天數的紀錄，期限內的保留', function () use (&$logAdmin): void {
    $pdo = ctx()['pdo'];
    insertLog(gmdate('Y-m-d H:i:s', time() - 400 * 86400), null, 'old', 'auth', 'login.failure', 'failure', '過期紀錄');
    insertLog(gmdate('Y-m-d H:i:s', time() - 300 * 86400), null, 'recent', 'auth', 'login.failure', 'failure', '期限內紀錄');
    logPage($logAdmin, 'mode=day&date=2026-10-30');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE detail = '過期紀錄'")->fetchColumn());
    assert_same(1, (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE detail = '期限內紀錄'")->fetchColumn());
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'logs.purge'")->fetchColumn() > 0, '清理本身也留下紀錄');
    setSetting('logs.retention_days', '30');
    logPage($logAdmin, 'mode=day&date=2026-10-30');
    assert_same(0, (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE detail = '期限內紀錄'")->fetchColumn(), '調整保存天數後生效');
    setSetting('logs.retention_days', '365');
});

test('畫面提醒：沒有紀錄不代表沒有工作；紀錄內容以中文顯示並跳脫', function () use (&$logAdmin): void {
    $html = logPage($logAdmin, 'mode=day&date=2026-10-30');
    assert_contains('沒有操作紀錄不代表沒有工作', $html);
    assert_contains('新增隨手記', $html);
    assert_contains('進入專案', $html);
    insertLog('2026-10-30 05:00:00', null, '<script>alert(1)</script>', 'auth', 'login.failure', 'failure', '<img src=x onerror=alert(1)>');
    $html = logPage($logAdmin, 'mode=day&date=2026-10-30');
    assert_not_contains('<script>alert(1)</script>', $html);
    assert_not_contains('<img src=x', $html);
});
