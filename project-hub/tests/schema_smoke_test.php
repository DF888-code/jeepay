<?php
/**
 * Schema 草案驗證測試（階段 1／2 用）
 *
 * 目的：在正式撰寫應用程式之前，先證明 Schema 能支撐規格中最關鍵的 SQL 模式：
 *   1. 雙重授權（全域 × 專案）四種組合的判斷結果
 *   2. 權限撤銷後下一次查詢立即生效（不快取授權結果）
 *   3. 搜尋在 SQL 層就限制範圍，不會回傳未授權專案（包含筆數）
 *   4. 專案說明的樂觀併發控制：條件式 UPDATE 只允許一方成功
 *   5. 救援 Token 單次使用的原子性
 *   6. 關鍵字標準化後的唯一性（資料庫 = 数据库）
 *
 * 用法（會建立並清空指定的測試資料庫，請勿指向正式資料庫）：
 *   PH_TEST_DSN="mysql:host=127.0.0.1;dbname=ph_test;charset=utf8mb4" PH_TEST_USER=ph PH_TEST_PASS=xxx \
 *     php project-hub/tests/schema_smoke_test.php
 *
 * 注意：此處的 *_norm 值以手動填入模擬 OpenCC 標準化結果，標準化函式本身於階段 7 另行測試。
 */

declare(strict_types=1);

