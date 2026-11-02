# 09 安全測試報告（階段 12，v1.0.0）

測試日期：2026-11-02。環境：PHP 8.3、MariaDB 10.11；部署包另以 Apache 2.4 + mod_php（`AllowOverride All`）實測。
正式主機 InfinityFree（PHP 8.4）的部分項目需上線後依 07 文件第 4 節再確認一次。

## 1. 結果總覽

| 類別 | 項目數 | 結果 |
|---|---|---|
| 單元測試（TOTP、加密、簡繁轉換等） | 15 | 全部通過 |
| 整合測試（實際啟動網站＋真實資料庫） | 157 | 全部通過 |
| Schema 驗證測試（雙重授權、搜尋範圍等 SQL） | 26 | 全部通過 |
| Apache 部署包目錄保護 | 27 個路徑 | 全部 403／404 |
| 相依套件弱點（`composer audit`） | — | 無已知弱點 |
| 版面檢查（階段 11，51 個畫面） | — | 無水平捲動、無 CSP 錯誤 |

## 2. 全站自動掃描（`tests/Integration/SecurityTest.php`）

以反射讀出完整路由表（79 條）逐條測試。**日後新增的路由會自動納入**，不需另外補測試。

| 檢查 | 方法 | 結果 |
|---|---|---|
| 存取等級 | 每條路由都有明確等級；`/admin` 開頭一律為管理後台等級；不需登入的只有救援確認頁 | 通過 |
| 未登入 | 所有需登入的 GET／POST（帶有效 CSRF Token）→ 導向登入頁或 API 401；前後比對 11 個資料表的指紋，資料完全沒變 | 通過 |
| 一般員工越權 | 全域權限全開的員工呼叫所有管理後台路由 → 一律 404，頁面不透露後台存在，留下拒絕紀錄；資料不變 | 通過 |
| CSRF | 管理者（且已再驗證）對所有狀態變更路由送出「沒有 Token」與「錯誤 Token」→ 全部 400；登入、救援申請表單同樣檢查；資料不變 | 通過 |
| 路由變形 | PUT／DELETE／PATCH／OPTIONS、超大 ID、0、負數、`01`、`1abc`、`/index.php/admin` → 404 | 通過 |
| 安全標頭 | 一般頁、JSON、404：CSP（`default-src 'self'`、無 `unsafe-inline`、`frame-ancestors 'none'`、`form-action 'self'`）、`nosniff`、`X-Frame-Options: DENY`、`Referrer-Policy`、`Cache-Control: no-store`；不送出 `X-Powered-By` | 通過 |
| 原始碼中無 inline | 主要頁面沒有 inline script、inline style、`on*=` 事件屬性 | 通過 |
| Cookie／Session | `HttpOnly`、`SameSite=Lax`（HTTPS 時加 `Secure`）；登入後更換 Session ID；登入前的 ID 與登出後的 ID 都無法使用 | 通過 |
| 注入字串 | 10 種 SQL／HTML／NUL 控制字元／超長字串／路徑字串放入搜尋、API、紀錄篩選與匯出 → 沒有 500、沒有未跳脫輸出、沒有洩漏密碼雜湊；專案名稱含 `<script>` 以文字顯示 | 通過（修正 1 項，見第 4 節） |
| 開放式轉址 | 再驗證的返回網址放入外部網址、`//`、反斜線、`@`、CRLF → 一律回到站內 | 通過 |
| Host 標頭偽造 | 申請救援時偽造 `Host`、`X-Forwarded-Host` → 信中連結仍使用 `base_url` | 通過（修正 1 項，見第 4 節） |
| 錯誤訊息 | 錯誤頁不含路徑、SQL、例外名稱或 PHP 版本 | 通過 |

各功能的專門測試（雙重授權矩陣、TOTP 防重放、登入鎖定、附件 MIME 偽裝與 polyglot、撤銷權限即時生效、搜尋不洩漏、救援 Token 單次使用、機密不入紀錄等）見 05 文件各階段報告。

## 3. 程式碼檢視

| 項目 | 結果 |
|---|---|
| SQL | 全部使用 PDO 參數化（關閉模擬 prepare）。字串插入 SQL 的位置只有：程式內建的條件片段（`visibleCondition()`、搜尋 LIKE 條件）與白名單資料表名稱（搜尋索引重建） |
| 樣板輸出 | 未經 `e()` 的輸出只有整數 ID 與程式內固定字串 |
| 危險函式 | 無 `eval`、`exec`、`system`、`unserialize`、動態 `include`；`PDO::exec` 只執行遷移檔與固定 SQL |
| 超全域變數 | 只在 `Http\Request` 讀取；輸入一律轉為字串並移除 NUL |
| 機密 | 2FA 金鑰、SMTP 密碼、API 金鑰、Google Token 以 app_key 衍生子金鑰加密；救援 Token 只存 SHA-256；紀錄不含密碼、驗證碼、Token |

## 4. 本階段發現並修正的問題

| # | 問題 | 風險 | 修正 |
|---|---|---|---|
| 1 | `config.php` 未設定 `base_url` 時，救援信連結使用請求的 Host 標頭 | 中：攻擊者可讓救援信連結指向自己的網站，管理者點擊即洩漏 Token（Host header poisoning） | 未設定 `base_url` 時不寄救援信並記錄原因；帳號設定頁顯示警告 |
| 2 | 網址參數含 NUL 字元時，歷史紀錄頁發生 500 錯誤 | 低：只有管理者可進入，但屬未處理的例外 | `Request` 統一移除 NUL；日期先以格式檢查再解析 |
| 3 | 回應標頭 `X-Powered-By` 透露 PHP 版本 | 低：資訊洩漏 | 送出回應前移除 |
| 4 | JSON API 原樣回傳 `<script>` 等字元 | 低：已有 `application/json` 與 `nosniff`，不會被執行 | JSON 輸出把 `< > & ' "` 轉為 `<` 等（縱深防禦） |

## 4.1 上線後追加（v1.0.1）

- 系統錯誤紀錄：錯誤細節寫入 `storage/logs/`（Apache 實測 `/storage/` 一律 403），只記錄方法、路徑（不含查詢字串）、例外類型與訊息、專案內相對路徑的堆疊；只有最高權限管理者可在後台查詢，一般員工 404。訊息可能含資料庫錯誤描述，因此不對其他人開放；
- 關鍵字局部更新 API（`/api/projects/{id}/keywords`）：權限、CSRF、紀錄與原表單相同，已自動納入全站路由安全掃描；關鍵字以 textContent 寫入畫面，不使用 innerHTML。

## 5. 已知限制與殘餘風險

- **免費主機**：InfinityFree 無 SLA、無備份保證，條款禁止當作檔案儲存（附件建議改存 Google Drive，決議 #24、#33）；請定期以 phpMyAdmin 匯出資料庫備份；
- **歷史紀錄不是不可竄改的稽核證據**：擁有資料庫權限者可修改；
- **救援安全性等同救援信箱**：信箱必須開啟兩步驟驗證；
- **救援 Token 在網址中**：可能出現在主機存取記錄，只能用一次且 30 分鐘失效；
- **同 IP 計數**：登入與救援的 IP 限制以 `REMOTE_ADDR` 計算；InfinityFree 的代理架構下若所有使用者顯示相同 IP，IP 上限需調高（系統設定）；
- **沒有惡意檔案掃描**（決議 #26），以白名單、內容偵測、圖片重新編碼、禁止執行與強制下載防護；
- **未做的測試**：外部滲透測試、實體 iOS／Android 瀏覽器、正式環境的實際寄信與 Google Drive 連線。
