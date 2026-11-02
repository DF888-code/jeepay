<?php

declare(strict_types=1);

namespace ProjectHub\Authorization;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 第一層：全域使用者權限。
 *
 * 每位使用者一列，欄位互相獨立；沒有資料列或欄位為 0 都代表沒有權限（預設拒絕）。
 * 不存在任何職級或角色：每個人的權限都由最高權限管理者逐一設定。
 */
final class GlobalPermissions
{
    /** 介面上開放設定的權限：欄位名稱 → 顯示名稱。can_manage_users 依決議 #15 暫不開放。 */
    public const FIELDS = [
        'can_view_projects' => '查看',
        'can_edit_projects' => '編輯',
        'can_create_projects' => '建立',
        'can_delete_projects' => '刪除',
    ];

    public function __construct(private Database $db)
    {
    }

    /** 建立使用者時新增一列「全部無權限」的資料。 */
    public function ensureRow(int $userId, ?int $updatedBy): void
    {
        $this->db->run(
            'INSERT IGNORE INTO user_global_permissions (user_id, updated_by, updated_at) VALUES (?, ?, ?)',
            [$userId, $updatedBy, Clock::utc()]
        );
    }

    /** @return array<string, int>|null 含 row_version */
    public function find(int $userId): ?array
    {
        $row = $this->db->one(
            'SELECT ' . implode(', ', array_keys(self::FIELDS)) . ', row_version FROM user_global_permissions WHERE user_id = ?',
            [$userId]
        );
        return $row === null ? null : array_map('intval', $row);
    }

    /**
     * 只更新指定的一位使用者，並以 row_version 做樂觀併發控制：
     * 兩位管理者同時編輯同一人時，後送出者會失敗，不會默默覆蓋前者的修改。
     *
     * @param array<string, bool> $values 鍵必須是 FIELDS 中的欄位
     */
    public function save(int $userId, array $values, int $expectedVersion, int $updatedBy): bool
    {
        $sets = [];
        $params = [];
        foreach (array_keys(self::FIELDS) as $field) {
            // 欄位名稱只來自程式內的白名單，值一律以參數綁定
            $sets[] = "{$field} = ?";
            $params[] = !empty($values[$field]) ? 1 : 0;
        }
        $params[] = $updatedBy;
        $params[] = Clock::utc();
        $params[] = $userId;
        $params[] = $expectedVersion;
        $stmt = $this->db->run(
            'UPDATE user_global_permissions SET ' . implode(', ', $sets)
            . ', row_version = row_version + 1, updated_by = ?, updated_at = ? WHERE user_id = ? AND row_version = ?',
            $params
        );
        return $stmt->rowCount() === 1;
    }
}
