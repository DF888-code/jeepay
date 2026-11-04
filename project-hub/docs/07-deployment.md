# 07 InfinityFree 部署與維運

## 1. 第一次部署

### 1.1 建立資料庫

1. InfinityFree 控制台 → **MySQL Databases** → 建立一個資料庫（名稱例如 `projecthub`）；
2. 記下頁面上的 **MySQL Host**、**Database Name**、**Username**、**Password**、Port（3306）。

### 1.2 上傳程式

1. 取得部署包 `project-hub-htdocs-v{版本}-{日期}.zip`（開發端執行 `composer install --no-dev && php tools/build-deploy.php` 產生於 `dist/`；版本號見 `CHANGELOG.md`，登入後網站頁尾也會顯示目前版本）；
2. File Manager 進入 `htdocs`，使用 **Upload & Unzip** 上傳 zip；
3. 確認 `htdocs` 底下出現 `index.php`、`.htaccess`、`assets/`、`app/`、`config/`、`database/`、`storage/`、`vendor/`、`views/`。

> 部署包不含 `config.php`、測試與文件。`app/` 等私有目錄放在 htdocs 內，是因為主機實測上層目錄不可寫；
> 每個私有目錄都有 `.htaccess` 拒絕存取，根目錄 `.htaccess` 也會再擋一次（見第 4 節驗證結果）。

### 1.3 建立設定檔

1. 用瀏覽器開啟網站：會顯示「尚未完成設定」頁，並提供兩組隨機值（`app_key`、`install_token`）可直接複製；
2. File Manager 中把 `config/config.example.php` 複製為 `config/config.php`，填入：
   - `app_key`、`install_token`：貼上剛剛複製的隨機值；
   - `db`：1.1 記下的資料庫資料；
   - `base_url`：`https://book888.free.je`。
3. **`app_key` 請另外安全保存**（例如密碼管理器）。遺失或更改後，所有人的 2FA 都無法解密，必須重新綁定。

### 1.4 安裝

1. 開啟 `https://你的網域/install`；
2. 輸入 `install_token`、管理者帳號、顯示名稱、救援 Email、密碼；
3. 完成後導向登入頁，登入後會要求掃描 QR Code 綁定 Google Authenticator；
4. 安裝完成後可把 `config.php` 的 `install_token` 清空（安裝頁已永久停用，留著也無法再使用）。

## 2. 更新程式

1. 建議先用 phpMyAdmin 匯出資料庫備份；
2. 產生新的部署包，以 Upload & Unzip 覆蓋；**不會覆蓋** `config/config.php` 與 `storage/` 中的資料（部署包內沒有這些檔案）；
3. 以最高權限管理者登入並進入「管理後台」：若有待套用的資料庫更新，系統會自動導向「資料庫更新」頁，按「套用資料庫更新」即可（只做新增型變更）。

## 2.1 設定 Google Drive 附件儲存（選用，v0.9.0 起）

1. 到 Google Cloud Console 建立專案，啟用「Google Drive API」；
2. 「OAuth 同意畫面」選「外部」，加入範圍 `https://www.googleapis.com/auth/drive.file`；完成後按「發布應用程式」（停在「測試中」時授權 7 天就會失效）；
3. 「憑證 → OAuth 用戶端 ID → 網頁應用程式」，「已授權的重新導向 URI」填入管理後台「系統設定 → 附件儲存位置」頁面顯示的網址（例如 `https://book888.free.je/admin/settings/storage/google/callback`），`config.php` 的 `base_url` 必須正確；
4. 在「附件儲存位置」頁填入用戶端 ID 與密鑰並儲存，按「連結 Google 帳號」並同意；
5. 確認顯示「已連結」後，把「新上傳附件的儲存位置」改為 Google Drive；
6. 驗收：上傳一張圖片與一個 PDF，確認可預覽與下載；到 Google 雲端硬碟確認資料夾「專案管理平台附件（請勿手動修改）」內出現隨機檔名的檔案；用沒有專案權限的帳號開啟附件網址應為「找不到頁面」。

注意：連結的 Google 帳號就是附件的實際存放處，請使用公司可長期保管的帳號，不要使用個人帳號；不要在雲端硬碟手動移動、改名或刪除該資料夾內的檔案。

## 2.1b 設定 Cloudflare R2 附件儲存（選用，v1.1.0 起）

