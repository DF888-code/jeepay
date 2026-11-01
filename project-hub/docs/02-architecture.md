# 02 技術架構

## 1. 架構原則

| 原則 | 具體做法 |
|---|---|
| 專案是通用資料容器 | `projects` 不設「類型」欄位；用途差異只透過權限與內容呈現 |
| 權限只在伺服器端 | 所有頁面與 API 都經過同一個 `Authorizer`，前端隱藏按鈕只是體驗優化 |
| 預設拒絕 | 沒有授權資料列、查不到使用者、帳號停用、專案已刪除，一律視為拒絕 |
| 每次請求重新驗證 | 不在 Session 快取權限；每個請求重新查詢，撤銷立即生效 |
| 主機可替換 | 不依賴 Cron、SSH、`exec`、外部 QR API；郵件、資料庫皆透過介面隔離 |
| 少依賴 | 免費主機有檔案數上限且無 Composer，只引入必要套件 |

## 2. 技術選擇

| 層 | 選擇 | 理由 |
|---|---|---|
| 後端 | PHP 8.1+，無大型框架，自建精簡分層 | Laravel／Symfony 檔案數龐大，不利 FTP 部署與 inode 限制；本系統路由與功能數量有限 |
| 資料存取 | PDO + 參數化查詢，`ATTR_EMULATE_PREPARES=false` | 防 SQL Injection；集中在 Repository |
| 前端 | 伺服器端渲染 HTML + 原生 JS（`fetch` 呼叫 JSON API 處理局部互動）；**介面風格改為 Bootstrap 5**（自行託管於 `assets/vendor/`，見待確認事項 #23） | 不需建置工具；Bootstrap 已編譯好的 CSS／JS 直接放在本站，不違反 CSP |
| TOTP | 自行實作 RFC 6238（約 80 行，可完整單元測試） | 避免引入不必要的套件 |
| QR Code | 純 PHP 產生 SVG（候選：`chillerlan/php-qrcode`），或打包本地 JS | Secret 不得離開本系統 |
| SMTP | 候選 `phpmailer/phpmailer`（成熟、檔案少、支援 STARTTLS／SMTPS） | 自行實作 SMTP 協定容易出現安全問題 |
| 簡繁轉換 | 純 PHP 的 OpenCC 實作（候選：`overtrue/php-opencc`）或自行載入 OpenCC 詞典做最長詞優先比對 | 免費主機無法執行 OpenCC 執行檔；詞彙級轉換符合規格「不可只用單字元替換」 |
| 測試 | 自建極簡測試工具（`tests/run.php`）+ 整合測試實際啟動網站並使用真實 MariaDB | 不增加相依套件；權限測試必須打到真實 SQL 與真實 HTTP 流程，不能只 mock |

> 候選套件在階段 3／7 正式引入前，會再確認授權條款、檔案數量與 PHP 版本相容性。

## 3. 目錄結構（規劃）

```
project-hub/
├── public/                  # 唯一對外公開的目錄（部署時內容放到 htdocs/）
│   ├── index.php            # 前端控制器：所有請求由此進入
│   ├── assets/              # CSS、JS、圖示
│   └── .htaccess            # 轉址、安全標頭、拒絕存取隱藏檔
├── app/
│   ├── Http/                # Router、Request、Response、Middleware
│   ├── Auth/                # 登入、TOTP、Session、救援
│   ├── Authorization/       # Authorizer（雙重授權唯一入口）
│   ├── Project/             # 專案、說明歷史、關鍵字、隨手記
│   ├── Search/              # 搜尋服務、TextNormalizer（OpenCC）
│   ├── Activity/            # ActivityLogger、紀錄查詢與匯出
│   ├── Admin/               # 使用者管理、權限管理、系統設定
│   ├── Settings/            # SettingsRegistry（型別、預設值、範圍）
│   ├── Mail/                # MailTransport 介面與實作
│   └── Support/             # DB、Csrf、Crypto、Clock、View 等工具
├── views/                   # PHP 樣板（輸出一律經過 e() 跳脫）
├── config/
│   ├── config.example.php   # 範本（進版控）
│   └── config.php           # 實際機密設定（不進版控）
├── database/
│   └── migrations/          # 依序執行的 SQL 遷移（0001_initial_schema.sql 起）
├── storage/                 # Session（若自管）、匯出暫存；.htaccess 全面拒絕
├── tests/
└── tools/host-check.php
```

部署到 InfinityFree 時，若 Web 根目錄之外無法放檔案，則 `app/`、`config/`、`storage/` 等目錄放在 `htdocs/` 內並各自以 `.htaccess`
`Require all denied` 拒絕存取（同時程式檔內容不會被直接輸出）。

## 4. 請求處理流程

```
請求 → public/index.php
  → SecurityHeaders（CSP、X-Frame-Options、nosniff、Referrer-Policy）
  → SessionStart（HttpOnly、Secure、SameSite=Lax、strict_mode、定期 regenerate）
  → LoadCurrentUser
      每次從資料庫讀取使用者；檢查 status=active 且 auth_version 與 Session 內相同，否則清除 Session
  → AuthStageGate
      未登入            → 只允許登入、救援頁
      已過密碼、待 TOTP → 只允許 TOTP 驗證頁
      需重新綁定 2FA    → 只允許 2FA 綁定頁與登出（含 API，一律 403）
      完整登入          → 進入一般路由
  → CsrfGuard（所有 POST/PUT/PATCH/DELETE）
  → Router → Controller
      → Authorizer::require(user, action, projectId)   ← 雙重授權
      → Service / Repository（參數化 SQL）
      → ActivityLogger（伺服器端產生紀錄）
  → Response（HTML 或 JSON；錯誤訊息不含內部細節）
```

