-- =====================================================================
-- 專案管理與知識紀錄平台（Project Hub）資料庫 Schema：初始遷移 0001
-- 目標：MySQL 8.0+ / MariaDB 10.6+（InnoDB, utf8mb4）
--
-- 狀態：已定案（2026-10-30）。資料庫依主機實測定為 MySQL／MariaDB。
--   * 已發佈的遷移檔不可再修改；之後的結構變更一律新增 0002、0003… 遷移檔。
--   * 本檔刻意只使用 MySQL 與 MariaDB 共通語法，並避免 PostgreSQL 難以對應的特性
--     （例如 ON UPDATE CURRENT_TIMESTAMP、FULLTEXT ngram），以降低日後換庫成本。
--
-- 共通約定：
--   * 所有時間欄位一律存 UTC（DATETIME，不使用 TIMESTAMP，避免 session time_zone 影響與 2038 問題），
--     由應用程式依「系統設定 > 時區」轉換顯示。
--   * *_norm 欄位是「搜尋標準化」結果（NFKC → 小寫 → OpenCC 繁轉簡），只用於比對，
--     原文一律保存在對應的原始欄位，不可被覆寫。
--   * *_norm 欄位使用 utf8mb4_bin：標準化已處理大小寫，使用二進位比對可避免 collation
--     自行把不同字視為相同，造成唯一鍵或搜尋行為難以預期。
--   * 不使用外鍵的 ON DELETE CASCADE 刪除業務資料：任何刪除都必須經過應用層權限檢查與紀錄。
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 使用者
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username                VARCHAR(50)     NOT NULL COMMENT '登入帳號（不分大小寫唯一，見 username_norm）',
    username_norm           VARCHAR(50)     NOT NULL COMMENT '小寫化帳號，用於唯一性與登入比對',
    display_name            VARCHAR(100)    NOT NULL,
    email                   VARCHAR(254)    NULL,
    password_hash           VARCHAR(255)    NOT NULL COMMENT 'password_hash() 結果，優先 Argon2id，主機不支援時退回 bcrypt',
    is_super_admin          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '最高權限管理者旗標；不是職級角色',
    status                  VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active / disabled',

    -- 2FA（TOTP）
    totp_secret_enc         VARBINARY(255)  NULL COMMENT '以伺服器金鑰（libsodium secretbox）加密後的 TOTP Secret；NULL 表示未綁定',
    totp_enabled            TINYINT(1)      NOT NULL DEFAULT 0,
    totp_last_used_step     BIGINT          NULL COMMENT '最後一次成功使用的 TOTP 時間步，防止同一組驗證碼重放',
    totp_pending_secret_enc VARBINARY(255)  NULL COMMENT '綁定／更換流程中尚未確認的新 Secret',
    totp_pending_expires_at DATETIME        NULL,
    must_enroll_2fa         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = 下次登入必須完成 2FA 綁定才可使用一般功能',

    -- Session 失效控制：改密碼、重設 2FA、停用帳號、救援完成時 +1，舊 Session 立即失效
    auth_version            INT UNSIGNED    NOT NULL DEFAULT 1,

    last_login_at           DATETIME        NULL,
    created_at              DATETIME        NOT NULL,
    created_by              INT UNSIGNED    NULL,
    updated_at              DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_username_norm (username_norm),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第一層：全域使用者權限（每位使用者一列，欄位互相獨立）
