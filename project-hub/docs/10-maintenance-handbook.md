# 10 維護交接手冊

> 給日後接手維護的人與 AI 助手（Claude、ChatGPT／Codex、Cursor 等）。
> 先讀本文件，再依需要查閱 01–09 文件。最後更新：2026-11-04（v1.2.1）。

## 1. 一分鐘認識這個專案

| 項目 | 內容 |
|---|---|
| 是什麼 | 多人使用的「專案管理與知識紀錄平台」：專案、專案說明（含版本歷史）、關鍵字、隨手記（含圖片與附件）、全站搜尋（繁簡互搜）、歷史操作紀錄、系統設定 |
| 技術 | PHP 8.1+（正式主機 PHP 8.4），**不使用框架**；MySQL／MariaDB；Bootstrap 5.3.8；Hotwire Turbo 8.0.23；前端資源全部放在本站，不用 CDN |
| 正式主機 | InfinityFree 免費主機（`book888.free.je`），限制見第 6 節 |
| 程式位置 | GitHub `DF888-code/jeepay` 倉庫的 `project-hub/` 子目錄（與 jeepay 的 Java 程式無關，建議日後搬到獨立倉庫，決議 #20） |
| 版本 | 見 `VERSION` 檔與 `CHANGELOG.md`；登入後頁尾顯示版本 |
| 語言規範 | 介面、註解、文件、提交訊息一律**繁體中文**；註解寫「為什麼」與「注意事項」 |

### 必讀文件

| 文件 | 何時看 |
|---|---|
| `docs/02-architecture.md` | 了解目錄結構、請求流程 |
| `docs/03-database-design.md` | 改資料表前 |
| `docs/04-authorization-and-security.md` | **改任何權限、登入、資料讀取前必讀** |
| `docs/06-open-questions.md` | 所有設計決議（#1–#39）與理由；改行為前先查是否有既有決議 |
| `docs/07-deployment.md` | 部署、升級、緊急重設、上線檢查清單 |
| `docs/09-security-report.md` | 安全測試結果與已知殘餘風險 |
| `CHANGELOG.md` | 每一版改了什麼 |

## 2. 絕對不能違反的原則（規格書核心）

1. **雙重授權**：專案相關操作必須同時通過「全域權限」與「專案權限」，且編輯、刪除也要具備查看權限。一律用 `Authorizer::can()`；列表與搜尋用 `Authorizer::visibleCondition()` 在 SQL 內篩選。
2. **預設拒絕**：沒有權限與「不存在」回應完全相同（404），不透露資源是否存在。
3. **不可用前端隱藏取代後端授權**：按鈕隱藏只是體驗，伺服器每個請求都要檢查。
4. **不寫入敏感資料**：密碼、2FA 驗證碼與金鑰、Token、API 金鑰、SMTP 密碼、救援連結，絕不寫入紀錄、錯誤訊息或畫面。
5. **破壞性操作要確認**：刪除、重設、中斷連結等需要確認視窗；不可在未經同意下刪除使用者資料（專案是軟刪除，可還原）。
6. **CSP 嚴格**：`script-src 'self'`、`style-src 'self'`（只有 Turbo 進度條用 nonce），**禁止 inline script／inline style／on* 事件屬性**。要動態效果就用 CSS class 加 `data-*` 屬性，再寫在 `public/assets/*.js`。
7. **所有輸出跳脫**：樣板一律 `<?= e($值) ?>`；只有整數 ID 與程式內固定字串可直接輸出。JS 寫入使用者內容時用 `textContent`，不用 `innerHTML`。

## 3. 目錄與重要檔案

```
project-hub/
├── public/            部署時成為 htdocs 根目錄：index.php、.htaccess、assets/
│   └── assets/        app.css、各頁 JS、vendor/（bootstrap、turbo）
├── app/
│   ├── App.php        路由表（routes()）、存取等級、安全標頭、CSP、錯誤處理
│   ├── Controllers/   各功能控制器；Admin*Controller 皆繼承 AdminController（再驗證）
│   ├── Authorization/ Authorizer（雙重授權的唯一入口）
│   ├── Auth/          登入狀態機、TOTP、登入節流、密碼
│   ├── Attachment/    附件：本機、Google Drive、Cloudflare R2（S3Signer 自行實作 SigV4）
│   ├── Settings/      SettingsRegistry（所有設定的定義）、Settings、SecretSettings（加密機密）
│   ├── Mail/          SMTP 與 Brevo API 寄信
│   ├── Recovery/      最高權限管理者 Email 救援 Token
│   ├── Search/        繁簡轉換（OpenCC 字典）、搜尋、索引重建
│   ├── Activity/      歷史操作紀錄（ActivityLabels 是操作代碼的中文名稱）
│   └── Support/       Database、Crypto、View、HttpClient、ErrorLog、Clock
├── views/             PHP 樣板（layout.php 為共用版面）
├── database/migrations/  0001–0005 遷移檔（只能新增，不可修改已發佈的檔案）
├── tests/             單元、整合、安全掃描；tests/Fakes/ 為假的 Google／R2／Brevo／SMTP
├── tools/             build-deploy.php（產生部署包）、host-check.php（主機探針）
└── docs/              01–10 文件
```

