<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Search\SearchService;

/**
 * 全站搜尋（階段 7）。搜尋範圍與權限限制見 SearchService。
 */
final class SearchController extends ProjectBaseController
{
    public function index(Request $request): Response
    {
        $query = trim($request->query('q'));
        $page = max(1, (int)$request->query('page', '1'));
        $vars = ['title' => '搜尋', 'query' => $query, 'result' => null, 'page' => $page, 'error' => null];
        if ($query !== '') {
            if (mb_strlen($query) > SearchService::MAX_QUERY_LENGTH) {
                $vars['error'] = '搜尋文字不可超過 ' . SearchService::MAX_QUERY_LENGTH . ' 字。';
            } else {
                $vars['result'] = $this->service()->search($this->uid(), $query, $page);
                $vars['pages'] = max(1, (int)ceil($vars['result']['total'] / SearchService::PAGE_SIZE));
                $this->logSearch('page', $query, $vars['result']['total']);
            }
        }
        return $this->render('search/index', $vars);
    }

    public function api(Request $request): Response
    {
        $query = trim($request->query('q'));
        if ($query === '' || mb_strlen($query) > SearchService::MAX_QUERY_LENGTH) {
            return Response::json(['error' => '請輸入 1–' . SearchService::MAX_QUERY_LENGTH . ' 字的搜尋文字'], 422);
        }
        $result = $this->service()->search($this->uid(), $query, max(1, (int)$request->query('page', '1')));
        $this->logSearch('api', $query, $result['total']);
        return Response::json([
            'total' => $result['total'],
            'items' => array_map(static fn(array $i): array => [
                'id' => $i['id'],
                'name' => $i['name'],
                'matched' => $i['matched'] + ['notes' => $i['notes']['count']],
                'keywords' => $i['keywords'],
                'notes' => array_map(static fn(array $h): array => ['id' => $h['id'], 'snippet' => implode('', $h['snippet'])], $i['notes']['hints']),
            ], $result['items']),
        ]);
    }

    /**
     * 決議 #5：預設不保存搜尋原文（可能含密碼或個資），只記錄類型、字數與結果數量；
     * 系統設定開啟 search.log_keywords 時才保存原文。
     */
    private function logSearch(string $type, string $query, int $total): void
    {
        $detail = "類型：{$type}；字數：" . mb_strlen($query) . "；結果：{$total} 個專案";
        if ($this->app->settings->bool('search.log_keywords')) {
            $detail .= '；關鍵字：' . mb_substr($query, 0, 100);
        }
        $this->app->logger->log('search', 'search.query', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(), $detail);
    }

    private function uid(): int
    {
        return (int)$this->app->auth->userId();
    }

    private function service(): SearchService
    {
        return new SearchService($this->app->db, $this->authorizer());
    }
}
