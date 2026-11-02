<?php

declare(strict_types=1);

namespace ProjectHub\Activity;

/** 操作代碼 → 中文名稱（紀錄中保存英文代碼，方便日後查詢與擴充；畫面與匯出顯示中文）。 */
final class ActivityLabels
{
    private const ACTIONS = [
        'login.success' => '登入成功',
        'login.failure' => '登入失敗',
        'logout' => '登出',
        '2fa.enroll' => '綁定 2FA',
        '2fa.change' => '更換 2FA',
        'password.change' => '修改密碼',
        'sessions.revoke_others' => '登出其他裝置',
        'system.install' => '系統安裝',
        'system.migrate' => '資料庫更新',
        'admin.access' => '存取管理後台',
        'admin.reauth' => '管理者身分驗證',
        'admin.user.create' => '新增使用者',
        'admin.user.update_profile' => '修改使用者資料',
        'admin.user.disable' => '停用使用者',
        'admin.user.enable' => '啟用使用者',
        'admin.user.2fa_reset' => '重設員工 2FA',
        'admin.user.password_reset' => '重設員工密碼',
        'admin.permission.update' => '修改全域權限',
        'admin.project_permission.update' => '修改專案權限',
        'project.view' => '進入專案',
        'project.create' => '建立專案',
        'project.rename' => '專案改名',
        'project.delete' => '刪除專案',
        'project.restore' => '還原專案',
        'project.history.view' => '查看版本歷史',
        'project.description.update' => '編輯專案說明',
        'keyword.add' => '新增關鍵字',
        'keyword.delete' => '移除關鍵字',
        'note.create' => '新增隨手記',
        'note.update' => '修改隨手記',
        'note.delete' => '刪除隨手記',
        'note.list' => '讀取隨手記',
        'attachment.upload' => '上傳附件',
        'attachment.view' => '檢視原圖',
        'attachment.preview' => '預覽圖片',
        'attachment.download' => '下載附件',
        'attachment.delete' => '刪除附件',
        'search.query' => '搜尋',
        'search.reindex' => '重建搜尋索引',
        'logs.export' => '匯出歷史紀錄',
        'logs.purge' => '清理過期紀錄',
    ];

    private const RESULTS = ['success' => '成功', 'failure' => '失敗', 'denied' => '拒絕'];

    public static function action(string $code): string
    {
        return self::ACTIONS[$code] ?? $code;
    }

    public static function result(string $code): string
    {
        return self::RESULTS[$code] ?? $code;
    }

    public static function category(string $code): string
    {
        return ActivityLogRepository::CATEGORIES[$code] ?? $code;
    }
}