## 4. 常見修改的做法

### 新增一個頁面或 API
1. 在 `app/App.php` 的 `routes()` 加一行：`'GET /路徑' => [存取等級, 控制器::class, '方法']`。存取等級：`ACCESS_AUTH`（登入）、`ACCESS_ADMIN`（最高權限管理者，其他人 404）、`ACCESS_GUEST`、`ACCESS_PUBLIC` 等。
2. 專案相關資料**一定**先用 `$this->authorizer()->can($uid, Authorizer::VIEW/EDIT/DELETE, $projectId)` 檢查，失敗回 `$this->deny(...)`。
3. 狀態變更一律用 POST（CSRF 由 App 自動檢查），成功後 `return $this->back(網址, 'success', 訊息)`（303 轉址）。驗證錯誤回 4xx 並重新顯示表單。
4. 寫入歷史紀錄：`$this->app->logger->log(類別, 操作代碼, 結果, ...)`，並在 `ActivityLabels` 加中文名稱。
5. 安全掃描測試（`SecurityTest`）會自動檢查新路由的未登入、越權與 CSRF。

### 新增一個系統設定
只要在 `app/Settings/SettingsRegistry.php` 加定義（型別、預設值、範圍、說明），設定中心就會自動出現欄位。程式中用 `$this->app->settings->int/string/bool('鍵')` 讀取。機密值（密碼、金鑰）不要放這裡，改用 `SecretSettings`（加密保存、不回顯）。

### 修改資料表
新增 `database/migrations/000N_說明.sql`，只做新增型變更。規則：字串與 COMMENT 內**不可有半形分號**（遷移以行尾分號切割）。部署後管理者進後台會被導向「資料庫更新」頁。同時更新 `tests/Integration/AuthFlowTest.php` 中的遷移清單斷言。

### 前端互動（Turbo 注意事項）
- 站內換頁由 Turbo 處理，不會重新載入整頁。**共用 JS 放在 `<head>`**（只執行一次）；**各頁專用 JS 放在 `$scripts`**（每次進入該頁都會重新執行）。
- 各頁 JS 若在 `document`／`window` 註冊監聽，必須用旗標確保只註冊一次（參考 `image-viewer.js`、`admin-permissions.js`）。
- 檔案下載、外部轉址（Google 授權）、登入／登出相關表單，要加 `data-turbo="false"`。
- POST 回應必須是轉址（303）或 4xx／5xx；回 200 HTML 時 Turbo 不會顯示。
- 已關閉 Turbo 預先載入（避免誤記「進入專案」）與頁面快照（避免臨時密碼在上一頁重現）。

## 5. 開發、測試與發佈

### 本機開發
```bash
cd project-hub
composer install
# 開發伺服器（需要一個 config.php，可用 PH_CONFIG_FILE 指定）
PH_CONFIG_FILE=/路徑/config.php php -S 127.0.0.1:8130 -t public public/index.php
```

### 測試（每次修改後都要跑，全部通過才能發佈）
```bash
php tests/run.php                       # 只跑單元測試
PH_TEST_DB_HOST=127.0.0.1 PH_TEST_DB_NAME=ph_it_test PH_TEST_DB_USER=ph PH_TEST_DB_PASS=*** php tests/run.php
                                        # 加整合測試：會清空該資料庫（名稱必須含 test）
```
整合測試會自動啟動網站與假的外部服務（Google、R2、Brevo、SMTP），不需連網。目前結果：單元 17、整合 168 項全部通過。

### 發佈新版
1. 修改 `VERSION`（規則：修正 +0.0.1、新功能 +0.1.0）並在 `CHANGELOG.md` 最上方加一列；
2. 新的設計決定寫入 `docs/06-open-questions.md`；
3. `composer install --no-dev && php tools/build-deploy.php` → 產生 `dist/project-hub-htdocs-v{版本}-{日期}.zip`；
4. 提交（中文訊息）並推送；
5. 部署：見 `docs/07-deployment.md` 第 2 節（上傳 zip → 解壓覆蓋 → 進後台套用資料庫更新）。

## 6. 主機與外部服務的限制（踩過的坑）

