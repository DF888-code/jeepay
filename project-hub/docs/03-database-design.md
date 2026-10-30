# 03 資料庫設計

> 完整 DDL：[`database/schema.mysql.draft.sql`](../database/schema.mysql.draft.sql)（草案，尚未定案）
> 驗證測試：[`tests/schema_smoke_test.php`](../tests/schema_smoke_test.php)

## 1. 資料表總覽

```
users ─┬─ user_global_permissions        第一層：全域權限（1:1）
       ├─ project_permissions ─── projects    第二層：專案權限（N:M）
       ├─ admin_recovery_tokens           最高權限管理者救援
       └─ activity_logs                   歷史操作紀錄（快照欄位，不強制外鍵）

projects ─┬─ project_description_history  專案說明完整版本歷史
          ├─ project_keywords             關鍵字（原文 + 標準化）
          └─ project_notes                隨手記

login_attempts      登入節流（不存原始帳號字串）
system_settings     系統設定（只存覆寫值）
schema_migrations   Schema 版本
```

| 資料表 | 用途 | 重點設計 |
|---|---|---|
| `users` | 帳號、密碼雜湊、2FA 狀態 | `is_super_admin` 是唯一的特殊旗標，**不存在職級／角色欄位**；`auth_version` 用於讓舊 Session 立即失效 |
| `user_global_permissions` | 查看／編輯／建立／刪除／管理使用者，五個獨立布林值 | `row_version` 讓「單一員工儲存」也有併發保護 |
| `projects` | 名稱、說明、說明版本號 | 沒有類型欄位；`description_version` 用於樂觀併發控制；軟刪除 |
| `project_permissions` | 每專案 × 每員工的查看／編輯／刪除 | 沒有資料列 = 沒有權限 |
| `project_description_history` | 修改前、修改後完整內容、修改者、時間 | 只新增，應用層不提供更新或刪除 |
| `project_keywords` | 原文 + `keyword_norm` | `UNIQUE(project_id, keyword_norm)` 擋下「資料庫／数据库」重複 |
| `project_notes` | 隨手記 | 作者只來自 Session；`(project_id, deleted_at, id)` 索引支援最新在上的 Keyset 分頁 |
| `admin_recovery_tokens` | 救援 Token | 只存 SHA-256；`purpose` 區分用途；`used_at` 原子性標記 |
| `activity_logs` | 歷史操作紀錄 | 四組「欄位 + 時間」複合索引對應篩選需求 |

## 2. 關鍵 SQL 模式

### 2.1 雙重授權

```sql
SELECT 1
  FROM users u
  JOIN projects pr ON pr.id = :project_id AND pr.deleted_at IS NULL
LEFT JOIN user_global_permissions g ON g.user_id = u.id
LEFT JOIN project_permissions pp ON pp.user_id = u.id AND pp.project_id = pr.id
 WHERE u.id = :user_id AND u.status = 'active'
   AND (u.is_super_admin = 1
        OR (g.can_view_projects = 1 AND pp.can_view = 1      -- 兩層都必須可查看
            AND g.can_edit_projects = 1 AND pp.can_edit = 1)) -- 兩層都必須具備目標動作
```

`LEFT JOIN` 找不到資料列時欄位為 `NULL`，`NULL = 1` 不成立 → 自然達成預設拒絕。

### 2.2 權限內搜尋

權限條件與內容比對寫在**同一個 WHERE**，沒有「先搜全部、再過濾」的步驟，`COUNT` 也只會計算可見專案。
（完整 SQL 見測試檔 `search()`。）

### 2.3 專案說明樂觀併發控制

```sql
-- 交易內
SELECT description, description_version FROM projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE;  -- 取得「修改前」內容並鎖定該列
UPDATE projects
   SET description = ?, description_norm = ?, description_version = description_version + 1,
       updated_by = ?, updated_at = ?
 WHERE id = ? AND deleted_at IS NULL AND description_version = ?;  -- 以使用者送來的版本號為條件
-- rowCount() = 1 → 寫入 project_description_history（before/after）並 COMMIT
-- rowCount() = 0 → ROLLBACK，回 409「專案說明已被其他使用者修改，請重新載入頁面後再編輯。」
```

