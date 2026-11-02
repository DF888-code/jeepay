<?php

declare(strict_types=1);

namespace ProjectHub\Project;

use PDOException;
use ProjectHub\Search\TextNormalizer;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 專案關鍵字：保留使用者輸入的原文，以標準化後的 keyword_norm 判斷是否重複。
 * 「資料庫」與「数据库」視為同一個關鍵字，需等階段 7 加入 OpenCC 後生效；目前已能辨識全形／半形與大小寫。
 */
final class KeywordRepository
{
    public const MAX_LENGTH = 100;

    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $projectId): array
    {
        return $this->db->all(
            'SELECT k.id, k.keyword, k.created_at, u.display_name AS created_by_name
               FROM project_keywords k JOIN users u ON u.id = k.created_by
              WHERE k.project_id = ? ORDER BY k.id',
            [$projectId]
        );
    }

    /**
     * 新增；已有相同意思的關鍵字時回傳既有的原文，成功回傳 null。
     * 以資料庫唯一鍵判斷重複，兩人同時新增相同關鍵字也只會成功一筆。
     */
    public function add(int $projectId, string $keyword, int $userId): ?string
    {
        $norm = TextNormalizer::normalize($keyword);
        try {
            $this->db->run(
                'INSERT INTO project_keywords (project_id, keyword, keyword_norm, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$projectId, $keyword, $norm, $userId, Clock::utc()]
            );
            return null;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return (string)$this->db->value('SELECT keyword FROM project_keywords WHERE project_id = ? AND keyword_norm = ?', [$projectId, $norm]);
        }
    }

    /** @return array<string, mixed>|null 只回傳屬於該專案的關鍵字，避免以其他專案的關鍵字 ID 操作 */
    public function find(int $projectId, int $keywordId): ?array
    {
        return $this->db->one('SELECT * FROM project_keywords WHERE id = ? AND project_id = ?', [$keywordId, $projectId]);
    }

    public function delete(int $projectId, int $keywordId): void
    {
        $this->db->run('DELETE FROM project_keywords WHERE id = ? AND project_id = ?', [$keywordId, $projectId]);
    }
}
