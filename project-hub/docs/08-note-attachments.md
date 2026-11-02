# 08 隨手記檔案與圖片上傳（規格七之一，2026-11-02 新增）

> 狀態：**設計中，排入階段 6 實作**（需先完成階段 4 權限管理、階段 5 專案與專案權限）。
> 儲存位置已決定（2026-11-02，待確認事項 #24）：先用本機儲存加容量上限，階段 6 上線時實測後再決定是否改用 R2 或付費主機。

## 1. 主機限制查證

| 項目 | 資料 | 來源 | 影響 |
|---|---|---|---|
| 單一檔案大小 | **10 MB**，超過會被主機自動刪除，免費方案不可調整 | InfinityFree 論壇（含官方人員回覆） | 單檔上限預設 10 MB |
| 檔案數（inode） | 約 **30,000** 個（檔案與資料夾合計） | 同上 | 程式本身約 150 個；附件、預覽圖都會占用 |
| 總空間 | 約 5 GB（說法不一） | 同上 | 需設定總容量上限 |
| 使用條款 | **禁止**任何形式的檔案託管（檔案分享、媒體託管、內容散佈、備份儲存），違規可能停權 | InfinityFree 官方人員引用服務條款第 5 節 | 大量附件存放在免費主機有停權風險 |
| PHP 上傳限制 | `upload_max_filesize` 20M、`post_max_size` 30M、執行時間 60 秒 | 2026-10-30 探針實測 | 每次請求只傳一個檔案，避免逾時 |
| 惡意檔案掃描 | 無 ClamAV、`exec` 被停用，無法在主機上掃毒 | 探針實測 | 見第 5.3 節 |

> 以上主機資料來自 InfinityFree 社群論壇，並非正式文件；正式導入前應以服務條款原文（infinityfree.net/terms）確認。

## 2. 儲存方案

| 方案 | 優點 | 缺點 |
|---|---|---|
| A. 存在 InfinityFree 主機上 | 最簡單、不需其他帳號 | **可能違反使用條款**；10 MB 限制；占用 inode；無備份 |
| **B. 外部物件儲存（建議：Cloudflare R2）** | 免費額度約 10 GB、下載流量免費；S3 相容 API 走 HTTPS 443（主機實測可連線）；不占主機空間與 inode | 需申請帳號；每次存取需經主機轉送或簽名網址（見下） |
| C. 改用付費主機 | 沒有條款疑慮、限制寬鬆，其他功能也受益 | 有費用、需要搬遷 |

**建議**：程式以 `FileStorage` 介面實作，同時提供「本機」與「S3 相容（R2）」兩種儲存方式，由系統設定切換。

**決議（2026-11-02）**：

1. 先採用 A，加上總容量上限（預設 500 MB）與檔案數上限，降低被認定為檔案儲存用途的風險；
2. 檔案以無副檔名的隨機名稱儲存。論壇上有使用者提到主機會封鎖 `.pdf`、`.exe` 等檔案類型，而主機若改以檔案內容判斷，仍可能標記這些檔案；
3. 階段 6 上線時，先實際上傳 PDF、Office、ZIP 測試是否會被主機刪除或標記，再決定是否改用 B 或 C；
4. 不要等違規後才升級：帳號被刪除時資料可能無法恢復。在此之前應定期匯出附件備份（規劃「匯出專案附件」功能）。

> 付費升級查證（2026-11-02）：InfinityFree 的付費方案由 iFastNet 提供，論壇使用者表示檔案數上限較寬鬆，
> 但**找不到**官方文件說明付費方案是否允許存放附件，或是否解除 10 MB 限制。若要改用付費主機，請先確認條款、單檔上限與備份機制，不限於 iFastNet。

### 外部儲存的權限控制方式

規格要求「撤銷權限後立即停止提供存取」，因此**不使用公開網址**：

| 方式 | 說明 | 是否符合「立即撤銷」 |
|---|---|---|
| **PHP 代理（建議）** | 每次讀取圖片或下載附件都先經伺服器做雙重授權檢查，再由 PHP 從 R2 取檔回傳 | 完全符合 |
| 短效簽名網址 | 檢查權限後轉址到有效期 60 秒的簽名網址 | 已發出的網址在 60 秒內仍可用，不完全符合 |

為了降低代理的流量與執行時間，圖片上傳時另外產生壓縮預覽圖（長邊 1600px），頁面顯示預覽圖，點擊放大或下載時才取原檔。

## 3. 功能設計

### 3.1 上傳流程

