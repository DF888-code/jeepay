<?php

declare(strict_types=1);

namespace ProjectHub\Search;

use PDOException;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 重新計算所有 *_norm 欄位（標準化規則或字典更新後使用）。
 *
 * 主機每個請求最多執行 60 秒且沒有排程，因此分批處理：每次呼叫只處理一段時間，
 * 進度（目前資料表與最後處理的 ID）存在 system_settings，可隨時中斷後繼續。
 * 只改寫 *_norm 欄位，使用者輸入的原文完全不變。
 */
final class SearchIndexer
{
    private const STATE_KEY = 'internal.search_index';
    private const BATCH = 200;

    /** 資料表 → [原文欄位, 標準化欄位, 是否需略過已刪除] */
    private const TABLES = [
        'projects' => [['name' => 'name_norm', 'description' => 'description_norm']],
        'project_keywords' => [['keyword' => 'keyword_norm']],
        'project_notes' => [['content' => 'content_norm']],
        'note_attachments' => [['original_name' => 'original_name_norm']],
    ];

    public function __construct(private Database $db)
    {
    }

    /** @return array{version:int, table:?string, last_id:int, conflicts:list<string>} */
    public function state(): array
    {
        $json = $this->db->value('SELECT value_json FROM system_settings WHERE setting_key = ?', [self::STATE_KEY]);
        $state = is_string($json) ? json_decode($json, true) : null;
        return is_array($state) ? $state + ['version' => 1, 'table' => null, 'last_id' => 0, 'conflicts' => []]
            : ['version' => 1, 'table' => null, 'last_id' => 0, 'conflicts' => []];
    }

    public function isCurrent(): bool
    {
        $state = $this->state();
        return (int)$state['version'] === TextNormalizer::VERSION && $state['table'] === null;
    }

    /** 新安裝的系統：資料一開始就以最新規則寫入，直接標記為最新。 */
    public function markCurrent(): void
    {
        $this->save(['version' => TextNormalizer::VERSION, 'table' => null, 'last_id' => 0, 'conflicts' => []]);
    }

    /**
     * 在時間限制內盡量處理；回傳是否全部完成。
     *
     * @return array{done:bool, table:?string, processed:int, conflicts:list<string>}
     */
    public function run(float $seconds): array
    {
        $state = $this->state();
        if ((int)$state['version'] === TextNormalizer::VERSION && $state['table'] === null) {
            return ['done' => true, 'table' => null, 'processed' => 0, 'conflicts' => $state['conflicts']];
        }
        $tables = array_keys(self::TABLES);
        if ($state['table'] === null || !in_array($state['table'], $tables, true)) {
            $state = ['version' => (int)$state['version'], 'table' => $tables[0], 'last_id' => 0, 'conflicts' => []];
        }
        $deadline = microtime(true) + $seconds;
        $processed = 0;
        while (microtime(true) < $deadline) {
            $table = (string)$state['table'];
            $columns = self::TABLES[$table][0];
            $rows = $this->db->all(
                'SELECT id, ' . implode(', ', array_keys($columns)) . ($table === 'project_keywords' ? ', project_id' : '')
                . " FROM {$table} WHERE id > ? ORDER BY id LIMIT " . self::BATCH,
                [(int)$state['last_id']]
            );
            foreach ($rows as $row) {
                $this->updateRow($table, $columns, $row, $state['conflicts']);
                $state['last_id'] = (int)$row['id'];
                $processed++;
            }
            if (count($rows) < self::BATCH) {
                $next = array_search($table, $tables, true) + 1;
                if (!isset($tables[$next])) {
                    $this->save(['version' => TextNormalizer::VERSION, 'table' => null, 'last_id' => 0, 'conflicts' => $state['conflicts']]);
                    return ['done' => true, 'table' => null, 'processed' => $processed, 'conflicts' => $state['conflicts']];
                }
                $state['table'] = $tables[$next];
                $state['last_id'] = 0;
            }
            $this->save($state);
        }
        return ['done' => false, 'table' => (string)$state['table'], 'processed' => $processed, 'conflicts' => $state['conflicts']];
    }

    /**
     * @param array<string, string> $columns
     * @param array<string, mixed> $row
     * @param list<string> $conflicts
     */
    private function updateRow(string $table, array $columns, array $row, array &$conflicts): void
    {
        $sets = [];
        $params = [];
        foreach ($columns as $source => $target) {
            $sets[] = "{$target} = ?";
            $params[] = TextNormalizer::normalize((string)$row[$source]);
        }
        $params[] = (int)$row['id'];
        try {
            $this->db->run("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = ?', $params);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // 新規則下兩個關鍵字變成相同（例如同一專案同時有「資料庫」與「资料库」）：
            // 不擅自刪除使用者資料，保留舊的標準化值並列出，請使用者自行決定是否移除其中一個
            $conflicts[] = "專案 #{$row['project_id']} 的關鍵字「{$row['keyword']}」與既有關鍵字意思相同";
        }
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $this->db->run(
            'INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)',
            [self::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE), Clock::utc()]
        );
    }
}
