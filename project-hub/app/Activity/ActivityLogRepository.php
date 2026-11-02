<?php

declare(strict_types=1);

namespace ProjectHub\Activity;

use DateTimeImmutable;
use DateTimeZone;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

/**
 * 歷史操作紀錄的查詢、統計、匯出與保存期限清理（階段 8）。
 *
 * 時間一律以 UTC 儲存；篩選的「日期」是系統時區（預設 Asia/Taipei）的日期，
 * 由程式換算成 UTC 範圍後查詢，才能使用 occurred_at 的索引。
 */
final class ActivityLogRepository
{
    public const PAGE_SIZE = 50;
    public const EXPORT_LIMIT = 50000;
    public const CATEGORIES = [
        'auth' => '登入',
        'account' => '帳號',
        'project' => '專案存取',
        'data' => '資料異動',
        'search' => '搜尋',
        'admin' => '管理',
        'security' => '安全',
    ];

    public function __construct(private Database $db, private string $timezone)
    {
    }

    /**
     * 將篩選條件轉為 SQL。$filter 已由控制器驗證。
     *
     * @param array{from:string,to:string,user:string,category:string} $filter
     * @return array{0:string, 1:list<mixed>}
     */
    public function where(array $filter): array
    {
        [$fromUtc, $toUtc] = $this->utcRange($filter['from'], $filter['to']);
        $sql = 'occurred_at >= ? AND occurred_at < ?';
        $params = [$fromUtc, $toUtc];
        if ($filter['user'] === 'unknown') {
            $sql .= ' AND actor_user_id IS NULL';
        } elseif ($filter['user'] !== '') {
            $sql .= ' AND actor_user_id = ?';
            $params[] = (int)$filter['user'];
        }
        if ($filter['category'] !== '') {
            $sql .= ' AND category = ?';
            $params[] = $filter['category'];
        }
        return [$sql, $params];
    }

    /**
     * 以 ID 由新到舊分頁（Keyset），資料量大時不使用 OFFSET。
     *
     * @param array{from:string,to:string,user:string,category:string} $filter
     * @return array{items: list<array<string, mixed>>, next: int|null}
     */
    public function page(array $filter, ?int $beforeId): array
    {
        [$where, $params] = $this->where($filter);
        if ($beforeId !== null) {
            $where .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            "SELECT id, occurred_at, actor_user_id, actor_label, category, action, result, project_id, project_label, target_user_id, detail, ip
               FROM activity_logs WHERE {$where} ORDER BY id DESC LIMIT " . (self::PAGE_SIZE + 1),
            $params
        );
        $next = null;
        if (count($rows) > self::PAGE_SIZE) {
            array_pop($rows);
            $next = (int)$rows[count($rows) - 1]['id'];
        }
        return ['items' => $rows, 'next' => $next];
    }

    /**
     * 統計：總數、首次與最後操作時間、上午（00:00–11:59）與下午（12:00–23:59）次數、每小時分布。
     * 上午／下午以系統時區計算。
     *
     * @param array{from:string,to:string,user:string,category:string} $filter
     * @return array{total:int, first:?string, last:?string, morning:int, afternoon:int, hours: array<int,int>}
     */
    public function stats(array $filter): array
    {
        [$where, $params] = $this->where($filter);
        $offset = $this->offsetSeconds($filter['from']);
        $row = (array)$this->db->one(
            "SELECT COUNT(*) AS total, MIN(occurred_at) AS first, MAX(occurred_at) AS last,
                    COALESCE(SUM(CASE WHEN HOUR(DATE_ADD(occurred_at, INTERVAL ? SECOND)) < 12 THEN 1 ELSE 0 END), 0) AS morning
               FROM activity_logs WHERE {$where}",
            array_merge([$offset], $params)
        );
        $hours = array_fill(0, 24, 0);
        foreach ($this->db->all(
            "SELECT HOUR(DATE_ADD(occurred_at, INTERVAL ? SECOND)) AS h, COUNT(*) AS c FROM activity_logs WHERE {$where} GROUP BY h",
            array_merge([$offset], $params)
        ) as $r) {
            $hours[(int)$r['h']] = (int)$r['c'];
        }
        $total = (int)$row['total'];
        $morning = (int)$row['morning'];
        return [
            'total' => $total,
            'first' => $row['first'] === null ? null : (string)$row['first'],
            'last' => $row['last'] === null ? null : (string)$row['last'],
            'morning' => $morning,
            'afternoon' => $total - $morning,
            'hours' => $hours,
        ];
    }

    /**
     * 依篩選條件逐筆讀出（供 CSV 匯出串流使用），最多 EXPORT_LIMIT 筆。
     *
     * @param array{from:string,to:string,user:string,category:string} $filter
     * @return \Generator<array<string, mixed>>
     */
    public function export(array $filter): \Generator
    {
        [$where, $params] = $this->where($filter);
        $stmt = $this->db->run(
            "SELECT occurred_at, actor_label, category, action, result, project_label, detail, ip
               FROM activity_logs WHERE {$where} ORDER BY id LIMIT " . self::EXPORT_LIMIT,
            $params
        );
        while (($row = $stmt->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * 刪除超過保存期限的紀錄。主機沒有排程，因此在管理者開啟紀錄頁時分批執行，每次最多 $limit 筆，
     * 避免單一請求過久。
     */
    public function purgeOlderThan(int $days, int $limit = 2000): int
    {
        return $this->db->run(
            'DELETE FROM activity_logs WHERE occurred_at < ? ORDER BY id LIMIT ' . $limit,
            [Clock::utc(Clock::now() - $days * 86400)]
        )->rowCount();
    }

    /** @return list<array<string, mixed>> 篩選用的使用者清單（含已停用） */
    public function actors(): array
    {
        return $this->db->all('SELECT id, username, display_name, status FROM users ORDER BY is_super_admin DESC, display_name');
    }

    /** 系統時區的日期區間 → UTC [起, 迄)。 */
    private function utcRange(string $fromDate, string $toDate): array
    {
        $tz = new DateTimeZone($this->timezone);
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($fromDate . ' 00:00:00', $tz))->setTimezone($utc);
        $end = (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * 系統時區相對 UTC 的秒數（以篩選起日計算）。台灣沒有日光節約時間，整個區間固定為 +8 小時；
     * 有日光節約的時區在切換日前後可能有一小時誤差，屬已知限制。
     */
    private function offsetSeconds(string $date): int
    {
        return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone($this->timezone)))->getOffset();
    }
}