| 項目 | 狀況與對策 |
|---|---|
| InfinityFree | PHP 8.4；單一請求 60 秒；單檔 10 MB；不能 `exec`；檔案數約 3 萬；htdocs 上層不可寫；有 CPU、同時處理數、每日請求數限制（數字不公開，超過可能暫停 24 小時）；條款禁止當檔案儲存 → 附件建議改存 R2 或 Google Drive |
| PHP 錯誤日誌 | InfinityFree 看不到 → 系統自己寫在 `storage/logs/`，管理後台「系統錯誤」以追蹤代碼查詢 |
| 對外連線 | SMTP 25／465／587、HTTPS 443 可連線（已實測） |
| Cloudflare R2 | 填「S3 API 端點」（歐盟儲存區端點不同）；bucket 必須私有；API 權杖只給單一 bucket 的物件讀寫；已有檔案後不可更換 bucket |
| Google Drive | 只要求 `drive.file` 權限；OAuth 同意畫面必須「已發布」，否則 7 天後授權失效 |
| 寄信 | 寄件者必須是寄信服務已驗證的地址；`config.php` 的 `base_url` 必須設定，否則不寄救援信（防 Host 標頭偽造） |

## 7. 開發時遇過的陷阱（避免重蹈覆轍）

- 遷移檔切行**不可用正規表示式 `\R`**：非 UTF-8 模式下會把中文字中的位元組當成換行。
- 樣板變數不可命名為 `content`（保留給版面），View 會直接丟出錯誤。
- 登出、登入時用 `Session::clearAuth()` 清空整個 Session（只保留提示訊息）；曾發生「管理者已驗證」狀態殘留的漏洞（v0.4.1）。
- TOTP 有防重放：同一組驗證碼只能用一次；自動化測試要等下一個 30 秒時間步，或用 `allowCurrentCode()`。
- 整合測試的 `Browser` 從上一個回應的頁面取 CSRF Token；POST 之後是 303 沒有表單，下一個 POST 前要先 GET 一個有表單的頁面。
- PHP 的檔案狀態快取：測試檢查另一個行程刪除的檔案前要 `clearstatcache()`。
- 輸入一律經 `Request::input()`／`query()`（已移除 NUL 字元）；不要直接讀 `$_GET`、`$_POST`。
- 設定頁表單新增欄位後，舊測試若手動組表單資料，要補上新欄位，否則會被判定不合法（422）。
- 「單一裝置登入」預設開啟：同帳號在另一個瀏覽器登入，原本的會被登出。
- 登入失敗後才顯示救援入口，且所有帳號一致；對外用詞保持中性（決議 #37）。

## 8. 最重要的機密與備份（遺失無法補救）

| 項目 | 位置 | 說明 |
|---|---|---|
| `app_key` | 主機 `config/config.php` | 所有 2FA 金鑰、寄信密碼、R2／Google 金鑰都用它加密。**遺失或更改 = 所有人都要重設 2FA、所有機密設定要重填。** 請另外安全保存一份 |
| 資料庫 | InfinityFree phpMyAdmin | 定期匯出備份（主機沒有備份保證） |
| 本機附件 | 主機 `storage/uploads/` | 若仍有存本機的附件，需用 FTP 備份 |
| R2／Google 上的附件 | 各雲端帳號 | 檔名為隨機代碼，必須搭配資料庫的 `note_attachments` 才知道是哪個附件 |

`config.php` 與 `storage/` 不在 Git 與部署包裡，請自行備份。

## 9. 尚未完成／可考慮的事項

- `/projects/1` 曾在正式環境出現一次系統錯誤（追蹤代碼 `5d80b170d638`，發生在錯誤紀錄功能上線前，無法追查）；再發生時到「系統錯誤」查詢。
- OneDrive 儲存：未支援（免費 5 GB 且與 Outlook 附件共用），有 Microsoft 365 訂閱時再評估。
- 可選的效能改善（未實作）：縮圖允許瀏覽器暫存 10 分鐘、縮圖捲動到才載入。
- 附件在本機／雲端之間的搬移工具：未實作。
- 建議把 `project-hub/` 搬到獨立的 Git 倉庫，並把開發分支合併為主要分支。

## 10. 給 AI 助手的開場提示（可直接複製）

> 這是一個 PHP 8.1+ 無框架的專案管理平台，程式在 `project-hub/`。請先閱讀 `project-hub/AGENTS.md` 與 `project-hub/docs/10-maintenance-handbook.md`，
> 修改權限或資料存取前閱讀 `docs/04-authorization-and-security.md`，修改行為前查 `docs/06-open-questions.md` 的既有決議。
> 介面、註解、文件、提交訊息使用繁體中文。修改後執行 `php tests/run.php`（含整合測試）確認全部通過，並更新 `VERSION` 與 `CHANGELOG.md`。
> 我要做的事：（在這裡描述需求）
