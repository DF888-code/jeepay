<?php

declare(strict_types=1);

namespace ProjectHub\Settings;

/**
 * 系統設定定義檔：每個設定的分類、型別、預設值與允許範圍都在這裡定義。
 * 新功能只要在此新增定義，系統設定中心就會自動出現對應欄位。
 *
 * page：顯示在哪一個設定頁（未指定為「一般設定」）。寄信與附件儲存因為有測試、授權等專屬流程，
 * 各自有獨立頁面。機密值（密碼、API 金鑰、Token）不在這裡，見 SecretSettings。
 */
final class SettingsRegistry
{
    /** 分類 → 顯示名稱（一般設定頁依此順序分組） */
    public const CATEGORIES = [
        'system' => '系統',
        'security' => '登入與安全',
        'attachments' => '附件',
        'logs' => '歷史紀錄',
        'recovery' => 'Email 救援（最高權限管理者）',
        'search' => '搜尋',
        'mail' => '寄信',
        'storage' => '附件儲存位置',
    ];

    /**
     * @return array<string, array{category:string,label:string,type:string,default:mixed,min?:int,max?:int,help?:string,page?:string,options?:array<string,string>,format?:string}>
     */
    public static function definitions(): array
    {
        return [
            'system.site_name' => [
                'category' => 'system', 'label' => '網站名稱', 'type' => 'string', 'default' => '專案管理平台',
                'max' => 50,
            ],
            'system.theme_color' => [
                'category' => 'system', 'label' => '站台顏色', 'type' => 'enum', 'default' => 'default',
                'options' => [
                    'default' => '預設（白／灰）', 'blue' => '藍色', 'green' => '綠色', 'teal' => '青色', 'purple' => '紫色',
                    'pink' => '粉紅色', 'orange' => '橘色', 'yellow' => '黃色', 'red' => '紅色', 'gray' => '深灰色',
                ],
                'help' => '同一套系統架設在多個地方時，用不同顏色區分。會改變背景與最上方導覽列的顏色，登入頁也會套用；深色模式會自動改用較深的同色系。',
            ],
            'system.site_label' => [
                'category' => 'system', 'label' => '站台標籤（選填）', 'type' => 'string', 'default' => '', 'max' => 20,
                'help' => '顯示在網站名稱旁邊的小標籤，例如「測試站」「台北分公司」。留空則不顯示。',
            ],
            'system.timezone' => [
                'category' => 'system', 'label' => '顯示時區', 'type' => 'timezone', 'default' => 'Asia/Taipei',
                'help' => '資料庫一律存 UTC，畫面依此時區顯示；歷史紀錄的上午／下午統計也依此時區計算。',
            ],
            'security.login_max_failures' => [
                'category' => 'security', 'label' => '同一帳號連續失敗上限', 'type' => 'int', 'default' => 5,
                'min' => 3, 'max' => 20, 'help' => '密碼與 2FA 驗證碼錯誤合併計算，達上限後暫時鎖定。',
            ],
            'security.login_ip_max_failures' => [
                'category' => 'security', 'label' => '同一 IP 失敗上限', 'type' => 'int', 'default' => 30,
                'min' => 5, 'max' => 500,
            ],
            'security.login_unknown_max' => [
                'category' => 'security', 'label' => '同一 IP 輸入不存在帳號的上限', 'type' => 'int', 'default' => 5,
                'min' => 3, 'max' => 50,
                'help' => '同一 IP 在鎖定時間內輸入不存在的帳號達此次數，就暫時封鎖該 IP 的所有登入（含正確帳號），用來阻擋猜帳號的行為。',
            ],
            'security.login_lock_minutes' => [
                'category' => 'security', 'label' => '鎖定時間（分鐘）', 'type' => 'int', 'default' => 15,
                'min' => 1, 'max' => 1440,
            ],
            'security.totp_window' => [
                'category' => 'security', 'label' => 'TOTP 時間容許範圍（前後各幾個 30 秒）', 'type' => 'int',
                'default' => 1, 'min' => 0, 'max' => 2,
            ],
            'security.pre_auth_minutes' => [
                'category' => 'security', 'label' => '密碼驗證後完成 2FA 的時限（分鐘）', 'type' => 'int',
                'default' => 10, 'min' => 2, 'max' => 30,
            ],
            'security.session_idle_minutes' => [
                'category' => 'security', 'label' => '閒置自動登出（分鐘）', 'type' => 'int', 'default' => 120,
                'min' => 5, 'max' => 1440,
            ],
            'security.session_absolute_hours' => [
                'category' => 'security', 'label' => '登入最長有效時間（小時）', 'type' => 'int', 'default' => 12,
                'min' => 1, 'max' => 168,
            ],
            'security.single_session' => [
                'category' => 'security', 'label' => '單一裝置登入', 'type' => 'bool', 'default' => true,
                'help' => '開啟時，在新裝置或新瀏覽器完成登入後，同一帳號在其他裝置的登入狀態會立即失效。',
            ],
            'attachments.max_file_mb' => [
                'category' => 'attachments', 'label' => '單一檔案上限（MB）', 'type' => 'int', 'default' => 10,
                'min' => 1, 'max' => 10, 'help' => 'InfinityFree 單檔上限為 10 MB，超過會被主機刪除。',
            ],
            'attachments.max_per_note' => [
                'category' => 'attachments', 'label' => '每則隨手記附件上限', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 50,
            ],
            'attachments.quota_mb' => [
                'category' => 'attachments', 'label' => '附件總容量上限（MB）', 'type' => 'int', 'default' => 500, 'min' => 10, 'max' => 2048,
                'help' => '決議 #24：降低免費主機被認定為檔案儲存用途的風險。',
            ],
            'attachments.max_files' => [
                'category' => 'attachments', 'label' => '附件檔案數上限', 'type' => 'int', 'default' => 5000, 'min' => 100, 'max' => 20000,
                'help' => '每張圖片會另存一個預覽檔；主機總檔案數上限約 30,000。',
            ],
            'logs.retention_days' => [
                'category' => 'logs', 'label' => '歷史操作紀錄保存天數', 'type' => 'int', 'default' => 365, 'min' => 30, 'max' => 3650,
                'help' => '決議 #4。超過天數的紀錄會在管理者開啟紀錄頁時分批刪除（主機沒有排程）。',
            ],
            'search.log_keywords' => [
                'category' => 'search', 'label' => '歷史紀錄保存搜尋原文', 'type' => 'bool', 'default' => false,
                'help' => '決議 #5：預設只記錄搜尋字數與結果數量；搜尋文字可能包含密碼或個資。',
            ],
            'security.password_min_length' => [
                'category' => 'security', 'label' => '密碼最短長度', 'type' => 'int', 'default' => 10,
                'min' => 8, 'max' => 64,
            ],
            'recovery.token_minutes' => [
                'category' => 'recovery', 'label' => '救援連結有效時間（分鐘）', 'type' => 'int', 'default' => 30, 'min' => 10, 'max' => 120,
            ],
            'recovery.both_cooldown_minutes' => [
                'category' => 'recovery', 'label' => '「密碼與驗證器都遺失」的等待時間（分鐘）', 'type' => 'int', 'default' => 30,
                'min' => 0, 'max' => 1440,
                'help' => '決議 #19：只靠 Email 的救援風險最高，連結在申請後需等待這段時間才能使用；期間本人若仍能登入可在帳號設定取消。',
            ],
            'recovery.requests_per_hour' => [
                'category' => 'recovery', 'label' => '每小時最多申請次數（同一帳號）', 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20,
                'help' => '超過時不再寄信（畫面回應不變，避免被拿來探測帳號）；同一 IP 上限為此數值的 3 倍。',
            ],
            'mail.driver' => [
                'category' => 'mail', 'page' => 'mail', 'label' => '寄信方式', 'type' => 'enum', 'default' => 'none',
                'options' => ['none' => '不寄信', 'smtp' => 'SMTP', 'brevo_api' => 'Brevo HTTPS API'],
                'help' => '決議 #3：同一供應商通常同時提供 SMTP 與 HTTPS API；SMTP 被擋時可改用 API。',
            ],
            'mail.from_address' => [
                'category' => 'mail', 'page' => 'mail', 'label' => '寄件者 Email', 'type' => 'string', 'default' => '',
                'max' => 254, 'format' => 'email', 'help' => '必須是寄信服務已驗證的寄件地址，否則信件會被拒收或進垃圾郵件。',
            ],
            'mail.from_name' => [
                'category' => 'mail', 'page' => 'mail', 'label' => '寄件者名稱', 'type' => 'string', 'default' => '',
                'max' => 100, 'help' => '空白時使用網站名稱。',
            ],
            'mail.smtp_host' => [
                'category' => 'mail', 'page' => 'mail', 'label' => 'SMTP 主機', 'type' => 'string', 'default' => '',
                'max' => 255, 'format' => 'host', 'help' => '例如 smtp-relay.brevo.com',
            ],
            'mail.smtp_port' => [
                'category' => 'mail', 'page' => 'mail', 'label' => 'SMTP 連接埠', 'type' => 'int', 'default' => 587, 'min' => 1, 'max' => 65535,
                'help' => '587 搭配 STARTTLS，465 搭配 SSL/TLS。',
            ],
            'mail.smtp_encryption' => [
                'category' => 'mail', 'page' => 'mail', 'label' => 'SMTP 加密方式', 'type' => 'enum', 'default' => 'tls',
                'options' => ['tls' => 'STARTTLS（建議，587）', 'ssl' => 'SSL/TLS（465）', 'none' => '不加密（僅供測試）'],
            ],
            'mail.smtp_username' => [
                'category' => 'mail', 'page' => 'mail', 'label' => 'SMTP 帳號', 'type' => 'string', 'default' => '', 'max' => 255,
            ],
            'attachments.storage' => [
                'category' => 'storage', 'page' => 'storage', 'label' => '新上傳附件的儲存位置', 'type' => 'enum', 'default' => 'local',
                'options' => ['local' => '本機（InfinityFree 主機）', 'google_drive' => 'Google Drive', 'r2' => 'Cloudflare R2'],
                'help' => '只影響之後上傳的檔案；每個檔案記住自己的位置，舊檔不需搬移。',
            ],
            'attachments.drive_quota_mb' => [
                'category' => 'storage', 'page' => 'storage', 'label' => 'Google Drive 附件總容量上限（MB）', 'type' => 'int',
                'default' => 10240, 'min' => 100, 'max' => 2097152,
                'help' => 'Google 免費帳號共 15 GB，與 Gmail、Google 相簿共用；建議預留空間。',
            ],
            'attachments.r2_quota_mb' => [
                'category' => 'storage', 'page' => 'storage', 'label' => 'Cloudflare R2 附件總容量上限（MB）', 'type' => 'int',
                'default' => 9216, 'min' => 100, 'max' => 2097152,
                'help' => 'R2 免費額度為每月 10 GB 儲存空間；超過會開始計費，預設留 1 GB 緩衝。',
            ],
            'storage.r2_endpoint' => [
                'category' => 'storage', 'page' => 'storage', 'label' => 'S3 API 端點（Endpoint）', 'type' => 'string', 'default' => '',
                'max' => 120, 'format' => 'r2endpoint',
                'help' => '在 bucket 的「設定」頁可複製「S3 API」網址，例如 https://帳戶ID.r2.cloudflarestorage.com；歐盟儲存區為 https://帳戶ID.eu.r2.cloudflarestorage.com。網址結尾帶有 bucket 名稱也可以，系統會自動分開。',
            ],
            'storage.r2_bucket' => [
                'category' => 'storage', 'page' => 'storage', 'label' => 'R2 bucket 名稱', 'type' => 'string', 'default' => '',
                'max' => 63, 'format' => 'bucket', 'help' => '只能有小寫英文、數字與連字號，例如 project-hub-attachments。',
            ],
            'storage.google_client_id' => [
                'category' => 'storage', 'page' => 'storage', 'label' => 'Google OAuth 用戶端 ID', 'type' => 'string', 'default' => '',
                'max' => 255, 'format' => 'google_client_id',
            ],
        ];
    }

    /**
     * 某一頁的設定定義。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forPage(string $page): array
    {
        return array_filter(self::definitions(), static fn(array $d): bool => ($d['page'] ?? 'general') === $page);
    }
}
