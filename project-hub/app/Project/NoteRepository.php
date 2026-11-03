<?php

declare(strict_types=1);

namespace ProjectHub\Project;

use ProjectHub\Search\TextNormalizer;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 隨手記：無標題、無分類；作者一律由呼叫端傳入目前登入的使用者，不接受表單指定。
 */
final class NoteRepository
{
    public const MAX_LENGTH = 20000;

    public function __construct(private Database $db)
    {
    }

    public function create(int $projectId, int $authorId, string $content): int
    {
        $this->db->run(
            'INSERT INTO project_notes (project_id, author_id, content, content_norm, created_at) VALUES (?, ?, ?, ?, ?)',
            [$projectId, $authorId, $content, TextNormalizer::normalize($content), Clock::utc()]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    /**
     * 最新在上，以 Keyset 分頁（id < 游標）避免資料量大時 OFFSET 變慢。
     * 多取一筆用來判斷是否還有更早的資料。
     *
     * @return array{items: list<array<string, mixed>>, next: int|null}
     */
    public function page(int $projectId, ?int $beforeId, int $limit): array
    {
        $params = [$projectId];
        $cursor = '';
        if ($beforeId !== null) {
            $cursor = ' AND n.id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            "SELECT n.id, n.author_id, n.content, n.created_at, n.updated_at, a.display_name AS author_name, e.display_name AS editor_name
               FROM project_notes n
               JOIN users a ON a.id = n.author_id
          LEFT JOIN users e ON e.id = n.updated_by
              WHERE n.project_id = ? AND n.deleted_at IS NULL{$cursor}
           ORDER BY n.id DESC
              LIMIT " . ($limit + 1),
            $params
        );
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int)$rows[count($rows) - 1]['id'];
        }
        return ['items' => $rows, 'next' => $next];
    }

    /** @return array<string, mixed>|null 只回傳屬於該專案且未刪除的隨手記 */
    public function find(int $projectId, int $noteId): ?array
    {
        return $this->db->one('SELECT * FROM project_notes WHERE id = ? AND project_id = ? AND deleted_at IS NULL', [$noteId, $projectId]);
    }

    /**
     * 修改內容，並在同一個交易保存修改前後的完整內容。
     * 以 FOR UPDATE 鎖住該列，兩人同時修改時各自記錄到正確的「修改前」內容。
     */
    public function update(int $noteId, string $content, int $userId): void
    {
        $this->db->transaction(function () use ($noteId, $content, $userId): void {
            $before = $this->db->value('SELECT content FROM project_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$noteId]);
            if (!is_string($before)) {
                return;
            }
            $now = Clock::utc();
            $this->db->run(
                'UPDATE project_notes SET content = ?, content_norm = ?, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL',
                [$content, TextNormalizer::normalize($content), $now, $userId, $noteId]
            );
            if ($before !== $content) {
                $this->revision($noteId, 'update', $before, $content, $userId, $now);
            }
        });
    }

    /** 軟刪除，並保存刪除當下的內容（刪除後仍可在修改紀錄中查看） */
    public function softDelete(int $noteId, int $userId): void
    {
        $this->db->transaction(function () use ($noteId, $userId): void {
            $before = $this->db->value('SELECT content FROM project_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$noteId]);
            if (!is_string($before)) {
                return;
            }
            $now = Clock::utc();
            $this->db->run('UPDATE project_notes SET deleted_at = ?, deleted_by = ? WHERE id = ? AND deleted_at IS NULL', [$now, $userId, $noteId]);
            $this->revision($noteId, 'delete', $before, null, $userId, $now);
        });
    }

    /**
     * 專案的隨手記修改／刪除紀錄（新到舊）。可指定單一隨手記。
     *
     * @return array{items: list<array<string, mixed>>, next: ?int}
     */
    public function revisions(int $projectId, ?int $noteId, ?int $beforeId, int $limit, ?int $authorId = null): array
    {
        $where = 'r.project_id = ?';
        $params = [$projectId];
        if ($authorId !== null) {
            // 一般員工只能看自己撰寫的隨手記的紀錄
            $where .= ' AND n.author_id = ?';
            $params[] = $authorId;
        }
        if ($noteId !== null) {
            $where .= ' AND r.note_id = ?';
            $params[] = $noteId;
        }
        if ($beforeId !== null) {
            $where .= ' AND r.id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            "SELECT r.id, r.note_id, r.action, r.content_before, r.content_after, r.changed_at,
                    u.display_name AS changed_by_name, n.author_id, a.display_name AS author_name, n.created_at AS note_created_at,
                    n.deleted_at AS note_deleted_at
               FROM note_revisions r
               JOIN users u ON u.id = r.changed_by
               JOIN project_notes n ON n.id = r.note_id
               JOIN users a ON a.id = n.author_id
              WHERE {$where}
           ORDER BY r.id DESC
              LIMIT " . ($limit + 1),
            $params
        );
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (int)end($rows)['id'];
        }
        return ['items' => $rows, 'next' => $next];
    }

    /** @return array<string, mixed>|null 隨手記（含已刪除），供修改紀錄頁顯示標題 */
    public function findAny(int $projectId, int $noteId): ?array
    {
        return $this->db->one(
            'SELECT n.*, a.display_name AS author_name FROM project_notes n JOIN users a ON a.id = n.author_id WHERE n.id = ? AND n.project_id = ?',
            [$noteId, $projectId]
        );
    }

    private function revision(int $noteId, string $action, string $before, ?string $after, int $userId, string $at): void
    {
        $this->db->run(
            'INSERT INTO note_revisions (note_id, project_id, action, content_before, content_after, changed_by, changed_at)
             SELECT id, project_id, ?, ?, ?, ?, ? FROM project_notes WHERE id = ?',
            [$action, $before, $after, $userId, $at, $noteId]
        );
    }
}