1. Cloudflare 後台 → R2 物件儲存，第一次使用先啟用 R2；
2. 建立 bucket（例如 `project-hub-attachments`），**不要開啟公開存取或 r2.dev 網址**；
3. 進入 bucket →「設定」，複製「S3 API」網址（例如 `https://帳戶ID.r2.cloudflarestorage.com/bucket名稱`）；歐盟儲存區的 bucket 網址為 `…eu.r2.cloudflarestorage.com`，只能從該端點存取，請照實貼上；
4. 「管理 API 權杖」→ 建立 API 權杖：權限「物件讀取和寫入」、範圍「僅套用至特定 bucket」，複製 Access Key ID 與 Secret Access Key（Secret 只顯示一次）；
5. 管理後台「系統設定 → 附件儲存位置 → Cloudflare R2」把 S3 API 網址貼到「S3 API 端點」（結尾帶 bucket 名稱會自動拆開），填入兩把金鑰並儲存，按「測試連線」；
6. 成功後把「新上傳附件的儲存位置」改為 Cloudflare R2；
7. 驗收：上傳圖片與 PDF，確認可預覽與下載；在 Cloudflare 後台的 bucket 中會看到 64 碼隨機名稱的物件。

已有附件存在 R2 時，系統不允許更換端點或 bucket（舊檔會讀不到）。端點只接受 Cloudflare R2 官方網址格式（一般、`eu`、`fedramp` 儲存區），避免伺服器被設定去連線其他網址。

## 2.2 設定寄信（v0.9.0 起）

「系統設定 → 寄信設定」：以 Brevo 為例，SMTP 主機 `smtp-relay.brevo.com`、連接埠 587、STARTTLS，帳號與密碼為 Brevo 後台「SMTP & API」頁的 SMTP 登入資訊；SMTP 不通時改用「Brevo HTTPS API」並填入 API 金鑰。寄件者 Email 必須是 Brevo 已驗證的寄件地址。儲存後依序按「測試連線」與「寄送測試信」。

## 2.3 Email 救援（v0.10.0 起）

最高權限管理者忘記密碼或遺失驗證器時，開啟 `https://你的網域/recovery`（建議存成書籤）。登入頁平常不顯示此入口；任何帳號輸入錯誤密碼或驗證碼後，才會出現中性的「申請帳號協助」連結（決議 #37）。前提：已在「帳號設定 → Email 救援設定」設定救援 Email，且寄信設定可用。建議上線後實際演練一次「忘記密碼」流程。

## 2.4 畫面出現「系統發生錯誤」時（v1.0.1 起）

錯誤頁會顯示 12 碼的追蹤代碼。以最高權限管理者登入「管理後台 → 系統錯誤」，輸入追蹤代碼即可看到錯誤類型、訊息與發生位置；回報問題時請提供這些內容（截圖即可）。紀錄存放在 `storage/logs/`（無法從網址直接讀取），只保留最近 3 個月。

## 3. 緊急情況：Email 救援無法使用時（寄信失敗、救援信箱也遺失）

平時請使用 Email 救援（第 2.3 節）。只有在寄信無法使用、或救援信箱也無法存取時，才透過**主機控制台的 phpMyAdmin** 重設 2FA：

```sql
UPDATE users
   SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_used_step = NULL,
       totp_pending_secret_enc = NULL, totp_pending_expires_at = NULL,
       must_enroll_2fa = 1, auth_version = auth_version + 1
 WHERE username = '你的管理者帳號';
```

執行後以密碼登入，系統會要求重新綁定 2FA。此方法需要 InfinityFree 帳號的控制台權限，因此安全性等同於主機帳號本身；
請務必替 InfinityFree 帳號設定強密碼。完成後建議到「歷史操作紀錄」確認沒有異常登入（階段 8 上線後）。

> 原規劃為「本機 CLI 緊急指令」，但 InfinityFree 沒有命令列環境，因此改為 phpMyAdmin 方式。

## 4. 部署安全檢查清單