```
使用者拖拉或選擇檔案（可多選；手機用檔案／相簿選取）
  → 前端逐一上傳：每個檔案一個請求，顯示進度（避免 30 MB／60 秒限制）
      POST /api/projects/{id}/attachments    （需雙重授權「編輯」＋ CSRF）
      伺服器檢查 → 存成「暫存附件」（尚未屬於任何隨手記，只有上傳者本人可見）
  → 使用者按「儲存隨手記」
      伺服器確認每個暫存附件：屬於本人、屬於同一專案、尚未過期 → 綁定到該隨手記
  → 未綁定的暫存附件 24 小時後由請求觸發的清理程序刪除（主機沒有 Cron）
```

### 3.2 顯示

- 文字內容照原樣顯示，圖片依上傳順序顯示在文字下方（與規格範例一致）；
- 圖片使用 `img-fluid` 依寬度縮放，不會撐破 RWD 版面；多張圖片在桌機以格狀排列，手機改為單欄；
- 點擊圖片以 Bootstrap Modal 放大檢視（不用外部燈箱套件）；
- 一般附件以清單顯示：圖示、檔名、格式、大小、下載按鈕；手機改為卡片樣式。

### 3.3 權限（沿用雙重授權，與待確認事項 #10 一致）

| 操作 | 需要的權限（全域 ∧ 專案） |
|---|---|
| 檢視圖片、預覽圖、下載附件、附件 API | 查看 |
| 上傳附件、在自己的隨手記新增附件 | 查看 ＋ 編輯 |
| 刪除自己隨手記上的附件 | 查看 ＋ 編輯 |
| 刪除他人隨手記上的附件 | 查看 ＋ 刪除 |

- 圖片與下載網址只含附件 ID，例如 `/attachments/123/preview`、`/attachments/123/download`；**每次請求都重新做雙重授權**，不使用公開或可猜測的實體路徑；
- 未授權、附件不存在、隨手記或專案已刪除，一律回應相同的 404；
- 回應帶 `Cache-Control: private, no-store`，避免撤銷權限後仍從共用快取取得。

## 4. 資料庫設計（遷移 0002，階段 6 建立）

```sql
CREATE TABLE note_attachments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id          INT UNSIGNED    NOT NULL,
    note_id             BIGINT UNSIGNED NULL COMMENT '暫存附件為 NULL',
    uploader_id         INT UNSIGNED    NOT NULL,
    original_name       VARCHAR(255)    NOT NULL COMMENT '原始檔名，只用於顯示與下載檔名',
    original_name_norm  VARCHAR(255)    NOT NULL COLLATE utf8mb4_bin COMMENT '供日後擴充附件檔名搜尋',
    kind                VARCHAR(10)     NOT NULL COMMENT 'image / file',
    mime_type           VARCHAR(100)    NOT NULL COMMENT '伺服器偵測到的實際 MIME',
    size_bytes          INT UNSIGNED    NOT NULL,
    sha256              BINARY(32)      NOT NULL,
    storage_driver      VARCHAR(20)     NOT NULL COMMENT 'local / s3',
    stored_name         CHAR(64)        NOT NULL COMMENT '隨機名稱（無副檔名），與原始檔名無關',
    preview_stored_name CHAR(64)        NULL COMMENT '圖片預覽圖',
    image_width         INT UNSIGNED    NULL,
    image_height        INT UNSIGNED    NULL,
    status              VARCHAR(10)     NOT NULL DEFAULT 'pending' COMMENT 'pending / attached / deleted',
    created_at          DATETIME        NOT NULL,
    attached_at         DATETIME        NULL,
    deleted_at          DATETIME        NULL,
    deleted_by          INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_na_stored (stored_name),
    KEY idx_na_note (note_id, status),
    KEY idx_na_project (project_id, status),
    KEY idx_na_pending (status, created_at)
);
```

規格要求的欄位對應：檔案 ID＝`id`、所屬專案＝`project_id`、所屬隨手記＝`note_id`、原始檔名、實際儲存名稱＝`stored_name`、MIME、大小、上傳者、上傳時間＝`created_at`、儲存位置＝`storage_driver` ＋ `stored_name`。

## 5. 檔案安全

### 5.1 上傳檢查（依序，任一失敗即拒絕並刪除暫存檔）

