# Project Hub：個人／公司專案管理與知識紀錄平台

依據《Claude Code 開發需求規格書 v1.0》開發。

> 本目錄是獨立的 PHP 專案，與所在的 `jeepay`（Java 支付系統）程式碼無任何相依。建議日後移至獨立 Repo（待確認事項 #20）。

## 目前進度

| 階段 | 狀態 |
|---|---|
| 1 環境檢查及技術架構規劃 | 已完成（InfinityFree 已實測） |
| 2 資料庫 Schema 設計 | 已完成（`database/migrations/0001_initial_schema.sql`） |
| 3 使用者登入及 2FA | 已完成（測試報告見 [docs/05](docs/05-development-plan.md)） |
| 4 全域權限管理 | 下一步 |

## 文件

| 文件 | 內容 |
|---|---|
| [docs/01-host-environment.md](docs/01-host-environment.md) | InfinityFree 主機限制與實測結果、Email 連線評估、資料庫選型 |
| [docs/02-architecture.md](docs/02-architecture.md) | 技術選擇、目錄結構、請求流程、搜尋與簡繁標準化、紀錄、設定、RWD |
| [docs/03-database-design.md](docs/03-database-design.md) | 資料表設計、關鍵 SQL 模式（雙重授權、樂觀併發、Token 原子使用）、索引 |
| [docs/04-authorization-and-security.md](docs/04-authorization-and-security.md) | 雙重授權矩陣、登入與 2FA 狀態機、管理者救援、Web 安全、權限測試清單 |
| [docs/05-development-plan.md](docs/05-development-plan.md) | 12 階段開發計畫、各階段報告 |
| [docs/06-open-questions.md](docs/06-open-questions.md) | 待確認事項與決議 |
| [docs/07-deployment.md](docs/07-deployment.md) | InfinityFree 部署步驟、緊急重設、部署安全檢查清單 |
| [docs/08-note-attachments.md](docs/08-note-attachments.md) | 隨手記檔案與圖片上傳（規格七之一）設計與主機限制 |

## 目錄

```
public/        對外公開（index.php、.htaccess、assets）；部署時放在 htdocs 根目錄
app/           程式碼（App、Auth、Http、Settings、Activity、Controllers…）
views/         PHP 樣板
config/        config.example.php（config.php 含機密，不進版控）
database/      遷移檔
storage/       Session、安裝鎖（執行期產生，不進版控）
tests/         單元測試、整合測試、Schema 驗證測試
tools/         host-check.php（主機探針）、build-deploy.php（產生部署包）
```

## 開發

```bash
cd project-hub
composer install

# 單元測試（不需資料庫）
php tests/run.php

# 加上整合測試：會實際啟動網站並清空指定資料庫（名稱必須含 test）
PH_TEST_DB_HOST=127.0.0.1 PH_TEST_DB_NAME=ph_it_test PH_TEST_DB_USER=ph PH_TEST_DB_PASS=*** \
php tests/run.php

# Schema 驗證測試（雙重授權、搜尋範圍、樂觀併發等 SQL 模式）
PH_TEST_DSN="mysql:host=127.0.0.1;dbname=ph_test;charset=utf8mb4" PH_TEST_USER=ph PH_TEST_PASS=*** \
php tests/schema_smoke_test.php
```

最近一次結果（MariaDB 10.11、PHP 8.3）：單元 10、整合 27、Schema 26 項，全部通過。

## 部署

```bash
composer install --no-dev
php tools/build-deploy.php   # 產生 dist/project-hub-htdocs.zip
```

上傳與安裝步驟見 [docs/07-deployment.md](docs/07-deployment.md)。