| 項目 | 檢查方式 | 預期 |
|---|---|---|
| 設定檔不可被讀取 | 瀏覽 `/config/config.php` | 403 或 404（絕不可為 200） |
| 私有目錄不可瀏覽 | 瀏覽 `/app/`、`/vendor/autoload.php`、`/storage/`、`/database/migrations/0001_initial_schema.sql` | 403 或 404 |
| 安裝頁已停用 | 瀏覽 `/install` | 404 |
| HTTP 轉 HTTPS | 瀏覽 `http://你的網域/login` | 轉址到 https |
| 探針已刪除 | 瀏覽 `/host-check.php` | 403 或 404 |
| `base_url` 正確 | 「帳號設定 → Email 救援設定」沒有 base_url 警告 | 無警告 |
| 錯誤不外洩 | 瀏覽不存在的網址 | 只顯示「找不到頁面」，沒有路徑或 PHP 錯誤 |
| 不透露版本 | 瀏覽器開發者工具查看回應標頭 | 沒有 `X-Powered-By` |
| Cookie 安全 | 開發者工具 → Application → Cookies | `phsid` 有 HttpOnly、Secure、SameSite=Lax |
| 2FA 強制 | 新建員工帳號第一次登入 | 必須先綁定 Google Authenticator |
| 寄信可用 | 「系統設定 → 寄信設定」寄送測試信 | 收到測試信 |
| Email 救援演練 | 用「忘記密碼」流程完整走一次 | 收到救援信、可重設密碼 |
| 備份 | phpMyAdmin 匯出資料庫 | 取得 .sql 檔並妥善保存 |

### 正式上線（v1.0.0）步驟摘要

1. 以 phpMyAdmin 匯出既有資料庫備份；
2. 上傳 `project-hub-htdocs-v1.0.0-{日期}.zip` 並解壓覆蓋 htdocs（不會覆蓋 `config/config.php` 與 `storage/`）；
3. 以最高權限管理者登入，若被導向「資料庫更新」頁就按「套用資料庫更新」；
4. 確認 `config.php` 的 `base_url` 是 `https://` 開頭的正式網址、`force_https` 為 `true`、`install_token` 已清空；
5. 完成「寄信設定」與「Email 救援設定」，演練一次救援；
6. （選用）完成 Google Drive 連結，把新附件改存 Google Drive；
7. 逐項完成上表檢查，並在「歷史操作紀錄」確認沒有異常。

### 開發環境 Apache 驗證結果（2026-11-02，v1.0.0 部署包）

27 個路徑實測：`/config/config.php`、`/config/config.example.php`、`/app/App.php`、`/vendor/autoload.php`、`/vendor/composer/installed.json`、
`/database/migrations/0004_settings_center.sql`、`/storage/`、`/storage/installed.lock`、`/storage/sessions/`、`/storage/tmp/`、`/views/layout.php`、`/.htaccess`、
上傳目錄中的 `.php` 檔、`/index.php/../config/config.php`、`/assets/../config/config.php`、`/app/../config/config.php`：**403**；
`/VERSION`、`/composer.json`、`/tools/`、`/tests/`、`/docs/`、大小寫變形：**404**；`/%2e%2e/config/config.php`：400；安裝後 `/install`：404。
安裝 → 登入 → 2FA 綁定流程正常，Session 寫入 `storage/sessions/`，回應沒有 `X-Powered-By`。

### 開發環境 Apache 驗證結果（2026-10-30）

以 Apache 2.4 + mod_php 8.3、`AllowOverride All`，載入部署包解壓後的 htdocs 實測：

- `/config/config.php`、`/config/config.example.php`、`/app/App.php`、`/vendor/autoload.php`、`/vendor/composer/installed.json`、
  `/database/migrations/0001_initial_schema.sql`、`/storage/`、`/storage/installed.lock`、`/storage/sessions/`、`/views/layout.php`、`/.htaccess`：全部 **403**；
- 大小寫變形（`/APP/App.php`、`/Config/config.php`）：404；`/assets/` 不列目錄（404）；
- 安裝 → 登入 → 2FA 綁定頁完整流程正常；安裝後 `/install` 回 404；Session 檔寫入 `storage/sessions/`。

InfinityFree 上線後仍須依上表再檢查一次（主機的 Apache 設定可能與開發環境不同）。

### InfinityFree 正式環境驗證結果（2026-11-02，book888.free.je）

- 部署包以 Upload & Unzip 上傳成功，PHP 8.4 下正常執行；
- 設定說明頁 → 建立 `config.php` → 安裝 → 登入 → 2FA 綁定，流程完成；
- `/config/config.php`：**403**；`/install`：**404**（安裝後停用）；
- `http://…/login`：自動轉址到 https（已登入狀態再導向首頁）。