$dsn = getenv('PH_TEST_DSN') ?: '';
if ($dsn === '' || !str_contains($dsn, 'test')) {
    fwrite(STDERR, "請設定 PH_TEST_DSN，且資料庫名稱需包含 test（防止誤清正式資料）。\n");
    exit(2);
}
$pdo = new PDO($dsn, getenv('PH_TEST_USER') ?: 'root', getenv('PH_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// ---- 重建 Schema ----
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `{$t}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.mysql.draft.sql'));

$pass = 0;
$fail = 0;
function check(string $name, bool $cond): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  [PASS] ' : '  [FAIL] ') . $name . "\n";
}

$now = gmdate('Y-m-d H:i:s');

// ---- 測試資料 ----
// 使用者：1=管理者, 2..5 = 四種雙重授權組合（針對「編輯」）
$users = [
    1 => ['admin', 1],
    2 => ['g_yes_p_yes', 0],
    3 => ['g_yes_p_no', 0],
    4 => ['g_no_p_yes', 0],
    5 => ['g_no_p_no', 0],
];
$insU = $pdo->prepare('INSERT INTO users (id, username, username_norm, display_name, password_hash, is_super_admin, must_enroll_2fa, created_at, updated_at) VALUES (?,?,?,?,?,?,0,?,?)');
$insG = $pdo->prepare('INSERT INTO user_global_permissions (user_id, can_view_projects, can_edit_projects, updated_at) VALUES (?,?,?,?)');
foreach ($users as $id => [$name, $admin]) {
    $insU->execute([$id, $name, $name, $name, 'x', $admin, $now, $now]);
}
// 全域：2、3 有查看＋編輯；4、5 只有查看
foreach ([2 => 1, 3 => 1, 4 => 0, 5 => 0] as $uid => $edit) {
    $insG->execute([$uid, 1, $edit, $now]);
}

$insP = $pdo->prepare('INSERT INTO projects (id, name, name_norm, description, description_norm, created_by, created_at, updated_at) VALUES (?,?,?,?,?,1,?,?)');
$insP->execute([10, '公司規章', '公司规章', '請假流程', '请假流程', $now, $now]);
$insP->execute([11, '升遷制度', '升迁制度', '資料庫 管理職 晉升條件', '数据库 管理职 晋升条件', $now, $now]);
$insP->execute([12, '網站開發', '网站开发', '前端與資料庫', '前端与数据库', $now, $now]);
$pdo->exec("INSERT INTO projects (id, name, name_norm, description, description_norm, created_by, created_at, updated_at, deleted_at) VALUES (13, '已刪除', '已删除', '数据库', '数据库', 1, '$now', '$now', '$now')");

$insPP = $pdo->prepare('INSERT INTO project_permissions (project_id, user_id, can_view, can_edit, updated_at) VALUES (?,?,?,?,?)');
// 專案 12：2、4 有查看＋編輯；3、5 只有查看
foreach ([2 => 1, 3 => 0, 4 => 1, 5 => 0] as $uid => $edit) {
    $insPP->execute([12, $uid, 1, $edit, $now]);
}
// 專案 10：2 可查看；專案 11（升遷制度）：2 無權限
$insPP->execute([10, 2, 1, 0, $now]);
$insPP->execute([13, 2, 1, 0, $now]);

$pdo->prepare('INSERT INTO project_notes (project_id, author_id, content, content_norm, created_at) VALUES (?,?,?,?,?)')
    ->execute([11, 1, '晉升需主管推薦（資料庫負責人）', '晋升需主管推荐（数据库负责人）', $now]);

/**
 * 雙重授權判斷：與日後應用程式 Authorizer 使用同一個 SQL 形狀。
 * 每次呼叫都直接查資料庫，不快取，確保撤銷立即生效。
 */
function can(PDO $pdo, int $uid, int $projectId, string $action): bool
{
    $g = ['view' => 'can_view_projects', 'edit' => 'can_edit_projects', 'delete' => 'can_delete_projects'][$action];
    $p = ['view' => 'can_view', 'edit' => 'can_edit', 'delete' => 'can_delete'][$action];
    $sql = "SELECT 1
              FROM users u
              JOIN projects pr ON pr.id = :pid AND pr.deleted_at IS NULL
         LEFT JOIN user_global_permissions g ON g.user_id = u.id
         LEFT JOIN project_permissions pp ON pp.user_id = u.id AND pp.project_id = pr.id
             WHERE u.id = :uid AND u.status = 'active'
               AND (u.is_super_admin = 1
                    OR (g.can_view_projects = 1 AND pp.can_view = 1 AND g.{$g} = 1 AND pp.{$p} = 1))";
    $st = $pdo->prepare($sql);
    $st->execute(['pid' => $projectId, 'uid' => $uid]);
    return (bool)$st->fetchColumn();
}

echo "1. 雙重授權（編輯專案 12）\n";
check('全域有 + 專案有 → 允許', can($pdo, 2, 12, 'edit'));
check('全域有 + 專案無 → 拒絕', !can($pdo, 3, 12, 'edit'));
check('全域無 + 專案有 → 拒絕', !can($pdo, 4, 12, 'edit'));
check('全域無 + 專案無 → 拒絕', !can($pdo, 5, 12, 'edit'));
check('最高權限管理者 → 允許', can($pdo, 1, 12, 'edit'));
check('直接改專案 ID（11 無授權列）→ 拒絕', !can($pdo, 2, 11, 'view'));
check('不存在的專案 ID → 拒絕', !can($pdo, 2, 999, 'view'));
check('已刪除專案 → 拒絕', !can($pdo, 2, 13, 'view'));

echo "2. 撤銷後立即生效\n";
$pdo->prepare('UPDATE project_permissions SET can_edit = 0 WHERE project_id = 12 AND user_id = 2')->execute();
check('撤銷專案編輯後再次呼叫 → 拒絕', !can($pdo, 2, 12, 'edit'));
$pdo->prepare('UPDATE project_permissions SET can_edit = 1 WHERE project_id = 12 AND user_id = 2')->execute();
$pdo->prepare('UPDATE user_global_permissions SET can_view_projects = 0 WHERE user_id = 2')->execute();
check('撤銷全域查看後 → 專案 10 拒絕', !can($pdo, 2, 10, 'view'));
$pdo->prepare('UPDATE user_global_permissions SET can_view_projects = 1 WHERE user_id = 2')->execute();
$pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = 2")->execute();
check('停用帳號後 → 拒絕', !can($pdo, 2, 12, 'view'));
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = 2")->execute();

echo "3. 搜尋在 SQL 層限制範圍\n";
/** 權限條件放在 WHERE 中，先限縮可見專案再比對內容；回傳的筆數也只來自可見範圍。 */
function search(PDO $pdo, int $uid, string $qNorm): array
{
    // 使用 ! 作為跳脫字元：不受 MySQL NO_BACKSLASH_ESCAPES 影響，PostgreSQL 也相容
    $like = '%' . strtr($qNorm, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $sql = "SELECT pr.id
              FROM projects pr
              JOIN users u ON u.id = :uid AND u.status = 'active'
         LEFT JOIN user_global_permissions g ON g.user_id = u.id
         LEFT JOIN project_permissions pp ON pp.project_id = pr.id AND pp.user_id = u.id
             WHERE pr.deleted_at IS NULL
               AND (u.is_super_admin = 1 OR (g.can_view_projects = 1 AND pp.can_view = 1))
               AND (pr.name_norm LIKE :q1 ESCAPE '!' OR pr.description_norm LIKE :q2 ESCAPE '!'
                    OR EXISTS (SELECT 1 FROM project_keywords k WHERE k.project_id = pr.id AND k.keyword_norm LIKE :q3 ESCAPE '!')
                    OR EXISTS (SELECT 1 FROM project_notes n WHERE n.project_id = pr.id AND n.deleted_at IS NULL AND n.content_norm LIKE :q4 ESCAPE '!'))
          ORDER BY pr.id";
    $st = $pdo->prepare($sql);
    $st->execute(['uid' => $uid, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
check('員工 2 搜尋「数据库」只得到專案 12（不含升遷制度 11、已刪除 13）', search($pdo, 2, '数据库') === [12]);
check('員工 2 搜尋「晋升」→ 0 筆（升遷制度的說明與隨手記都不外洩）', search($pdo, 2, '晋升') === []);
check('管理者搜尋「数据库」→ 11、12', search($pdo, 1, '数据库') === [11, 12]);
check('無全域查看的員工 5 → 0 筆', ($pdo->exec('UPDATE user_global_permissions SET can_view_projects = 0 WHERE user_id = 5') !== false) && search($pdo, 5, '数据库') === []);
check('LIKE 萬用字元被跳脫（搜尋 % 不會全部命中）', search($pdo, 1, '%') === []);

echo "4. 專案說明樂觀併發控制\n";
$upd = $pdo->prepare('UPDATE projects SET description = ?, description_norm = ?, description_version = description_version + 1, updated_by = ?, updated_at = ?
                       WHERE id = ? AND deleted_at IS NULL AND description_version = ?');
$upd->execute(['A 的版本', 'a 的版本', 2, $now, 12, 1]);
$a = $upd->rowCount();
$upd->execute(['B 的版本', 'b 的版本', 4, $now, 12, 1]); // B 仍持有舊版本號 1
$b = $upd->rowCount();
$cur = $pdo->query('SELECT description, description_version FROM projects WHERE id = 12')->fetch();
check('先送出者成功（影響 1 列）', $a === 1);
check('持舊版本號者失敗（影響 0 列），不覆蓋', $b === 0 && $cur['description'] === 'A 的版本');
check('版本號遞增為 2', (int)$cur['description_version'] === 2);
$upd->execute(['A 的版本', 'a 的版本', 2, $now, 12, 2]);
check('內容相同但版本號正確仍算成功（版本遞增保證 rowCount=1）', $upd->rowCount() === 1);

echo "5. 救援 Token 單次使用\n";
$raw = random_bytes(32);
$hash = hash('sha256', $raw, true);
$pdo->prepare('INSERT INTO admin_recovery_tokens (user_id, purpose, token_hash, created_at, expires_at) VALUES (1, ?, ?, ?, ?)')
    ->execute(['reset_password', $hash, $now, gmdate('Y-m-d H:i:s', time() + 1800)]);
$use = $pdo->prepare('UPDATE admin_recovery_tokens SET used_at = ? WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > ?');
$use->execute([$now, $hash, 'reset_2fa', $now]);
check('用途不符 → 失敗', $use->rowCount() === 0);
$use->execute([$now, $hash, 'reset_password', $now]);
check('第一次使用 → 成功', $use->rowCount() === 1);
$use->execute([$now, $hash, 'reset_password', $now]);
check('第二次使用 → 失敗', $use->rowCount() === 0);
$stored = $pdo->query('SELECT token_hash FROM admin_recovery_tokens')->fetchColumn();
check('資料庫只存雜湊，不含原始 Token', $stored === $hash && $stored !== $raw);

echo "6. 關鍵字標準化唯一\n";
$insK = $pdo->prepare('INSERT INTO project_keywords (project_id, keyword, keyword_norm, created_by, created_at) VALUES (12, ?, ?, 1, ?)');
$insK->execute(['資料庫', '数据库', $now]);
try {
    $insK->execute(['数据库', '数据库', $now]);
    $dup = false;
} catch (PDOException $e) {
    $dup = $e->getCode() === '23000';
}
check('「数据库」與既有「資料庫」視為重複', $dup);
check('原文保留繁體「資料庫」', $pdo->query('SELECT keyword FROM project_keywords')->fetchColumn() === '資料庫');

echo "\n結果：{$pass} 通過，{$fail} 失敗\n";
exit($fail > 0 ? 1 : 0);
