<?php

declare(strict_types=1);

namespace ProjectHub\Authorization;

use ProjectHub\Support\Database;

/**
 * 雙重授權（Two-Layer Authorization）的唯一判斷入口。
 *
 * 一般使用者要對專案執行動作，必須「全域權限」與「該專案權限」同時允許；
 * 且編輯、刪除都要求兩層都具備「查看」（決議 #8）。最高權限管理者全部允許。
 *
 * 注意事項：
 * - 每次呼叫都直接查資料庫，不快取，撤銷權限後下一個請求立即生效；
 * - 帳號停用、專案已刪除、查無資料列一律視為拒絕（Deny by Default）；
 * - 列表與搜尋不可逐筆呼叫 can() 後在 PHP 過濾，必須使用 visibleCondition() 讓資料庫只回傳可見專案。
 */
final class Authorizer
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    /** 動作 → [全域欄位, 專案欄位]；欄位名稱只來自此白名單 */
    private const COLUMNS = [
        self::VIEW => ['can_view_projects', 'can_view'],
        self::EDIT => ['can_edit_projects', 'can_edit'],
        self::DELETE => ['can_delete_projects', 'can_delete'],
    ];

    public function __construct(private Database $db)
    {
    }

    public function can(int $userId, string $action, int $projectId): bool
    {
        if (!isset(self::COLUMNS[$action])) {
            return false;
        }
        [$global, $project] = self::COLUMNS[$action];
        $sql = "SELECT 1
                  FROM users u
                  JOIN projects pr ON pr.id = ? AND pr.deleted_at IS NULL
             LEFT JOIN user_global_permissions g ON g.user_id = u.id
             LEFT JOIN project_permissions pp ON pp.user_id = u.id AND pp.project_id = pr.id
                 WHERE u.id = ? AND u.status = 'active'
                   AND (u.is_super_admin = 1
                        OR (g.can_view_projects = 1 AND pp.can_view = 1 AND g.{$global} = 1 AND pp.{$project} = 1))";
        return (bool)$this->db->value($sql, [$projectId, $userId]);
    }

    /** @return array{view:bool,edit:bool,delete:bool} 一次取得三種動作的結果（用於決定畫面上顯示哪些按鈕；實際操作仍會再檢查） */
    public function abilities(int $userId, int $projectId): array
    {
        return [
            'view' => $this->can($userId, self::VIEW, $projectId),
            'edit' => $this->can($userId, self::EDIT, $projectId),
            'delete' => $this->can($userId, self::DELETE, $projectId),
        ];
    }

    /** 建立專案沒有既存專案可授權，只檢查全域建立權限。 */
    public function canCreate(int $userId): bool
    {
        return (bool)$this->db->value(
            "SELECT 1 FROM users u LEFT JOIN user_global_permissions g ON g.user_id = u.id
              WHERE u.id = ? AND u.status = 'active' AND (u.is_super_admin = 1 OR g.can_create_projects = 1)",
            [$userId]
        );
    }

    /**
     * 「可查看專案」的 SQL 條件，供列表、搜尋、匯出等查詢組合使用。
     * 呼叫端的查詢必須以 pr 作為 projects 的別名。
     *
     * @return array{0:string, 1:list<int>} [WHERE 片段, 參數]
     */
    public function visibleCondition(int $userId): array
    {
        return [
            "pr.deleted_at IS NULL AND EXISTS (
                SELECT 1 FROM users u
             LEFT JOIN user_global_permissions g ON g.user_id = u.id
             LEFT JOIN project_permissions pp ON pp.user_id = u.id AND pp.project_id = pr.id
                 WHERE u.id = ? AND u.status = 'active'
                   AND (u.is_super_admin = 1 OR (g.can_view_projects = 1 AND pp.can_view = 1)))",
            [$userId],
        ];
    }
}
