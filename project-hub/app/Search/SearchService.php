<?php

declare(strict_types=1);

namespace ProjectHub\Search;

use ProjectHub\Authorization\Authorizer;
use ProjectHub\Support\Database;

/**
 * 全站搜尋：專案名稱、專案說明、專案關鍵字、隨手記。
 *
 * 安全要求：權限條件與比對條件寫在同一個 SQL 中，資料庫只會回傳使用者可查看的專案；
 * 筆數、摘要、符合的隨手記也都只來自這些專案，不會洩漏未授權專案的任何資訊（規格 8.2）。
 */
final class SearchService
{
    public const MAX_QUERY_LENGTH = 100;
    public const PAGE_SIZE = 20;
    private const NOTE_HINTS = 3;
    private const SNIPPET_RADIUS = 40;

    public function __construct(private Database $db, private Authorizer $authorizer)
    {
    }

    /**
     * @return array{total:int, items:list<array<string, mixed>>, variants:list<string>}
     */
    public function search(int $userId, string $query, int $page): array
    {
        $variants = TextNormalizer::variants($query);
        if ($variants === []) {
            return ['total' => 0, 'items' => [], 'variants' => []];
        }
        [$visible, $visibleParams] = $this->authorizer->visibleCondition($userId);

        // 每個欄位對每一種寫法各比對一次（以參數綁定，LIKE 萬用字元已跳脫）
        $likes = array_map(static fn(string $v): string => '%' . strtr($v, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%', $variants);
        $any = static fn(string $column): string => '(' . implode(' OR ', array_fill(0, count($likes), "{$column} LIKE ? ESCAPE '!'")) . ')';
        $nameMatch = $any('pr.name_norm');
        $descMatch = $any('pr.description_norm');
        $keywordMatch = 'EXISTS (SELECT 1 FROM project_keywords k WHERE k.project_id = pr.id AND ' . $any('k.keyword_norm') . ')';
        $noteMatch = 'EXISTS (SELECT 1 FROM project_notes n WHERE n.project_id = pr.id AND n.deleted_at IS NULL AND ' . $any('n.content_norm') . ')';

        $where = "{$visible} AND ({$nameMatch} OR {$descMatch} OR {$keywordMatch} OR {$noteMatch})";
        $whereParams = array_merge($visibleParams, $likes, $likes, $likes, $likes);

        $total = (int)$this->db->value("SELECT COUNT(*) FROM projects pr WHERE {$where}", $whereParams);
        $offset = max(0, ($page - 1) * self::PAGE_SIZE);
        $rows = $this->db->all(
            "SELECT pr.id, pr.name, pr.description, pr.updated_at,
                    {$nameMatch} AS m_name, {$descMatch} AS m_desc, {$keywordMatch} AS m_keyword
               FROM projects pr
              WHERE {$where}
           ORDER BY m_name DESC, pr.updated_at DESC, pr.id DESC
              LIMIT " . self::PAGE_SIZE . " OFFSET {$offset}",
            array_merge($likes, $likes, $likes, $whereParams)
        );

        $items = [];
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            $items[] = [
                'id' => $pid,
                'name' => (string)$row['name'],
                'updated_at' => (string)$row['updated_at'],
                'matched' => [
                    'name' => (bool)$row['m_name'],
                    'description' => (bool)$row['m_desc'],
                    'keyword' => (bool)$row['m_keyword'],
                ],
                'description_snippet' => (bool)$row['m_desc'] ? self::snippet((string)$row['description'], $variants) : null,
                'keywords' => $this->matchingKeywords($pid, $variants),
                'notes' => $this->matchingNotes($pid, $likes, $variants),
            ];
        }
        return ['total' => $total, 'items' => $items, 'variants' => $variants];
    }

    /**
     * @param list<string> $variants
     * @return list<string>
     */
    private function matchingKeywords(int $projectId, array $variants): array
    {
        $out = [];
        foreach ($this->db->all('SELECT keyword, keyword_norm FROM project_keywords WHERE project_id = ? ORDER BY id', [$projectId]) as $k) {
            foreach ($variants as $v) {
                if (str_contains((string)$k['keyword_norm'], $v)) {
                    $out[] = (string)$k['keyword'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * 只查詢已確認可查看之專案內的隨手記。
     *
     * @param list<string> $likes
     * @param list<string> $variants
     * @return array{count:int, hints:list<array<string, mixed>>}
     */
    private function matchingNotes(int $projectId, array $likes, array $variants): array
    {
        $cond = '(' . implode(' OR ', array_fill(0, count($likes), "n.content_norm LIKE ? ESCAPE '!'")) . ')';
        $params = array_merge([$projectId], $likes);
        $count = (int)$this->db->value("SELECT COUNT(*) FROM project_notes n WHERE n.project_id = ? AND n.deleted_at IS NULL AND {$cond}", $params);
        if ($count === 0) {
            return ['count' => 0, 'hints' => []];
        }
        $rows = $this->db->all(
            "SELECT n.id, n.content, n.created_at, u.display_name AS author_name
               FROM project_notes n JOIN users u ON u.id = n.author_id
              WHERE n.project_id = ? AND n.deleted_at IS NULL AND {$cond}
           ORDER BY n.id DESC LIMIT " . self::NOTE_HINTS,
            $params
        );
        $hints = array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'author' => (string)$r['author_name'],
            'created_at' => (string)$r['created_at'],
            'snippet' => self::snippet((string)$r['content'], $variants),
        ], $rows);
        return ['count' => $count, 'hints' => $hints];
    }

    /**
     * 從原文擷取符合位置附近的文字。位置以標準化後的文字尋找（字形轉換大多一字對一字），
     * 回傳 [前段, 符合段, 後段] 讓畫面分別跳脫並標示符合處，不在伺服器組合 HTML。
     *
     * @param list<string> $variants
     * @return array{0:string,1:string,2:string}
     */
    public static function snippet(string $original, array $variants): array
    {
        $original = (string)preg_replace('/\s+/u', ' ', $original);
        $norm = TextNormalizer::normalize($original);
        $pos = false;
        $len = 0;
        foreach ($variants as $v) {
            $p = mb_strpos($norm, $v);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
                $len = mb_strlen($v);
            }
        }
        if ($pos === false || mb_strlen($norm) !== mb_strlen($original)) {
            // 長度不一致（少數詞彙轉換會改變字數）時不標示，只顯示開頭
            return [mb_substr($original, 0, self::SNIPPET_RADIUS * 2) . (mb_strlen($original) > self::SNIPPET_RADIUS * 2 ? '…' : ''), '', ''];
        }
        $start = max(0, $pos - self::SNIPPET_RADIUS);
        $before = ($start > 0 ? '…' : '') . mb_substr($original, $start, $pos - $start);
        $match = mb_substr($original, $pos, $len);
        $afterStart = $pos + $len;
        $after = mb_substr($original, $afterStart, self::SNIPPET_RADIUS) . (mb_strlen($original) > $afterStart + self::SNIPPET_RADIUS ? '…' : '');
        return [$before, $match, $after];
    }
}
