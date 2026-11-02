-- =====================================================================
-- 遷移 0003：隨手記檔案與圖片附件（規格七之一，設計見 docs/08-note-attachments.md）
--
-- 檔案本身不存資料庫，只存中繼資料；實體檔案以隨機名稱（無副檔名）存放於 storage/uploads。
-- 只新增資料表，不修改或刪除既有資料。
-- =====================================================================

CREATE TABLE note_attachments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id          INT UNSIGNED    NOT NULL,
    note_id             BIGINT UNSIGNED NULL COMMENT '暫存附件（尚未儲存隨手記）為 NULL',
    uploader_id         INT UNSIGNED    NOT NULL,
    original_name       VARCHAR(255)    NOT NULL COMMENT '原始檔名，只用於顯示與下載檔名',
    original_name_norm  VARCHAR(255)    NOT NULL COLLATE utf8mb4_bin COMMENT '供日後擴充附件檔名搜尋',
    kind                VARCHAR(10)     NOT NULL COMMENT 'image 或 file',
    mime_type           VARCHAR(100)    NOT NULL COMMENT '伺服器偵測到的實際 MIME',
    size_bytes          INT UNSIGNED    NOT NULL,
    sha256              BINARY(32)      NOT NULL,
    storage_driver      VARCHAR(20)     NOT NULL COMMENT 'local 或 s3',
    stored_name         CHAR(64)        NOT NULL COMMENT '隨機名稱（無副檔名），與原始檔名無關',
    preview_stored_name CHAR(64)        NULL COMMENT '圖片預覽圖的儲存名稱',
    preview_mime_type   VARCHAR(100)    NULL,
    image_width         INT UNSIGNED    NULL,
    image_height        INT UNSIGNED    NULL,
    status              VARCHAR(10)     NOT NULL DEFAULT 'pending' COMMENT 'pending、attached 或 deleted',
    created_at          DATETIME        NOT NULL,
    attached_at         DATETIME        NULL,
    deleted_at          DATETIME        NULL,
    deleted_by          INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_na_stored (stored_name),
    KEY idx_na_note (note_id, status),
    KEY idx_na_project (project_id, status),
    KEY idx_na_pending (status, created_at),
    CONSTRAINT fk_na_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_na_uploader FOREIGN KEY (uploader_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
