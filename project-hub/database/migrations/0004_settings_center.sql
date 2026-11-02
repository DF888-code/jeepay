-- =====================================================================
-- 遷移 0004：系統設定中心（階段 9）
--
-- 1. secret_settings：寄信密碼、API 金鑰、Google 授權 Token 等機密設定。
--    以 app_key 衍生的子金鑰加密後才寫入，資料庫被整份匯出也無法直接讀出。
-- 2. note_attachments 增加外部儲存的檔案代號（Google Drive 的檔案 ID）。
--    本機檔案維持 NULL（沿用 stored_name），因此不需要改寫既有資料。
-- 只新增資料表與欄位，不修改或刪除既有資料。
-- =====================================================================

CREATE TABLE secret_settings (
    setting_key     VARCHAR(100)    NOT NULL COMMENT '例如 mail.smtp_password',
    value_enc       BLOB            NOT NULL COMMENT 'nonce + secretbox 密文',
    updated_by      INT UNSIGNED    NULL,
    updated_at      DATETIME        NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE note_attachments
    ADD COLUMN storage_ref VARCHAR(255) NULL COMMENT '外部儲存的檔案代號（本機為 NULL）' AFTER stored_name,
    ADD COLUMN preview_storage_ref VARCHAR(255) NULL COMMENT '預覽圖在外部儲存的檔案代號' AFTER preview_stored_name,
    ADD KEY idx_na_driver (storage_driver, status);
