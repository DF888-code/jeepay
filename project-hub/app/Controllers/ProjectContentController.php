<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Authorization\Authorizer;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Project\KeywordRepository;
use ProjectHub\Project\NoteRepository;
use ProjectHub\Project\ProjectRepository;
use ProjectHub\Search\TextNormalizer;

/**
 * 專案內容（階段 6a）：專案說明編輯與版本歷史、關鍵字、隨手記。
 * 每個動作都先做雙重授權；紀錄中不寫入說明或隨手記的內文（可能含敏感資料）。
 */
final class ProjectContentController extends ProjectBaseController
{
    private const CONFLICT_MESSAGE = '專案說明已被其他使用者修改，請重新載入頁面後再編輯。';
    public const NOTES_PAGE_SIZE = 20;

    public function editDescription(Request $request, int $id): Response
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id)) {
            return $this->deny('project.description.update', $id);
        }
        $project = (array)$this->projects()->find($id);
        return $this->render('projects/description_edit', [
            'title' => '編輯專案說明', 'scripts' => ['/assets/description-draft.js'],
            'project' => $project,
            'draft' => (string)$project['description'],
            'version' => (int)$project['description_version'],
        ]);
    }

    public function updateDescription(Request $request, int $id): Response
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id)) {
            return $this->deny('project.description.update', $id);
        }
        $project = (array)$this->projects()->find($id);
        $content = TextNormalizer::clean($request->input('description'));
        $version = (int)$request->input('version', '0');
        $error = null;
        if (trim($content) === '') {
            $error = '請輸入專案說明。';
        } elseif (mb_strlen($content) > ProjectRepository::DESCRIPTION_MAX) {
            $error = '專案說明不可超過 ' . ProjectRepository::DESCRIPTION_MAX . ' 字。';
        }
        if ($error !== null) {
            return $this->render('projects/description_edit', [
                'title' => '編輯專案說明', 'scripts' => ['/assets/description-draft.js'], 'project' => $project, 'draft' => $content, 'version' => $version, 'error' => $error,
            ], 422);
        }

        $newVersion = $this->projects()->updateDescription($id, $version, $content, $this->uid());
        if ($newVersion === null) {
            // 不覆蓋、不自動合併：保留使用者剛輸入的文字，並提供目前的最新內容供對照
            $this->app->logger->log('data', 'project.description.update', Log::RESULT_FAILURE, $this->uid(), $this->app->auth->label(),
                '版本衝突，未儲存', $id, (string)$project['name']);
            return $this->render('projects/description_edit', [
                'title' => '編輯專案說明', 'scripts' => ['/assets/description-draft.js'],
                'project' => $project,
                'draft' => $content,
                'version' => $version,
                'conflict' => true,
                'latest' => (string)$project['description'],
                'latestVersion' => (int)$project['description_version'],
                'error' => self::CONFLICT_MESSAGE,
            ], 409);
        }
        $this->app->logger->log('data', 'project.description.update', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(),
            "版本 {$version} → {$newVersion}", $id, (string)$project['name']);
        return $this->back("/projects/{$id}", 'success', "專案說明已儲存（版本 {$newVersion}）。");
    }

    public function history(Request $request, int $id): Response
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::VIEW, $id)) {
            return $this->deny('project.history.view', $id);
        }
        $project = (array)$this->projects()->find($id);
        $this->app->logger->log('project', 'project.history.view', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(), null, $id, (string)$project['name']);
        return $this->render('projects/history', [
            'title' => '版本歷史：' . $project['name'],
            'project' => $project,
            'history' => $this->projects()->descriptionHistory($id),
        ]);
    }

    public function addKeyword(Request $request, int $id): Response
    {
        $result = $this->doAddKeyword($request, $id);
        return $result instanceof Response ? $result : $this->back("/projects/{$id}#keywords", $result[0], $result[1]);
    }

    public function deleteKeyword(Request $request, int $id, int $keywordId): Response
    {
        $result = $this->doDeleteKeyword($id, $keywordId);
        return $result instanceof Response ? $result : $this->back("/projects/{$id}#keywords", $result[0], $result[1]);
    }

    /**
     * 局部更新用的 API（專案頁的關鍵字區塊以 fetch 呼叫，不重新載入整頁）。
     * 權限、驗證、紀錄與一般表單完全相同，只是回應改為 JSON，並附上最新的關鍵字清單供畫面重繪。
     */
    public function apiAddKeyword(Request $request, int $id): Response
    {
        $result = $this->doAddKeyword($request, $id);
        return $result instanceof Response ? $result : $this->keywordJson($id, $result);
    }

    public function apiDeleteKeyword(Request $request, int $id, int $keywordId): Response
    {
        $result = $this->doDeleteKeyword($id, $keywordId);
        return $result instanceof Response ? $result : $this->keywordJson($id, $result);
    }

    /** @return Response|array{0:string,1:string} 拒絕時回傳 Response；否則回傳 [提示類型, 訊息] */
    private function doAddKeyword(Request $request, int $id): Response|array
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id)) {
            return $this->deny('keyword.add', $id);
        }
        $keyword = trim(TextNormalizer::clean($request->input('keyword')));
        if ($keyword === '' || str_contains($keyword, "\n")) {
            return ['error', '請輸入關鍵字。'];
        }
        if (mb_strlen($keyword) > KeywordRepository::MAX_LENGTH) {
            return ['error', '關鍵字不可超過 ' . KeywordRepository::MAX_LENGTH . ' 字。'];
        }
        $existing = $this->keywords()->add($id, $keyword, $this->uid());
        if ($existing !== null) {
            return ['warning', "已有相同的關鍵字「{$existing}」，未重複新增。"];
        }
        $this->app->logger->log('data', 'keyword.add', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(), '關鍵字：' . $keyword, $id, $this->projectName($id));
        return ['success', "已新增關鍵字「{$keyword}」。"];
    }

    /** @return Response|array{0:string,1:string} */
    private function doDeleteKeyword(int $id, int $keywordId): Response|array
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id)) {
            return $this->deny('keyword.delete', $id);
        }
        $keyword = $this->keywords()->find($id, $keywordId);
        if ($keyword === null) {
            return $this->deny('keyword.delete', $id);
        }
        $this->keywords()->delete($id, $keywordId);
        $this->app->logger->log('data', 'keyword.delete', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(), '關鍵字：' . $keyword['keyword'], $id, $this->projectName($id));
        return ['success', "已移除關鍵字「{$keyword['keyword']}」。"];
    }

    /** @param array{0:string,1:string} $result */
    private function keywordJson(int $id, array $result): Response
    {
        $list = array_map(static fn(array $k): array => ['id' => (int)$k['id'], 'keyword' => (string)$k['keyword']], $this->keywords()->list($id));
        // 輸入錯誤回 422，其餘（含「已存在」提醒）回 200
        return Response::json(['type' => $result[0], 'message' => $result[1], 'keywords' => $list], $result[0] === 'error' ? 422 : 200);
    }

    public function createNote(Request $request, int $id): Response
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id)) {
            return $this->deny('note.create', $id);
        }
        $attachmentIds = $request->inputList('attachment_ids');
        $content = $this->noteContent($request, $attachmentIds !== []);
        if (is_string($content) === false) {
            return $this->back("/projects/{$id}#notes", 'error', $content[0]);
        }
        $noteId = $this->notes()->create($id, $this->uid(), $content);
        $attached = AttachmentController::attachToNote($this->app, $attachmentIds, $noteId, $id, $this->uid());
        $this->app->logger->log('data', 'note.create', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(),
            "隨手記 #{$noteId}" . ($attached > 0 ? "（附件 {$attached} 個）" : ''), $id, $this->projectName($id));
        return $this->back("/projects/{$id}#notes", 'success', '已新增隨手記。');
    }

    /** 只有作者本人（且具備編輯權限）可以修改；不能修改他人的隨手記（決議 #10）。 */
    public function updateNote(Request $request, int $id, int $noteId): Response
    {
        $note = $this->notes()->find($id, $noteId);
        if ($note === null || !$this->authorizer()->can($this->uid(), Authorizer::EDIT, $id) || (int)$note['author_id'] !== $this->uid()) {
            return $this->deny('note.update', $id);
        }
        $attachmentIds = $request->inputList('attachment_ids');
        $hasAttachments = $attachmentIds !== [] || (new \ProjectHub\Attachment\AttachmentRepository($this->app->db))->countForNote($noteId) > 0;
        $content = $this->noteContent($request, $hasAttachments);
        if (is_string($content) === false) {
            return $this->back("/projects/{$id}#note-{$noteId}", 'error', $content[0]);
        }
        $this->notes()->update($noteId, $content, $this->uid());
        AttachmentController::attachToNote($this->app, $attachmentIds, $noteId, $id, $this->uid());
        $this->app->logger->log('data', 'note.update', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(), "隨手記 #{$noteId}", $id, $this->projectName($id));
        return $this->back("/projects/{$id}#note-{$noteId}", 'success', '隨手記已更新。');
    }

    /** 作者本人（具編輯權限）或具備刪除權限者可以刪除（決議 #10）。 */
    public function deleteNote(Request $request, int $id, int $noteId): Response
    {
        $note = $this->notes()->find($id, $noteId);
        if ($note === null || !$this->canDeleteNote($note, $id)) {
            return $this->deny('note.delete', $id);
        }
        $this->notes()->softDelete($noteId, $this->uid());
        $mine = (int)$note['author_id'] === $this->uid();
        $this->app->logger->log('data', 'note.delete', Log::RESULT_SUCCESS, $this->uid(), $this->app->auth->label(),
            "隨手記 #{$noteId}" . ($mine ? '' : '（他人撰寫）'), $id, $this->projectName($id));
        return $this->back("/projects/{$id}#notes", 'success', '隨手記已刪除。');
    }

    /** JSON API：與頁面套用相同的雙重授權。 */
    public function apiNotes(Request $request, int $id): Response
    {
        if (!$this->authorizer()->can($this->uid(), Authorizer::VIEW, $id)) {
            return $this->deny('note.list', $id);
        }
        $before = $request->query('before');
        $page = $this->notes()->page($id, ctype_digit($before) ? (int)$before : null, self::NOTES_PAGE_SIZE);
        return Response::json([
            'items' => array_map(static fn(array $n): array => [
                'id' => (int)$n['id'], 'author' => (string)$n['author_name'], 'content' => (string)$n['content'], 'created_at' => (string)$n['created_at'],
            ], $page['items']),
            'next_before' => $page['next'],
        ]);
    }

    /** @param array<string, mixed> $note */
    public function canDeleteNote(array $note, int $projectId): bool
    {
        if ((int)$note['author_id'] === $this->uid() && $this->authorizer()->can($this->uid(), Authorizer::EDIT, $projectId)) {
            return true;
        }
        return $this->authorizer()->can($this->uid(), Authorizer::DELETE, $projectId);
    }

    /**
     * 有附件時允許不輸入文字（例如只貼一張錯誤截圖）。
     *
     * @return string|array{0:string} 驗證通過回傳內容，否則回傳 [錯誤訊息]
     */
    private function noteContent(Request $request, bool $hasAttachments = false): string|array
    {
        $content = rtrim(TextNormalizer::clean($request->input('content')));
        if (trim($content) === '' && !$hasAttachments) {
            return ['請輸入隨手記內容或附加檔案。'];
        }
        if (mb_strlen($content) > NoteRepository::MAX_LENGTH) {
            return ['隨手記不可超過 ' . NoteRepository::MAX_LENGTH . ' 字。'];
        }
        return $content;
    }

    private function uid(): int
    {
        return (int)$this->app->auth->userId();
    }

    private function projectName(int $id): string
    {
        return (string)($this->projects()->find($id)['name'] ?? '');
    }

    private function keywords(): KeywordRepository
    {
        return new KeywordRepository($this->app->db);
    }

    private function notes(): NoteRepository
    {
        return new NoteRepository($this->app->db);
    }
}