1. 雙重授權「編輯」＋ CSRF；檢查單檔大小、單次數量、每則隨手記附件數、總容量上限；
2. 以 `is_uploaded_file()` 確認來源，**以 `finfo` 偵測實際 MIME**，副檔名只作為輔助比對，兩者必須一致且都在白名單內；
3. 圖片：用 GD 實際解碼，成功才接受；JPG／PNG／WebP 一律**重新編碼**（去除 EXIF／GPS 位置資訊，並破壞夾帶程式碼的偽裝檔）；
4. 檔名只保留供顯示，移除路徑字元、控制字元，長度上限 200 字；
5. 儲存名稱使用 `random_bytes(32)` 的十六進位字串，不含副檔名，不使用原始檔名，因此不可能發生路徑穿越。

### 5.2 白名單（預設，可於系統設定縮減）

| 類別 | 允許 | 偵測到的 MIME |
|---|---|---|
| 圖片 | JPG、PNG、WebP、GIF | image/jpeg、image/png、image/webp、image/gif |
| 文件 | PDF、DOCX、XLSX、PPTX、DOC、XLS、PPT | application/pdf、Office Open XML 系列、舊版 Office（CDF） |
| 文字 | TXT、CSV、LOG、MD | text/plain、text/csv |
| 壓縮檔 | ZIP | application/zip |

**一律拒絕**：PHP 等伺服器端程式、HTML、SVG（可夾帶 JavaScript）、JS、EXE、BAT、SH、含巨集的 Office 檔（DOCM、XLSM、PPTM）等。

### 5.3 不讓檔案被執行

- 本機儲存目錄位於 `storage/uploads/`，`.htaccess` 全面拒絕存取，並關閉 PHP 執行（`php_flag engine off`，雙重保險）；
- 所有檔案都只能透過 PHP 控制器讀出：
  - 一般附件回傳 `Content-Disposition: attachment`；
  - 圖片使用資料庫記錄的 MIME 回傳；
  - 一律加上 `X-Content-Type-Options: nosniff` 與 `Content-Security-Policy: default-src 'none'`；
- 原始檔名在 `Content-Disposition` 中以 RFC 5987 編碼，避免標頭注入。

### 5.4 惡意檔案掃描

主機無法執行掃毒程式。可選的外部掃描服務（例如 VirusTotal）會把公司檔案送到第三方，與內部文件的保密需求衝突，**預設不啟用**。

目前的防護是：白名單、實際 MIME 偵測、圖片重新編碼、不可執行、強制下載。這些措施無法偵測 PDF 或 Office 檔內的惡意內容，文件會列為已知限制。日後若改用付費主機，可加入 ClamAV。

## 6. 系統設定（新增「附件」分類）

| 設定 | 預設 | 範圍 |
|---|---|---|
| 單一檔案上限 | 10 MB | 1–10 MB（受主機限制） |
| 單次上傳數量 | 10 | 1–20 |
| 每則隨手記附件上限 | 20 | 1–50 |
| 總容量上限 | 500 MB（本機）| 本機最多 2 GB；S3 依方案 |
| 儲存方式 | 本機 | 本機／S3 相容 |
| 允許的檔案類型 | 第 5.2 節全部 | 只能從白名單中選擇 |

## 7. 搜尋與歷史紀錄

- 隨手記文字的搜尋規則不變；附件檔名先存標準化欄位，日後可加入搜尋（加入時同樣在 SQL 中套用權限條件）；
- 歷史操作紀錄新增：`attachment.upload`、`attachment.download`（含圖片放大檢視原圖；預覽圖不逐張記錄，以免紀錄爆量）、`attachment.delete`、`attachment.denied`；
- 紀錄只包含附件 ID、檔名與大小，不含檔案內容。

## 8. 測試計畫（階段 6 驗收）

- 雙重授權四種組合 × 上傳、預覽、下載、刪除；
- 撤銷專案權限後，原本可開啟的圖片與下載網址立即回 404；
- 直接猜測或修改附件 ID、存取其他專案的附件；
- 以 `.jpg` 副檔名包裝的 PHP、HTML、SVG 被拒絕；含 EXIF 的照片上傳後 EXIF 已移除；
- 超過大小或數量上限、總容量已滿；
- 他人的暫存附件不能被綁定到自己的隨手記；
- 直接請求 `storage/uploads/` 實體路徑被 Apache 拒絕；
- 手機與桌機的拖拉、選擇檔案、圖片放大、附件清單（Playwright 截圖）；
- **InfinityFree 實測**：上傳 PDF、DOCX、XLSX、ZIP、圖片後，確認檔案未被主機刪除或標記（決議 #24 的判斷依據）。
