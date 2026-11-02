<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Authorization\Authorizer;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Attachment\AttachmentRepository;
use ProjectHub\Attachment\UploadInspector;
use ProjectHub\Project\KeywordRepository;
use ProjectHub\Project\NoteRepository;
use ProjectHub\Project\ProjectRepository;
use ProjectHub\Search\TextNormalizer;

/**
 * 專案列表、建立、詳細頁、改名、刪除（階段 5）。
 *
 * 每個動作都先經過 Authorizer 的雙重授權；不符合時一律回應與「專案不存在」相同的 404，
 * 不洩漏專案名稱或存在與否，並在歷史操作紀錄留下拒絕事件。
 */
final class ProjectController extends ProjectBaseController
{
    private const PAGE_SIZE = 30;

    public function index(Request $request): Response
    {
        $uid = (int)$this->app->auth->userId();
        $page = max(1, (int)$request->query('page', '1'));
        $result = $this->projects()->listVisible($uid, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        return $this->render('projects/index', [
            'title' => '專案',
            'projects' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int)ceil($result['total'] / self::PAGE_SIZE)),
            'canCreate' => $this->authorizer()->canCreate($uid),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        if (!$this->authorizer()->canCreate((int)$this->app->auth->userId())) {
            return $this->deny('project.create', null);
        }
        return $this->render('projects/new', ['title' => '建立專案', 'old' => []]);
    }

    public function create(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        $uid = (int)$user['id'];
        if (!$this->authorizer()->canCreate($uid)) {
            return $this->deny('project.create', null);
        }
        $name = trim(TextNormalizer::clean($request->input('name')));
        $description = TextNormalizer::clean($request->input('description'));
        $error = $this->validateName($name);
        if ($error === null && trim($description) === '') {
            $error = '請輸入專案說明。';
        } elseif ($error === null && mb_strlen($description) > ProjectRepository::DESCRIPTION_MAX) {
            $error = '專案說明不可超過 ' . ProjectRepository::DESCRIPTION_MAX . ' 字。';
        }
        if ($error !== null) {
            return $this->render('projects/new', ['title' => '建立專案', 'old' => ['name' => $name, 'description' => $description], 'error' => $error], 422);
        }

        $isAdmin = (int)$user['is_super_admin'] === 1;
        $id = $this->projects()->create($name, $description, $uid, !$isAdmin);
        $this->app->logger->log('data', 'project.create', Log::RESULT_SUCCESS, $uid, (string)$user['username'], null, $id, $name);
        return $this->back("/projects/{$id}", 'success', $isAdmin
            ? '專案已建立。可到「管理後台 → 專案管理」設定授權名單。'
            : '專案已建立，您已自動取得此專案的查看與編輯權限。');
    }

    public function show(Request $request, int $id): Response
    {
        $uid = (int)$this->app->auth->userId();
        if (!$this->authorizer()->can($uid, Authorizer::VIEW, $id)) {
            return $this->deny('project.view', $id);
        }
        $project = (array)$this->projects()->find($id);
        $can = $this->authorizer()->abilities($uid, $id);
        $before = $request->query('before');
        $notes = (new NoteRepository($this->app->db))->page($id, ctype_digit($before) ? (int)$before : null, ProjectContentController::NOTES_PAGE_SIZE);
        // 每則隨手記可執行的動作（決議 #10）：作者本人具編輯權限可修改與刪除；具刪除權限者可刪除他人的隨手記
        foreach ($notes['items'] as &$note) {
            $mine = (int)$note['author_id'] === $uid;
            $note['can_edit'] = $mine && $can['edit'];
            $note['can_delete'] = ($mine && $can['edit']) || $can['delete'];
        }
        unset($note);
        $attachments = (new AttachmentRepository($this->app->db))->forNotes(array_map(static fn(array $n): int => (int)$n['id'], $notes['items']));
        // 只在第一頁記錄「進入專案」，翻頁載入更早的隨手記不重複記錄
        if ($before === '') {
            $this->app->logger->log('project', 'project.view', Log::RESULT_SUCCESS, $uid, $this->app->auth->label(), null, $id, (string)$project['name']);
        }
        return $this->render('projects/show', [
            'title' => (string)$project['name'],
            'project' => $project,
            'can' => $can,
            'isAdmin' => (int)($this->app->auth->user()['is_super_admin'] ?? 0) === 1,
            'keywords' => (new KeywordRepository($this->app->db))->list($id),
            'notes' => $notes['items'],
            'nextBefore' => $notes['next'],
            'olderPage' => $before !== '',
            'attachments' => $attachments,
            'acceptTypes' => UploadInspector::acceptAttribute(),
            'maxFileMb' => $this->app->settings->int('attachments.max_file_mb'),
            'scripts' => $can['edit'] ? ['/assets/note-attachments.js', '/assets/image-viewer.js', '/assets/project-keywords.js'] : ['/assets/image-viewer.js'],
        ]);
    }

    public function rename(Request $request, int $id): Response
    {
        $uid = (int)$this->app->auth->userId();
        if (!$this->authorizer()->can($uid, Authorizer::EDIT, $id)) {
            return $this->deny('project.rename', $id);
        }
        $name = trim(TextNormalizer::clean($request->input('name')));
        $error = $this->validateName($name);
        if ($error !== null) {
            return $this->back("/projects/{$id}", 'error', $error);
        }
        $before = (string)$this->projects()->find($id)['name'];
        $this->projects()->rename($id, $name, $uid);
        $this->app->logger->log('data', 'project.rename', Log::RESULT_SUCCESS, $uid, $this->app->auth->label(), '原名稱：' . $before, $id, $name);
        return $this->back("/projects/{$id}", 'success', '專案名稱已更新。');
    }

    public function delete(Request $request, int $id): Response
    {
        $uid = (int)$this->app->auth->userId();
        if (!$this->authorizer()->can($uid, Authorizer::DELETE, $id)) {
            return $this->deny('project.delete', $id);
        }
        $project = (array)$this->projects()->find($id);
        // 刪除前要求輸入專案名稱，避免誤按
        if (trim($request->input('confirm_name')) !== (string)$project['name']) {
            return $this->back("/projects/{$id}", 'error', '確認名稱不符，專案未刪除。');
        }
        $this->projects()->softDelete($id, $uid);
        $this->app->logger->log('data', 'project.delete', Log::RESULT_SUCCESS, $uid, $this->app->auth->label(), '移至回收區（可由最高權限管理者還原）', $id, (string)$project['name']);
        return $this->back('/', 'success', '專案已刪除。若需要復原，請聯絡最高權限管理者。');
    }

    /** JSON API：與頁面套用完全相同的雙重授權。 */
    public function apiList(Request $request): Response
    {
        $page = max(1, (int)$request->query('page', '1'));
        $result = $this->projects()->listVisible((int)$this->app->auth->userId(), self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        return Response::json([
            'total' => $result['total'],
            'items' => array_map(static fn(array $p): array => ['id' => (int)$p['id'], 'name' => (string)$p['name']], $result['items']),
        ]);
    }

    public function apiShow(Request $request, int $id): Response
    {
        $uid = (int)$this->app->auth->userId();
        if (!$this->authorizer()->can($uid, Authorizer::VIEW, $id)) {
            return $this->deny('project.view', $id);
        }
        $project = (array)$this->projects()->find($id);
        return Response::json([
            'id' => $id,
            'name' => (string)$project['name'],
            'description' => (string)$project['description'],
            'description_version' => (int)$project['description_version'],
            'permissions' => $this->authorizer()->abilities($uid, $id),
        ]);
    }
}
