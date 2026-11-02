<?php

declare(strict_types=1);

namespace ProjectHub\Project;

use ProjectHub\Authorization\Authorizer;
use ProjectHub\Search\TextNormalizer;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

final class ProjectRepository
{
    public const NAME_MAX = 200;
    public const DESCRIPTION_MAX = 50000;

    public function __construct(private Database $db, private Authorizer $authorizer)
    {
    }

    /**
     * 建立專案並寫入第一筆說明版本歷史；非管理者建立時，自動取得該專案的查看＋編輯（決議 #12）。
     */
    public function create(string $name, string $description, int $creatorId, bool $grantCreator): int
    {
        return $this->db->transaction(function (Database $db) use ($name, $description, $creatorId, $grantCreator): int {
            $now = Clock::utc();
            $db->run(
                'INSERT INTO projects (name, name_norm, description, description_norm, description_version, created_by, created_at, updated_by, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)',
                [$name, TextNormalizer::normalize($name), $description, TextNormalizer::normalize($description), $creatorId, $now, $creatorId, $now]
            );
            $id = (int)$db->pdo()->lastInsertId();
            $db->run(
                'INSERT INTO project_description_history (project_id, version_before, version_after, content_before, content_after, changed_by, changed_at)
                 VALUES (?, NULL, 1, NULL, ?, ?, ?)',
                [$id, $description, $creatorId, $now]
            );
            if ($grantCreator) {
                $db->run(
                    'INSERT INTO project_permissions (project_id, user_id, can_view, can_edit, can_delete, granted_by, updated_at)
                     VALUES (?, ?, 1, 1, 0, ?, ?)',
                    [$id, $creatorId, $creatorId, $now]
                );
            }
            return $id;
        });
    }

    /** @return array<string, mixed>|null 未刪除的專案（不做權限判斷，呼叫前必須先經過 Authorizer） */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @return array<string, mixed>|null 包含已刪除的專案（僅供管理後台使用） */
    public function findAny(int $id): ?array
    {
        return $this->db->one('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    /**
     * 使用者可查看的專案：權限條件在 SQL 中，資料庫只會回傳可見的專案與筆數。
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listVisible(int $userId, int $limit, int $offset): array
    {
        [$condition, $params] = $this->authorizer->visibleCondition($userId);
        $total = (int)$this->db->value("SELECT COUNT(*) FROM projects pr WHERE {$condition}", $params);
        $items = $this->db->all(
            "SELECT pr.id, pr.name, pr.description, pr.updated_at, u.display_name AS creator_name
               FROM projects pr
               JOIN users u ON u.id = pr.created_by
              WHERE {$condition}
           ORDER BY pr.updated_at DESC, pr.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string, mixed>> 管理後台：全部專案（含已刪除） */
    public function listAllForAdmin(): array
    {
        return $this->db->all(
            'SELECT pr.id, pr.name, pr.created_at, pr.updated_at, pr.deleted_at, c.display_name AS creator_name,
                    (SELECT COUNT(*) FROM project_permissions pp WHERE pp.project_id = pr.id AND pp.can_view = 1) AS member_count
               FROM projects pr
               JOIN users c ON c.id = pr.created_by
           ORDER BY pr.deleted_at IS NOT NULL, pr.updated_at DESC'
        );
    }

    public function rename(int $id, string $name, int $userId): void
    {
        $this->db->run(
            'UPDATE projects SET name = ?, name_norm = ?, updated_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$name, TextNormalizer::normalize($name), $userId, Clock::utc(), $id]
        );
    }

    /** 軟刪除：資料保留，可由最高權限管理者在後台還原（決議 #7）。 */
    public function softDelete(int $id, int $userId): bool
    {
        $now = Clock::utc();
        return $this->db->run(
            'UPDATE projects SET deleted_at = ?, deleted_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $userId, $now, $id]
        )->rowCount() === 1;
    }

    public function restore(int $id, int $userId): bool
    {
        return $this->db->run(
            'UPDATE projects SET deleted_at = NULL, deleted_by = NULL, updated_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NOT NULL',
            [$userId, Clock::utc(), $id]
        )->rowCount() === 1;
    }

    /**
     * 專案授權名單：列出所有一般使用者，以及他們在此專案的權限與全域權限（供管理者判斷雙重授權結果）。
     *
     * @return list<array<string, mixed>>
     */
    public function memberMatrix(int $projectId): array
    {
        return $this->db->all(
            "SELECT u.id, u.username, u.display_name, u.status,
                    COALESCE(pp.can_view, 0) AS can_view, COALESCE(pp.can_edit, 0) AS can_edit, COALESCE(pp.can_delete, 0) AS can_delete,
                    COALESCE(g.can_view_projects, 0) AS g_view, COALESCE(g.can_edit_projects, 0) AS g_edit, COALESCE(g.can_delete_projects, 0) AS g_delete
               FROM users u
          LEFT JOIN project_permissions pp ON pp.user_id = u.id AND pp.project_id = ?
          LEFT JOIN user_global_permissions g ON g.user_id = u.id
              WHERE u.is_super_admin = 0
           ORDER BY pp.can_view DESC, u.status = 'active' DESC, u.display_name",
            [$projectId]
        );
    }

    /** @return array{can_view:int,can_edit:int,can_delete:int} */
    public function memberPermissions(int $projectId, int $userId): array
    {
        $row = $this->db->one('SELECT can_view, can_edit, can_delete FROM project_permissions WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
        return $row === null ? ['can_view' => 0, 'can_edit' => 0, 'can_delete' => 0] : array_map('intval', $row);
    }

    /** 設定某位使用者在此專案的權限；全部取消時刪除資料列（沒有資料列 = 沒有權限）。 */
    public function setMemberPermissions(int $projectId, int $userId, bool $view, bool $edit, bool $delete, int $grantedBy): void
    {
        if (!$view && !$edit && !$delete) {
            $this->db->run('DELETE FROM project_permissions WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
            return;
        }
        $this->db->run(
            'INSERT INTO project_permissions (project_id, user_id, can_view, can_edit, can_delete, granted_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit), can_delete = VALUES(can_delete),
                                     granted_by = VALUES(granted_by), updated_at = VALUES(updated_at)',
            [$projectId, $userId, (int)$view, (int)$edit, (int)$delete, $grantedBy, Clock::utc()]
        );
    }
}
