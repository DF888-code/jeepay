<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use ProjectHub\Activity\ActivityLabels;
use ProjectHub\Activity\ActivityLogRepository;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

/**
 * 歷史操作紀錄（階段 8）：只有最高權限管理者可以查看（路由層已限制）。
 *
 * 用途是了解員工使用系統的大致情況，而不是以點擊次數評分；畫面上會提醒「沒有紀錄不代表沒有工作」。
 */
final class AdminLogController extends AdminController
{
    private const MAX_RANGE_DAYS = 366;

    public function index(Request $request): Response
    {
        $repo = $this->repo();
        // 無排程：開啟紀錄頁時順便分批清理超過保存期限的紀錄
        $purged = $repo->purgeOlderThan($this->app->settings->int('logs.retention_days'));
        if ($purged > 0) {
            $this->log('logs.purge', "已刪除超過保存期限的紀錄 {$purged} 筆");
        }

        [$filter, $error] = $this->filter($request);
        $before = $request->query('before');
        $page = $repo->page($filter, ctype_digit($before) ? (int)$before : null);
        return $this->render('admin/logs', [
            'title' => '歷史操作紀錄',
            'filter' => $filter,
            'mode' => $request->query('mode') === 'range' ? 'range' : 'day',
            'error' => $error,
            'stats' => $repo->stats($filter),
            'items' => $page['items'],
            'next' => $page['next'],
            'actors' => $repo->actors(),
            'categories' => ActivityLogRepository::CATEGORIES,
            'retentionDays' => $this->app->settings->int('logs.retention_days'),
            'scripts' => ['/assets/admin-logs.js'],
            'queryString' => http_build_query(array_filter([
                'mode' => $request->query('mode'), 'date' => $request->query('date'), 'from' => $request->query('from'),
                'to' => $request->query('to'), 'user' => $filter['user'], 'category' => $filter['category'],
            ], static fn($v): bool => $v !== '' && $v !== null)),
        ]);
    }

    /** 匯出 CSV（Excel 可直接開啟）。最多 5 萬筆；儲存格以 = + - @ 開頭時加上單引號，防止公式注入。 */
    public function export(Request $request): Response
    {
        [$filter, $error] = $this->filter($request);
        if ($error !== null) {
            return $this->back('/admin/logs', 'error', $error);
        }
        $repo = $this->repo();
        $stats = $repo->stats($filter);
        $this->log('logs.export', "匯出 {$filter['from']}～{$filter['to']}，共 " . min($stats['total'], ActivityLogRepository::EXPORT_LIMIT) . ' 筆');
        $tz = $this->app->settings->string('system.timezone');
        $filename = "activity-logs-{$filter['from']}-{$filter['to']}.csv";
        return Response::stream(static function () use ($repo, $filter, $tz): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM：讓 Excel 正確顯示中文
            fputcsv($out, ['時間', '員工', '類別', '操作', '結果', '相關專案', '說明', 'IP'], ',', '"', '');
            foreach ($repo->export($filter) as $row) {
                fputcsv($out, array_map([self::class, 'csvCell'], [
                    Clock::display((string)$row['occurred_at'], $tz, 'Y-m-d H:i:s'),
                    (string)$row['actor_label'],
                    ActivityLabels::category((string)$row['category']),
                    ActivityLabels::action((string)$row['action']),
                    ActivityLabels::result((string)$row['result']),
                    (string)($row['project_label'] ?? ''),
                    (string)($row['detail'] ?? ''),
                    (string)($row['ip'] ?? ''),
                ]), ',', '"', '');
            }
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /**
     * 解析篩選條件：單日（mode=day&date=）或日期範圍（mode=range&from=&to=）、員工、類別。
     * 不合法的輸入一律改回「今天」並提示，不會造成錯誤頁。
     *
     * @return array{0: array{from:string,to:string,user:string,category:string}, 1: ?string}
     */
    private function filter(Request $request): array
    {
        $tz = new DateTimeZone($this->app->settings->string('system.timezone'));
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $error = null;
        if ($request->query('mode') === 'range') {
            $from = $request->query('from', $today);
            $to = $request->query('to', $today);
        } else {
            $from = $to = $request->query('date', $today);
        }
        if (!self::validDate($from) || !self::validDate($to)) {
            $error = '日期格式不正確，已改為顯示今天。';
            $from = $to = $today;
        } elseif ($from > $to) {
            $error = '起始日期不可晚於結束日期，已自動對調。';
            [$from, $to] = [$to, $from];
        }
        $days = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            $error = '日期範圍最多 ' . self::MAX_RANGE_DAYS . ' 天，已調整結束日期。';
            $to = (new DateTimeImmutable($from))->modify('+' . (self::MAX_RANGE_DAYS - 1) . ' days')->format('Y-m-d');
        }
        $user = $request->query('user');
        if ($user !== 'unknown' && !ctype_digit($user)) {
            $user = '';
        }
        $category = $request->query('category');
        if (!isset(ActivityLogRepository::CATEGORIES[$category])) {
            $category = '';
        }
        return [['from' => $from, 'to' => $to, 'user' => $user, 'category' => $category], $error];
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function repo(): ActivityLogRepository
    {
        return new ActivityLogRepository($this->app->db, $this->app->settings->string('system.timezone'));
    }
}
