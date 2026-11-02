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

    public function update(int $noteId, string $content, int $userId): void
    {
        $this->db->run(
            'UPDATE project_notes SET content = ?, content_norm = ?, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL',
            [$content, TextNormalizer::normalize($content), Clock::utc(), $userId, $noteId]
        );
    }

    public function softDelete(int $noteId, int $userId): void
    {
        $this->db->run('UPDATE project_notes SET deleted_at = ?, deleted_by = ? WHERE id = ? AND deleted_at IS NULL', [Clock::utc(), $userId, $noteId]);
    }
}
