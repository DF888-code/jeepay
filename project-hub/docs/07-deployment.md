# 07 InfinityFree 部署與維運

## 1. 第一次部署

### 1.1 建立資料庫

1. InfinityFree 控制台 → **MySQL Databases** → 建立一個資料庫（名稱例如 `projecthub`）；
2. 記下頁面上的 **MySQL Host**、**Database Name**、**Username**、**Password**、Port（3306）。

### 1.2 上傳程式

1. 取得部署包 `project-hub-htdocs.zip`（開發端執行 `composer install --no-dev && php tools/build-deploy.php` 產生於 `dist/`）；
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

1. 產生新的部署包，以 Upload & Unzip 覆蓋；**不會覆蓋** `config/config.php` 與 `storage/` 中的資料（部署包內沒有這些檔案）；
2. 若新版本含資料庫遷移，會在對應階段提供後台遷移功能（目前只有 0001 初始遷移，由安裝流程執行）。

## 3. 緊急情況：最高權限管理者遺失驗證器（Email 救援上線前）

Email 救援功能在階段 10 才完成。在那之前若最高權限管理者遺失手機，可透過**主機控制台的 phpMyAdmin** 重設：

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
| 探針已刪除 | 瀏覽 `/host-check.php` | 404 |

### 開發環境 Apache 驗證結果（2026-10-30）

以 Apache 2.4 + mod_php 8.3、`AllowOverride All`，載入部署包解壓後的 htdocs 實測：

- `/config/config.php`、`/config/config.example.php`、`/app/App.php`、`/vendor/autoload.php`、`/vendor/composer/installed.json`、
  `/database/migrations/0001_initial_schema.sql`、`/storage/`、`/storage/installed.lock`、`/storage/sessions/`、`/views/layout.php`、`/.htaccess`：全部 **403**；
- 大小寫變形（`/APP/App.php`、`/Config/config.php`）：404；`/assets/` 不列目錄（404）；
- 安裝 → 登入 → 2FA 綁定頁完整流程正常；安裝後 `/install` 回 404；Session 檔寫入 `storage/sessions/`。

InfinityFree 上線後仍須依上表再檢查一次（主機的 Apache 設定可能與開發環境不同）。
