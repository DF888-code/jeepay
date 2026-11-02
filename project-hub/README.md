# Project Hub：個人／公司專案管理與知識紀錄平台

依據《Claude Code 開發需求規格書 v1.0》開發。

> 本目錄是獨立的 PHP 專案，與所在的 `jeepay`（Java 支付系統）程式碼無任何相依。建議日後移至獨立 Repo（待確認事項 #20）。

## 目前進度

| 階段 | 狀態 |
|---|---|
| 1 環境檢查及技術架構規劃 | 已完成（InfinityFree 已實測） |
| 2 資料庫 Schema 設計 | 已完成（`database/migrations/0001_initial_schema.sql`） |
| 3 使用者登入及 2FA | 已完成（測試報告見 [docs/05](docs/05-development-plan.md)） |
| 4 全域權限管理 | 已完成（管理後台、使用者管理、全域權限、單一裝置登入） |
| 5 專案管理及個別專案權限 | 已完成（建立／列表／詳細／改名／刪除與回收區、專案授權名單、雙重授權） |
| 6 專案說明、關鍵字、隨手記（含附件） | 已完成（說明版本歷史與衝突保護、關鍵字、隨手記、圖片與附件上傳） |
| 7 全站搜尋及簡繁轉換 | 已完成（繁簡與兩岸用語互搜、權限內搜尋、索引重建） |
| 8 歷史操作紀錄（查詢介面） | 已完成（篩選、統計、每小時分布、CSV 匯出、保存期限） |
| 9 系統設定中心（含寄信、Google Drive 附件儲存） | 已完成（一般設定、SMTP／Brevo 寄信與測試、Google Drive 連結與儲存位置切換） |
| 10 Email 救援流程 | 已完成（三種救援情境、單次 Token、冷卻與通知、救援 Email 設定） |
| 11 RWD 及介面改善 | 已完成（375／768／1280 三種寬度自動檢查 51 個畫面、長字串與觸控目標修正） |
| 12 完整安全測試及部署 | 已完成（全站路由安全掃描、Apache 部署驗證、安全測試報告） |

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
| [docs/09-security-report.md](docs/09-security-report.md) | 安全測試報告（v1.0.0）、修正項目與殘餘風險 |

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

最近一次結果（MariaDB 10.11、PHP 8.3）：單元 15、整合 157（登入 27＋管理後台 23＋專案與雙重授權 17＋說明／關鍵字／隨手記 14＋附件 15＋搜尋 12＋歷史紀錄 10＋系統設定 17＋Email 救援 11＋安全掃描 11）、Schema 26 項，全部通過。整合測試會啟動假的 Google／Brevo／SMTP 服務（`tests/Fakes/`），不需連網。

## 部署

```bash
composer install --no-dev
php tools/build-deploy.php   # 產生 dist/project-hub-htdocs-v{版本}-{日期}.zip（版本號在 VERSION 檔，紀錄見 CHANGELOG.md）
```

上傳與安裝步驟見 [docs/07-deployment.md](docs/07-deployment.md)。
