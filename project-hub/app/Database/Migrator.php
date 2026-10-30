<?php

declare(strict_types=1);

namespace ProjectHub\Database;

use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;
use Throwable;

/**
 * 依檔名順序執行 database/migrations/*.sql。
 *
 * InfinityFree 無 SSH，遷移只能透過受保護的網頁觸發。
 * 注意：MySQL 的 DDL 會自動提交，無法整批回滾；遷移檔必須只做「新增」型變更，
 * 會刪除或改變既有資料的遷移需先經過確認。
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> 已套用的版本 */
    public function applied(): array
    {
        try {
            return array_map('strval', $this->db->pdo()->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> 本次套用的版本 */
    public function migrate(): array
    {
        $applied = $this->applied();
        $done = [];
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach (self::statements((string)file_get_contents($file)) as $sql) {
                $this->db->pdo()->exec($sql);
            }
            $this->db->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, Clock::utc()]);
            $done[] = $version;
        }
        return $done;
    }

    /**
     * 拆分 SQL 檔：移除整行註解後，以行尾分號切割。
     * 遷移檔撰寫規範：字串與 COMMENT 內不得出現半形分號。
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\r\n|\n|\r/', $sql) ?: [], // 不可用 \R：非 /u 模式下會把中文字中的 0x85 位元組當成換行
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }
}