-- 最高權限管理者不依賴此表（由 users.is_super_admin 判斷），但仍可有資料列。
-- ---------------------------------------------------------------------
CREATE TABLE user_global_permissions (
    user_id             INT UNSIGNED    NOT NULL,
    can_view_projects   TINYINT(1)      NOT NULL DEFAULT 0,
    can_edit_projects   TINYINT(1)      NOT NULL DEFAULT 0,
    can_create_projects TINYINT(1)      NOT NULL DEFAULT 0,
    can_delete_projects TINYINT(1)      NOT NULL DEFAULT 0,
    can_manage_users    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '保留欄位；授權範圍尚待確認，初期管理後台僅限最高權限管理者',
    row_version         INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '單一員工儲存時的樂觀鎖，避免兩位管理者互相覆蓋',
    updated_by          INT UNSIGNED    NULL,
    updated_at          DATETIME        NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_ugp_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 專案（通用資料容器，不設類型欄位）
-- ---------------------------------------------------------------------
CREATE TABLE projects (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name                VARCHAR(200)    NOT NULL,
    name_norm           VARCHAR(200)    NOT NULL COLLATE utf8mb4_bin,
    description         MEDIUMTEXT      NOT NULL,
    description_norm    MEDIUMTEXT      NOT NULL COLLATE utf8mb4_bin,
    description_version INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '樂觀併發控制版本號，每次成功儲存 +1',
    created_by          INT UNSIGNED    NOT NULL,
    created_at          DATETIME        NOT NULL,
    updated_by          INT UNSIGNED    NULL,
    updated_at          DATETIME        NOT NULL,
    -- 刪除策略尚待確認，先採軟刪除以保留回收可能性；所有查詢必須加上 deleted_at IS NULL
    deleted_at          DATETIME        NULL,
    deleted_by          INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    KEY idx_projects_deleted (deleted_at),
    CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 第二層：個別專案權限
-- 沒有資料列 = 沒有任何權限（Deny by Default）。
-- ---------------------------------------------------------------------
CREATE TABLE project_permissions (
    project_id  INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    can_view    TINYINT(1)      NOT NULL DEFAULT 0,
    can_edit    TINYINT(1)      NOT NULL DEFAULT 0,
    can_delete  TINYINT(1)      NOT NULL DEFAULT 0,
    granted_by  INT UNSIGNED    NULL,
    updated_at  DATETIME        NOT NULL,
    PRIMARY KEY (project_id, user_id),
    -- 搜尋與專案列表會以 user_id 為起點找出可見專案
    KEY idx_pp_user_view (user_id, can_view, project_id),
    CONSTRAINT fk_pp_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_pp_user    FOREIGN KEY (user_id)    REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 專案說明版本歷史（只新增，不提供一般使用者修改或刪除）
-- ---------------------------------------------------------------------
CREATE TABLE project_description_history (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id          INT UNSIGNED    NOT NULL,
    version_before      INT UNSIGNED    NULL COMMENT '建立專案時的初始版本為 NULL',
    version_after       INT UNSIGNED    NOT NULL,
    content_before      MEDIUMTEXT      NULL,
    content_after       MEDIUMTEXT      NOT NULL,
    changed_by          INT UNSIGNED    NOT NULL,
    changed_at          DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pdh_project_version (project_id, version_after),
    CONSTRAINT fk_pdh_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_pdh_user    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 專案關鍵字：保留原文，以 keyword_norm 判斷是否重複（「資料庫」=「数据库」）
-- ---------------------------------------------------------------------
CREATE TABLE project_keywords (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_id      INT UNSIGNED    NOT NULL,
    keyword         VARCHAR(100)    NOT NULL COMMENT '使用者原始輸入',
    keyword_norm    VARCHAR(100)    NOT NULL COLLATE utf8mb4_bin,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pk_project_norm (project_id, keyword_norm),
    CONSTRAINT fk_pk_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_pk_user    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 隨手記：無標題、無分類；作者一律取自登入 Session
-- ---------------------------------------------------------------------
CREATE TABLE project_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      INT UNSIGNED    NOT NULL,
    author_id       INT UNSIGNED    NOT NULL,
    content         MEDIUMTEXT      NOT NULL,
    content_norm    MEDIUMTEXT      NOT NULL COLLATE utf8mb4_bin,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NULL,
    updated_by      INT UNSIGNED    NULL,
    deleted_at      DATETIME        NULL,
    deleted_by      INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    -- 最新在上 + Keyset 分頁：WHERE project_id=? AND deleted_at IS NULL AND id < ? ORDER BY id DESC LIMIT n
    KEY idx_notes_project_id (project_id, deleted_at, id),
    CONSTRAINT fk_notes_project FOREIGN KEY (project_id) REFERENCES projects (id),
    CONSTRAINT fk_notes_author  FOREIGN KEY (author_id)  REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 登入節流：不保存原始帳號字串（避免使用者誤把密碼輸入到帳號欄而被存下）
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_key     BINARY(32)      NOT NULL COMMENT 'HMAC-SHA256(伺服器金鑰, 小寫帳號)',
    ip              VARCHAR(45)     NOT NULL,
    stage           VARCHAR(20)     NOT NULL COMMENT 'password / totp / recovery',
    succeeded       TINYINT(1)      NOT NULL,
    attempted_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_la_account_time (account_key, attempted_at),
    KEY idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 最高權限管理者救援 Token
-- 只保存 SHA-256 雜湊；使用時以單一條件式 UPDATE 原子性標記為已使用：
--   UPDATE admin_recovery_tokens SET used_at = ?
--    WHERE token_hash = ? AND purpose = ? AND used_at IS NULL
--      AND invalidated_at IS NULL AND expires_at > ?
--   → 影響列數 = 1 才算成功
-- ---------------------------------------------------------------------
CREATE TABLE admin_recovery_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED    NOT NULL,
    purpose         VARCHAR(30)     NOT NULL COMMENT 'reset_password / reset_2fa / reset_both',
    token_hash      BINARY(32)      NOT NULL,
    created_at      DATETIME        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        NULL,
    invalidated_at  DATETIME        NULL COMMENT '同用途重新申請、或任一救援完成時，使其他未使用 Token 失效',
    request_ip      VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_art_hash (token_hash),
    KEY idx_art_user_purpose (user_id, purpose, used_at),
    CONSTRAINT fk_art_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 歷史操作紀錄（伺服器端產生；不得寫入密碼、驗證碼、Token、Secret、隨手記內文）
-- actor_label / project_label 為快照，確保使用者或專案改名、刪除後仍可閱讀。
-- ---------------------------------------------------------------------
CREATE TABLE activity_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at     DATETIME(3)     NOT NULL COMMENT 'UTC',
    actor_user_id   INT UNSIGNED    NULL COMMENT '未登入或未知帳號時為 NULL',
    actor_label     VARCHAR(100)    NOT NULL,
    category        VARCHAR(20)     NOT NULL COMMENT 'auth / search / project / data / admin / security',
    action          VARCHAR(50)     NOT NULL COMMENT '例如 login.success、project.view、note.create',
    result          VARCHAR(10)     NOT NULL COMMENT 'success / failure / denied',
    project_id      INT UNSIGNED    NULL,
    project_label   VARCHAR(200)    NULL,
    target_user_id  INT UNSIGNED    NULL COMMENT '管理操作的對象使用者',
    detail          VARCHAR(500)    NULL COMMENT '必要的簡短描述，已過濾敏感資訊',
    ip              VARCHAR(45)     NULL,
    user_agent      VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    KEY idx_al_time (occurred_at),
    KEY idx_al_actor_time (actor_user_id, occurred_at),
    KEY idx_al_action_time (action, occurred_at),
    KEY idx_al_project_time (project_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 系統設定：只存「被修改過的值」。
-- 型別、預設值、允許範圍、分類由程式中的設定定義檔（SettingsRegistry）負責，
-- 讀取時找不到資料列即使用預設值。真正的密碼／金鑰不放這裡（見 docs/04）。
-- ---------------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key     VARCHAR(100)    NOT NULL COMMENT '例如 security.login_max_failures',
    value_json      TEXT            NOT NULL,
    updated_by      INT UNSIGNED    NULL,
    updated_at      DATETIME        NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Schema 版本（簡易遷移紀錄；InfinityFree 無 SSH，遷移由受保護的安裝頁執行）
-- ---------------------------------------------------------------------
CREATE TABLE schema_migrations (
    version     VARCHAR(50)     NOT NULL,
    applied_at  DATETIME        NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
