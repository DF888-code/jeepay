# 01 主機環境與限制檢查

> 狀態：**待實測**。本文件中關於 InfinityFree 的描述，來自其公開說明與社群經驗，主機政策可能隨時變更，
> 一律以 `tools/host-check.php` 在實際帳號上的檢查結果為準。實測後請把結果填入第 4 節。

## 1. 為什麼要先檢查

規格要求「不可假設 InfinityFree 支援所有 PHP 擴充套件、外部連線或排程功能」。
以下功能的設計方向直接取決於主機能力：

| 功能 | 依賴的主機能力 | 若不支援時的替代方案 |
|---|---|---|
| Google Authenticator（TOTP） | `hash_hmac`、`random_bytes` | 幾乎所有 PHP 8 主機皆具備，風險低 |
| TOTP Secret 加密保存 | `sodium` 擴充 | 改用 `openssl` AES-256-GCM |
| 密碼雜湊 | `PASSWORD_ARGON2ID` | 退回 `PASSWORD_BCRYPT`（cost ≥ 12） |
| 簡繁轉換（OpenCC） | 若用官方 C++ 版需 `exec` 或 PHP 擴充 | 免費主機通常不可用 → 採**純 PHP 實作 + OpenCC 詞典檔** |
| NFKC 標準化（全形／半形） | `intl`（`Normalizer`） | 自製全形轉半形對照表 |
| QR Code | 不需外部服務 | 以純 PHP（SVG 輸出）或前端本地 JS 產生；**禁止**把 Secret 送到第三方 QR 產生 API |
| 救援 Email | 對外 SMTP（465/587）或 HTTPS（443） | 見第 3 節 |
| PostgreSQL（Aiven） | `pdo_pgsql` + 對外連線到 Aiven 自訂 Port | 無法使用則採 MySQL／MariaDB |
| 紀錄保存期限清理 | 排程（Cron） | 免費方案通常**沒有 Cron** → 改為「管理者登入或開啟紀錄頁時分批清理」 |

## 2. InfinityFree 已知特性（需實測確認）

| 項目 | 目前資訊 | 對設計的影響 |
|---|---|---|
| PHP 版本 | 提供 PHP 8.x | 程式以 PHP 8.1 為最低需求，不使用更新版本專屬語法 |
| 資料庫 | 內建 MySQL／MariaDB；**不允許外部連入**資料庫 | 無法用本機工具直連，Schema 遷移需透過受保護的安裝頁執行 |
| SSH／Composer | 無 SSH，只能 FTP／檔案管理員上傳 | 相依套件需在本機 `composer install` 後連同 `vendor/` 上傳；需注意 inode（檔案數）上限，套件越少越好 |
| Cron | 免費方案無排程 | 不依賴排程；清理、過期處理採「請求觸發 + 分批」 |
| `mail()` | 不可依賴 | 規格本來就不使用主機自身郵件 |
| 對外連線 | 社群資料顯示部分 Port 可能受限 | **必須實測** SMTP 465/587 與 HTTPS 443 |
| 瀏覽器驗證機制 | 免費主機會對新訪客回傳一段 JS 驗證並設定 Cookie | 一般瀏覽器使用無影響；非瀏覽器的 API 用戶端（curl、腳本）會被擋，屬主機限制而非程式錯誤。另外，郵件掃描器多半不執行 JS，正好不會觸發救援頁 |
| 每日請求數、CPU、同時連線數 | 有上限，超過會暫停網站 | 隨手記分頁、搜尋避免一次撈大量資料；前端不做高頻輪詢 |
| HTTPS | 提供免費 SSL | Cookie 一律 `Secure`；強制 HTTPS 轉址 |
| `.htaccess` | 支援 | 用於拒絕存取 `app/`、`config/`、`storage/` 目錄與設定安全標頭 |
| 完整 DNS 管理 | 官方方案說明明列**不提供** | 若網域 DNS 交給 InfinityFree 管理，可能無法自行新增 SPF／DKIM／DMARC 所需的 TXT 紀錄。對策見第 3 節 |
| PHP 版本選擇 | 官方方案說明明列**不提供** | 只能使用主機給的版本，程式以 PHP 8.1 為最低需求，實際版本以探針結果為準 |
| Web 根目錄之外是否可放檔案 | 不確定 | 若不行，機密設定放在 Web 根目錄內並以 `.htaccess` 全面拒絕，同時檔名使用 `.php` 且內容以 `return [...]` 形式，即使被直接請求也不會輸出內容 |

**風險提醒**：免費主機不提供 SLA、備份保證與資源隔離保證。公司規章、升遷制度與隨手記（可能含 Wi-Fi 密碼）屬於內部敏感資料，
建議在正式導入前評估付費主機或至少建立定期匯出備份的流程。程式碼會保持主機無關，日後搬遷不需要重寫。

## 3. Email 對外連線評估流程

```
上傳 host-check.php → 執行對外連線測試
   ├─ SMTP 587 或 465 可連線 ──→ 第一階段採通用 SMTP（規格要求）
   ├─ SMTP 不通，HTTPS 443 可連線 ──→ 實作 HTTPS Email API Transport
   └─ 兩者皆不通 ──→ 重新評估主機（救援 Email 是最高權限管理者唯一自救管道）
```

