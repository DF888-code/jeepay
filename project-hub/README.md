# Project Hub：個人／公司專案管理與知識紀錄平台

依據《Claude Code 開發需求規格書 v1.0》建立。目前進度：**階段 1（環境檢查及技術架構規劃）與階段 2（Schema 草案）**，尚未開始應用程式實作。

> 注意：本目錄是獨立的 PHP 專案，與所在的 `jeepay`（Java 支付系統）程式碼無任何相依。建議日後移至獨立 Repo，見待確認事項 #20。

## 文件

| 文件 | 內容 |
|---|---|
| [docs/01-host-environment.md](docs/01-host-environment.md) | InfinityFree 主機限制、Email 連線評估、資料庫選型比較 |
| [docs/02-architecture.md](docs/02-architecture.md) | 技術選擇、目錄結構、請求流程、搜尋與簡繁標準化、紀錄、設定、RWD |
| [docs/03-database-design.md](docs/03-database-design.md) | 資料表設計、關鍵 SQL 模式（雙重授權、樂觀併發、Token 原子使用）、索引 |
| [docs/04-authorization-and-security.md](docs/04-authorization-and-security.md) | 雙重授權矩陣、登入與 2FA 狀態機、管理者救援、Web 安全、權限測試清單 |
| [docs/05-development-plan.md](docs/05-development-plan.md) | 12 階段開發計畫與每階段驗收標準 |
| [docs/06-open-questions.md](docs/06-open-questions.md) | 待確認事項與暫定建議 |

## 工具與測試

| 檔案 | 用途 |
|---|---|
| `tools/host-check.php` | 上傳至候選主機，檢查 PHP 擴充、停用函式、SMTP／HTTPS／外部資料庫連線（需設定存取金鑰，用完即刪） |
| `database/schema.mysql.draft.sql` | Schema 草案（MySQL 8 / MariaDB 10.6+） |
| `tests/schema_smoke_test.php` | 以真實資料庫驗證 Schema 的關鍵 SQL 模式（26 項） |

### 執行 Schema 驗證測試

```bash
# 需要一個名稱含 test 的空資料庫，測試會清空並重建其中所有資料表
PH_TEST_DSN="mysql:host=127.0.0.1;dbname=ph_test;charset=utf8mb4" \
PH_TEST_USER=ph PH_TEST_PASS=*** \
php project-hub/tests/schema_smoke_test.php
```

最近一次結果（MariaDB 10.11.14、PHP 8.3.6）：26 通過、0 失敗。

## 下一步

1. ~~在 InfinityFree 上執行 `tools/host-check.php`~~（2026-10-30 已完成：資料庫定案 MySQL／MariaDB，SMTP 與 HTTPS 皆可對外連線）；
2. 確認 `docs/06-open-questions.md` 中的項目（特別是 #1 資料庫、#3 郵件服務、#8–#12 權限細節）；
3. 確認後將 Schema 草案轉為正式遷移檔，進入階段 3（登入及 2FA）。
