-- =====================================================================
-- 遷移 0005：隨手記修改／刪除紀錄（使用者要求保存修改前後的內容，取代決議 #11 的原做法）
--
-- 每次修改保存「修改前」與「修改後」的完整內容；刪除時保存刪除當下的內容。
-- 只新增資料表，不修改或刪除既有資料。此功能上線前的修改沒有保存內容。
-- =====================================================================

CREATE TABLE note_revisions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note_id         BIGINT UNSIGNED NOT NULL,
    project_id      INT UNSIGNED    NOT NULL,
    action          VARCHAR(10)     NOT NULL COMMENT 'update 或 delete',
    content_before  MEDIUMTEXT      NOT NULL,
    content_after   MEDIUMTEXT      NULL COMMENT '刪除時為 NULL',
    changed_by      INT UNSIGNED    NOT NULL,
    changed_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_nr_project (project_id, id),
    KEY idx_nr_note (note_id, id),
    CONSTRAINT fk_nr_note FOREIGN KEY (note_id) REFERENCES project_notes (id),
    CONSTRAINT fk_nr_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_nr_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