郵件模組設計為介面 `MailTransport`，實作 `SmtpTransport`（第一階段）與 `HttpApiTransport`（預留），
供應商參數全部來自系統設定，不寫死 Gmail。

### 寄件網域與 DNS（InfinityFree 不提供完整 DNS 管理）

SPF／DKIM／DMARC 需要在網域 DNS 新增 TXT（或 CNAME）紀錄。可行做法，依建議順序：

1. **DNS 不交給 InfinityFree 管理**：網域的 DNS 放在註冊商或 Cloudflare，再把網站指向 InfinityFree，寄信驗證紀錄在原處設定（需確認 InfinityFree 是否接受此種指向方式）；
2. **寄件人使用郵件服務商驗證過的單一信箱**（例如以既有 Gmail 或公司信箱作為寄件人），不使用自有網域寄信，因此不需要 DNS 設定；救援信每月只有零星數封，可接受；
3. 寄件使用**另一個 DNS 可自行管理的網域**，與網站網域分開。

救援 Email 是低流量、高重要性的信件，第一階段建議採做法 2 或 1，避免被 DNS 限制卡住。

## 4. 資料庫選型評估

| 評估項目 | MySQL／MariaDB（InfinityFree 內建） | PostgreSQL（Aiven 免費方案） |
|---|---|---|
| 成本 | 免費，含在主機內 | 免費方案有規格與閒置限制，條款可能變動 |
| 主機相容性 | `pdo_mysql` 為標準配備 | 需 `pdo_pgsql`，**免費 PHP 主機常未安裝** |
| 網路 | 同機房、延遲低 | 需從 InfinityFree 對外連到 Aiven 的**非標準 Port**，可能被擋；且必須 SSL |
| 延遲 | 每次查詢約 1 ms 等級 | 跨區域每次查詢可能數十至上百 ms，每頁 5–10 次查詢會被放大 |
| 可用性 | 與網站同生共死 | 多一個外部依賴，任一方停止服務網站就無法使用 |
| 中文全文檢索 | MySQL 8 有 ngram，MariaDB 沒有 | 需額外擴充（如 `pg_bigm`、`zhparser`），託管服務不一定提供 |
| 功能 | 足以滿足本規格（交易、條件式 UPDATE、索引） | 功能更強（`RETURNING`、部分索引），但本規格不必要 |
| 備份 | 需自行匯出 | Aiven 提供自動備份 |

**建議**：第一階段採 **MySQL／MariaDB（InfinityFree 內建）**。

原因：

1. Aiven 方案能否運作取決於「`pdo_pgsql` 是否存在」與「對外 Port 是否開放」兩個在免費主機上高度不確定的條件；
2. 即使可連線，跨區延遲會直接拖慢每一頁；
3. 本規格需要的資料庫能力（交易、原子條件更新、索引、參數化查詢）MySQL／MariaDB 都具備。

為了保留未來換庫的彈性：

- 所有 SQL 透過 PDO 與 Repository 層集中管理；
- Schema 避免 `ON UPDATE CURRENT_TIMESTAMP`、`ENUM`、FULLTEXT ngram 等難以移植的特性；
- 搜尋使用應用層標準化欄位 + `LIKE`，不依賴特定資料庫的全文檢索。

**最終決定仍需等待 `host-check.php` 實測結果**（列於待確認清單）。若實測顯示 `pdo_pgsql` 可用、Aiven Port 可連且延遲可接受，可再討論。

## 5. 本機（開發容器）檢查結果

以下為開發環境執行 `php project-hub/tools/host-check.php` 的結果，**不代表 InfinityFree**：

| 項目 | 結果 |
|---|---|
| PHP | 8.3.6，所有必要與選用擴充皆存在，支援 Argon2id |
| MariaDB | 10.11.14（無 ngram 全文檢索，與上表判斷一致） |
| Schema 草案 | 可於 MariaDB 10.11 成功建立 12 張資料表 |
| 對外連線 | 開發容器無一般對外網路，SMTP／HTTPS 測試皆為 DNS 失敗（預期中，不具參考價值） |

## 6. InfinityFree 實測結果（待填寫）

| 項目 | 結果 | 測試日期 |
|---|---|---|
| PHP 版本 |  |  |
| 缺少的擴充套件 |  |  |
| 停用的函式 |  |  |
| Argon2id |  |  |
| SMTP 587 |  |  |
| SMTP 465 |  |  |
| HTTPS 443（Email API） |  |  |
| Aiven PostgreSQL Port |  |  |
| MySQL 版本／是否 MariaDB |  |  |
| 上層目錄是否可寫 |  |  |

### 執行方式

1. 修改 `tools/host-check.php` 頂端的 `ACCESS_KEY`（至少 32 字元隨機字串）與 `$TARGETS` 白名單（填入 Aiven 主機與 Port、預計使用的郵件服務主機）；
2. 以 FTP 上傳至 `htdocs/`，用瀏覽器開啟 `https://你的網域/host-check.php?key=ACCESS_KEY`；
3. 依序執行「對外連線測試」與「資料庫連線測試」；
4. 記錄結果後**立即刪除**該檔案。