- 真正決定能否寫入的是**條件式 UPDATE 本身**，不是先查詢的結果，符合「不可先查版本再另外 UPDATE」的要求；
- `SELECT ... FOR UPDATE` 只為了在同一交易中取得精確的「修改前內容」寫入歷史；
- 因為版本號一定遞增，即使內容沒變也會影響 1 列，避免 MySQL「內容相同時 rowCount 為 0」的誤判；
- 前端收到 409 時保留文字框內容，並另存於 `localStorage` 草稿，不自動合併、不自動覆蓋。

### 2.4 救援 Token 單次使用

```sql
UPDATE admin_recovery_tokens
   SET used_at = :now
 WHERE token_hash = :hash AND purpose = :purpose
   AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > :now
```

影響 1 列才繼續；並在同一交易內完成密碼／2FA 重設與 `auth_version + 1`，任何一步失敗整體回滾。

## 3. 索引與效能

| 查詢情境 | 使用索引 |
|---|---|
| 專案列表（使用者可見專案） | `project_permissions.idx_pp_user_view (user_id, can_view, project_id)` |
| 單一專案授權判斷 | `project_permissions` 主鍵 `(project_id, user_id)` |
| 隨手記分頁 | `project_notes.idx_notes_project_id (project_id, deleted_at, id)` |
| 紀錄：日期範圍 | `activity_logs.idx_al_time` |
| 紀錄：員工 + 日期 | `activity_logs.idx_al_actor_time` |
| 紀錄：操作類型 + 日期 | `activity_logs.idx_al_action_time` |
| 紀錄：專案 + 日期 | `activity_logs.idx_al_project_time` |
| 登入節流 | `login_attempts` 的帳號／IP + 時間索引 |

歷史操作紀錄分頁採 **Keyset 分頁**（`WHERE (occurred_at, id) < (?, ?)`），避免 `OFFSET` 在大量資料下變慢；
匯出採分批串流輸出 CSV，並對 `= + - @` 開頭的儲存格加上前置字元，防止 CSV 公式注入。

## 4. 時間與時區

- 全部欄位存 UTC `DATETIME`；應用程式連線後設定 `SET time_zone = '+00:00'`；
- 篩選「2026-10-30」時，由應用程式把系統時區的當日 00:00–24:00 換算成 UTC 範圍後查詢，可使用索引；
- 上午／下午統計在 SQL 中以固定位移換算（台灣無日光節約時間），或於 PHP 端彙總。

## 5. 刪除與保存

- 專案、隨手記採**軟刪除**（`deleted_at`）；回收機制與永久刪除政策尚待確認；
- 歷史操作紀錄依系統設定保存天數，以分批 `DELETE ... ORDER BY id LIMIT n` 清理；
- 文件中**不宣稱**紀錄不可竄改：擁有資料庫權限者仍可修改。若日後需要防竄改，可評估雜湊鏈或定期匯出到外部唯讀儲存。

## 6. 跨資料庫移植注意事項

| 項目 | MySQL／MariaDB 草案 | 若改用 PostgreSQL |
|---|---|---|
| 自動遞增 | `AUTO_INCREMENT` | `GENERATED ALWAYS AS IDENTITY` |
| 布林 | `TINYINT(1)` | `BOOLEAN` |
| 二進位雜湊 | `BINARY(32)` | `BYTEA` |
| 大文字 | `MEDIUMTEXT` | `TEXT` |
| `*_norm` 二進位比對 | `COLLATE utf8mb4_bin` | 預設即為位元組比對（`C` collation） |
| LIKE 跳脫 | 統一寫 `ESCAPE '!'`（不受 `NO_BACKSLASH_ESCAPES` 影響） | 相同語法可用 |

所有 LIKE 一律寫明 `ESCAPE '!'` 並以 `!` 跳脫 `!`、`%`、`_`，避免受 `sql_mode` 影響。

## 7. 驗證結果（MariaDB 10.11.14）

`tests/schema_smoke_test.php`：**26 項全部通過**，涵蓋：

- 雙重授權四種組合、最高權限管理者、竄改專案 ID、不存在／已刪除專案；
- 撤銷專案權限、撤銷全域權限、停用帳號後立即拒絕；
- 搜尋不回傳未授權專案（含其說明與隨手記）、LIKE 萬用字元跳脫；
- 樂觀併發控制只允許一方成功、版本遞增；
- 救援 Token 用途不符失敗、只能使用一次、資料庫不存原始 Token；
- 關鍵字「資料庫／数据库」標準化後視為重複、原文保留。