### 未授權回應的一致性

查看「不存在的專案」與「無權限的專案」回傳**相同的 404 回應**，避免透過狀態碼差異推測專案是否存在。
內部仍記錄為 `project.view` / `denied`，供管理者查看。

## 5. 雙重授權（摘要）

完整規則見 [04-authorization-and-security.md](04-authorization-and-security.md)。

```php
// 唯一入口；所有控制器、API、搜尋、匯出、歷史紀錄都必須經過
$authorizer->require($user, ProjectAction::Edit, $projectId);
```

- 最高權限管理者：全部允許；
- 一般使用者：`全域權限 AND 專案權限`，且查看以外的動作同時要求兩層都具備「查看」；
- 建立專案：只檢查全域建立權限；
- 列表與搜尋不是逐筆呼叫 `require`，而是使用相同條件的 SQL 片段 `Authorizer::visibleProjectsCondition()`，確保在資料庫層就限縮範圍。

## 6. 搜尋與簡繁標準化

### 標準化函式（寫入與查詢共用同一個函式）

```
normalize(text) = OpenCC_t2s( lowercase( NFKC(text) ) ) 並壓縮連續空白
```

- **NFKC**：全形英數轉半形（`ＡＢＣ１２３` → `ABC123`）；
- **小寫**：英文不分大小寫；
- **OpenCC 繁轉簡（詞彙級）**：`資料庫` → `数据库`、`軟體` → `软件`。統一轉為簡體作為比對基準，因為繁轉簡多為多對一，合併後召回率較高；
- 原文欄位（`name`、`description`、`keyword`、`content`）保持使用者輸入，不受影響。

### 已知限制

- 詞彙級轉換也無法 100% 涵蓋所有地區用語差異（例如「記憶體」對「内存」屬地區用詞而非字形轉換）。OpenCC 的 `tw2sp` 設定包含部分台灣慣用詞轉換，
  可評估採用；但規格要求的是「簡繁互搜」，地區用語轉換列為可選強化項目。
- 標準化規則若日後改變（例如更新詞典），需要重新計算所有 `*_norm` 欄位，系統會提供管理端的重建工具，分批執行以避免逾時。

### 查詢方式

- 使用 `LIKE ... ESCAPE '!'` 比對 `*_norm` 欄位，並跳脫 `!`、`%`、`_`；
- 權限條件與內容條件在**同一個 SQL** 中，搜尋結果數量也只來自可見範圍；
- 結果以專案為單位去重，並附上「符合的隨手記」前 N 筆摘要（摘要只取自已授權專案）；
- 以分頁回傳，限制關鍵字最短長度（預設 1 個中文字或 2 個英數字，可設定）與最長長度。

資料量成長後（例如隨手記超過數十萬筆），`LIKE` 會變慢。屆時的升級路徑：自建 bigram 倒排索引表，或改用 MySQL 8 ngram（MariaDB 不支援）。
初期資料量下 `LIKE` 足夠，且行為在各資料庫一致。

## 7. 歷史操作紀錄

- `ActivityLogger` 只在伺服器端呼叫；前端點擊事件不作為紀錄來源；
- 寫入前經過 `detail` 白名單欄位處理，**禁止**傳入密碼、驗證碼、Token、Secret、隨手記內文、專案說明內文；
- 「離開專案」無法可靠取得（關閉分頁、斷網、手機切換 App 都不會通知伺服器），第一階段**不記錄離開時間**，
  只記錄進入時間；如未來需要，可用 `navigator.sendBeacon` 盡力回報並標示為「參考值」；
- 時間一律以 UTC 寫入，依系統設定時區顯示；上午／下午統計以系統時區計算（預設 `Asia/Taipei`，00:00–11:59 為上午）；
- 保存期限清理：無 Cron，因此在管理者開啟紀錄頁時，以每次最多 N 筆分批刪除過期資料。

## 8. 系統設定

- `SettingsRegistry` 以程式碼定義每個設定：分類、鍵名、型別、預設值、允許範圍、是否敏感、說明文字；
- 資料庫 `system_settings` 只保存「被修改過」的值，讀取時依定義檔驗證，無效值回退預設並記錄警告；
- 新功能只要在 Registry 新增定義，設定頁面就會自動出現對應分類；
- SMTP 密碼等機密：**優先放在 `config/config.php`**；若必須透過介面設定，則以伺服器金鑰加密後存入資料庫，且介面只顯示「已設定／未設定」，不回填明文。

## 9. 前端與 RWD

| 頁面 | 桌機（≥ 1024px） | 手機（< 768px） |
|---|---|---|
| 專案列表／搜尋結果 | 表格 | 卡片 |
| 全域權限管理 | 每位員工一列，權限橫向排列，每列一個「儲存〔員工名稱〕」按鈕 | 每位員工一張卡片，權限直向排列，卡片底部儲存按鈕 |
| 專案權限管理 | 員工 × 權限的表格 | 每位員工一張卡片 |
| 歷史操作紀錄 | 篩選列 + 統計 + 表格 | 篩選摺疊、統計卡片、時間軸列表 |
| 系統設定中心 | 左側分類、右側表單 | 分類改為上方下拉選單 |

全域權限頁的每個「儲存」按鈕只送出該員工的資料（員工 ID + 權限 + `row_version`），未儲存的修改以視覺標記提醒，離開頁面時提示。
