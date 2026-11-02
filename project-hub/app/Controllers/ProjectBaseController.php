<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Authorization\Authorizer;
use ProjectHub\Http\Response;
use ProjectHub\Project\ProjectRepository;

/**
 * 專案相關控制器的共用功能：雙重授權判斷與一致的拒絕回應。
 */
abstract class ProjectBaseController extends Controller
{
    /**
     * 拒絕：回應與「找不到」完全相同，紀錄中則保留嘗試存取的專案編號供管理者查看。
     * 紀錄不寫入專案名稱以外的內容；名稱只有最高權限管理者看得到。
     */
    protected function deny(string $action, ?int $projectId): Response
    {
        $label = null;
        if ($projectId !== null) {
            $project = $this->projects()->findAny($projectId);
            $label = $project === null ? null : (string)$project['name'];
        }
        $this->app->logger->log('project', $action, Log::RESULT_DENIED, $this->app->auth->userId(), $this->app->auth->label(),
            null, $projectId !== null && $label !== null ? $projectId : null, $label);
        return $this->app->request->wantsJson()
            ? Response::json(['error' => '找不到資源'], 404)
            : Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
    }

    protected function validateName(string $name): ?string
    {
        if ($name === '') {
            return '請輸入專案名稱。';
        }
        if (mb_strlen($name) > ProjectRepository::NAME_MAX) {
            return '專案名稱不可超過 ' . ProjectRepository::NAME_MAX . ' 字。';
        }
        if (str_contains($name, "\n")) {
            return '專案名稱不可換行。';
        }
        return null;
    }

    protected function authorizer(): Authorizer
    {
        return new Authorizer($this->app->db);
    }

    protected function projects(): ProjectRepository
    {
        return new ProjectRepository($this->app->db, $this->authorizer());
    }
}
