<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Authorization\Authorizer;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Project\ProjectRepository;
use ProjectHub\Search\SearchIndexer;

/**
 * 管理後台：專案管理（含回收區）與個別專案權限（第二層授權）。
 */
final class AdminProjectController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->render('admin/projects', [
            'title' => '專案管理',
            'projects' => $this->projects()->listAllForAdmin(),
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/projects',
            'searchIndex' => (new SearchIndexer($this->app->db))->state() + ['current' => (new SearchIndexer($this->app->db))->isCurrent()],
            'scripts' => ['/assets/search-rebuild.js'],
        ]);
    }

    /**
     * 重建搜尋索引（分批）：每次請求最多處理 20 秒，避開主機 60 秒限制。
     * 只重新計算 *_norm 欄位，不改動使用者輸入的原文，因此不需要再驗證。
     */
    public function rebuildSearchIndex(Request $request): Response
    {
        $result = (new SearchIndexer($this->app->db))->run(20.0);
        if ($result['done']) {
            $this->log('search.reindex', '搜尋索引重建完成' . ($result['conflicts'] === [] ? '' : '（' . count($result['conflicts']) . ' 個關鍵字需手動處理）'));
        }
        if ($request->wantsJson() || $request->header('Accept') === 'application/json') {
            return Response::json($result);
        }
        return $this->back('/admin/projects', $result['done'] ? 'success' : 'warning',
            $result['done'] ? '搜尋索引已重建完成。' : '已處理一部分，請再按一次「繼續重建」。');
    }

    public function restore(Request $request, int $id): Response
    {
        if (($deny = $this->requireElevated('/admin/projects')) !== null) {
            return $deny;
        }
        $project = $this->projects()->findAny($id);
        if ($project === null) {
            return $this->notFound();
        }
        if ($this->projects()->restore($id, (int)$this->admin()['id'])) {
            $this->log('project.restore', '自回收區還原', null, $id, (string)$project['name']);
        }
        return $this->back('/admin/projects', 'success', "已還原專案「{$project['name']}」。");
    }

    public function permissions(Request $request, int $id): Response
    {
        $project = $this->projects()->find($id);
        if ($project === null) {
            return $this->notFound();
        }
        return $this->render('admin/project_permissions', [
            'title' => '專案權限：' . $project['name'],
            'project' => $project,
            'members' => $this->projects()->memberMatrix($id),
            'elevated' => $this->elevated(),
            'returnTo' => "/admin/projects/{$id}/permissions",
            'scripts' => ['/assets/admin-permissions.js'],
        ]);
    }

    /** 只更新網址中這一個專案、這一位使用者的授權；不會自動授予任何全域權限。 */
    public function savePermission(Request $request, int $projectId, int $userId): Response
    {
        $return = "/admin/projects/{$projectId}/permissions";
        if (($deny = $this->requireElevated($return)) !== null) {
            return $deny;
        }
        $project = $this->projects()->find($projectId);
        $target = $this->app->users->findManageable($userId);
        if ($project === null || $target === null) {
            return $this->notFound();
        }
        $view = $request->input('can_view') === '1';
        $edit = $request->input('can_edit') === '1';
        $delete = $request->input('can_delete') === '1';
        // 決議 #8：編輯、刪除都要能先看到專案
        $autoView = !$view && ($edit || $delete);
        $view = $view || $edit || $delete;

        $before = $this->projects()->memberPermissions($projectId, $userId);
        $this->projects()->setMemberPermissions($projectId, $userId, $view, $edit, $delete, (int)$this->admin()['id']);

        $after = ['can_view' => (int)$view, 'can_edit' => (int)$edit, 'can_delete' => (int)$delete];
        $labels = ['can_view' => '查看', 'can_edit' => '編輯', 'can_delete' => '刪除'];
        $changes = [];
        foreach ($labels as $k => $label) {
            if ($before[$k] !== $after[$k]) {
                $changes[] = $label . '：' . ($before[$k] ? '有' : '無') . '→' . ($after[$k] ? '有' : '無');
            }
        }
        $this->log('admin.project_permission.update', '對象：' . $target['username'] . '；' . ($changes === [] ? '內容未變更' : implode('，', $changes)),
            $userId, $projectId, (string)$project['name']);
        return $this->back($return, 'success', "已儲存「{$target['display_name']}」在此專案的權限。"
            . ($autoView ? '（編輯或刪除需要能查看專案，已自動加上「查看」）' : ''));
    }

    private function projects(): ProjectRepository
    {
        return new ProjectRepository($this->app->db, new Authorizer($this->app->db));
    }
}
