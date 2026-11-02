<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Attachment\AttachmentRepository;
use ProjectHub\Attachment\LocalFileStorage;
use ProjectHub\Attachment\UploadInspector;
use ProjectHub\Authorization\Authorizer;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Project\NoteRepository;
use Throwable;

/**
 * 隨手記附件（階段 6b，規格七之一）。
 *
 * 所有圖片預覽、原圖、下載與附件 API 每次都重新做雙重授權；網址只含附件 ID，
 * 不提供任何可直接存取的實體路徑，因此撤銷專案權限後原本的網址立即失效。
 */
final class AttachmentController extends ProjectBaseController
{
    /** 上傳：每個請求只處理一個檔案（避開主機 30 MB／60 秒限制），先存為只有本人可見的暫存附件。 */
    public function upload(Request $request, int $projectId): Response
    {
        $uid = $this->uid();
        if (!$this->authorizer()->can($uid, Authorizer::EDIT, $projectId)) {
            return $this->deny('attachment.upload', $projectId);
        }
        $this->cleanupExpired();
        $file = $request->file('file');
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $this->fail('沒有收到檔案。');
        }
        $maxBytes = $this->app->settings->int('attachments.max_file_mb') * 1024 * 1024;
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $maxBytes) {
            return $this->fail('檔案超過 ' . $this->app->settings->int('attachments.max_file_mb') . ' MB 上限。');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $this->fail('上傳失敗，請重試。');
        }
        $name = self::safeName($file['name']);
        $usage = $this->attachments()->usage();
        if ($usage['bytes'] + $file['size'] > $this->app->settings->int('attachments.quota_mb') * 1024 * 1024
            || $usage['files'] >= $this->app->settings->int('attachments.max_files')) {
            $this->log('attachment.upload', $projectId, '總容量或檔案數已達上限', Log::RESULT_FAILURE);
            return $this->fail('附件空間已達系統上限，請聯絡最高權限管理者。');
        }

        $check = UploadInspector::inspect($file['tmp_name'], $name);
        if (is_string($check)) {
            $this->log('attachment.upload', $projectId, '拒絕：' . $check . '（' . $name . '）', Log::RESULT_FAILURE);
            return $this->fail($check);
        }

        $storage = $this->storage();
        $stored = bin2hex(random_bytes(32));
        $previewStored = null;
        $previewMime = null;
        $width = null;
        $height = null;
        $source = $file['tmp_name'];
        $temps = [];
        try {
            if ($check['kind'] === 'image') {
                $img = UploadInspector::processImage($file['tmp_name'], $check['mime'], $this->app->storagePath() . '/tmp');
                if (is_string($img)) {
                    $this->log('attachment.upload', $projectId, '拒絕：' . $img . '（' . $name . '）', Log::RESULT_FAILURE);
                    return $this->fail($img);
                }
                $source = $img['original'];
                $temps = array_unique([$img['original'], $img['preview']]);
                $previewStored = bin2hex(random_bytes(32));
                $previewMime = $img['preview_mime'];
                $width = $img['width'];
                $height = $img['height'];
                $storage->put($previewStored, $img['preview']);
            }
            $sha = hash_file('sha256', $source, true);
            $size = (int)filesize($source);
            $storage->put($stored, $source);
        } catch (Throwable $e) {
            foreach ($temps as $t) {
                @unlink($t);
            }
            if ($previewStored !== null) {
                $storage->delete($previewStored);
            }
            throw $e;
        }

        $id = $this->attachments()->createPending([
            'project_id' => $projectId, 'uploader_id' => $uid, 'original_name' => $name, 'kind' => $check['kind'],
            'mime_type' => $check['mime'], 'size_bytes' => $size, 'sha256' => $sha, 'storage_driver' => $storage->driver(),
            'stored_name' => $stored, 'preview_stored_name' => $previewStored, 'preview_mime_type' => $previewMime,
            'image_width' => $width, 'image_height' => $height,
        ]);
        $this->log('attachment.upload', $projectId, "附件 #{$id}：{$name}（" . self::humanSize($size) . '）');
        return Response::json([
            'id' => $id,
            'name' => $name,
            'kind' => $check['kind'],
            'label' => UploadInspector::label($check['mime']),
            'size' => self::humanSize($size),
            'preview_url' => $check['kind'] === 'image' ? "/attachments/{$id}/preview" : null,
        ]);
    }

    /** 在儲存隨手記前移除剛上傳的暫存附件：只有上傳者本人可以。 */
    public function discard(Request $request, int $id): Response
    {
        $att = $this->attachments()->find($id);
        if ($att === null || $att['status'] !== 'pending' || (int)$att['uploader_id'] !== $this->uid()) {
            return Response::json(['error' => '找不到資源'], 404);
        }
        $this->remove($att);
        return Response::json(['ok' => true]);
    }

    public function preview(Request $request, int $id): Response
    {
        $att = $this->accessible($id);
        if ($att === null || $att['kind'] !== 'image' || $att['preview_stored_name'] === null) {
            return $this->denyAttachment('attachment.preview', $id);
        }
        // 預覽圖數量多，不逐張記錄，避免紀錄爆量（決議見 docs/08 第 7 節）
        return $this->serve((string)$att['preview_stored_name'], (string)$att['preview_mime_type'], null);
    }

    public function original(Request $request, int $id): Response
    {
        $att = $this->accessible($id);
        if ($att === null || $att['kind'] !== 'image') {
            return $this->denyAttachment('attachment.view', $id);
        }
        $this->log('attachment.view', (int)$att['project_id'], "附件 #{$id}：{$att['original_name']}");
        return $this->serve((string)$att['stored_name'], (string)$att['mime_type'], null);
    }

    public function download(Request $request, int $id): Response
    {
        $att = $this->accessible($id);
        if ($att === null) {
            return $this->denyAttachment('attachment.download', $id);
        }
        $this->log('attachment.download', (int)$att['project_id'], "附件 #{$id}：{$att['original_name']}");
        return $this->serve((string)$att['stored_name'], (string)$att['mime_type'], (string)$att['original_name']);
    }

    /** 刪除已綁定的附件：與刪除隨手記相同的規則（作者本人具編輯權限，或具刪除權限）。 */
    public function delete(Request $request, int $projectId, int $id): Response
    {
        $att = $this->attachments()->find($id);
        $note = $att === null || $att['note_id'] === null ? null : (new NoteRepository($this->app->db))->find($projectId, (int)$att['note_id']);
        if ($att === null || $note === null || (int)$att['project_id'] !== $projectId || !$this->canModifyNote($note, $projectId)) {
            return $this->deny('attachment.delete', $projectId);
        }
        $this->remove($att);
        $this->log('attachment.delete', $projectId, "附件 #{$id}：{$att['original_name']}");
        return $this->back("/projects/{$projectId}#note-{$note['id']}", 'success', "已刪除附件「{$att['original_name']}」。");
    }

    /**
     * 綁定暫存附件到隨手記（由 ProjectContentController 在新增／修改隨手記時呼叫）。
     *
     * @param list<string> $rawIds
     */
    public static function attachToNote(\ProjectHub\App $app, array $rawIds, int $noteId, int $projectId, int $uid): int
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($rawIds, 'ctype_digit'))));
        if ($ids === []) {
            return 0;
        }
        $repo = new AttachmentRepository($app->db);
        $room = $app->settings->int('attachments.max_per_note') - $repo->countForNote($noteId);
        return $repo->attach(array_slice($ids, 0, max(0, $room)), $noteId, $projectId, $uid);
    }

    /** @param array<string, mixed> $note */
    private function canModifyNote(array $note, int $projectId): bool
    {
        $uid = $this->uid();
        if ((int)$note['author_id'] === $uid && $this->authorizer()->can($uid, Authorizer::EDIT, $projectId)) {
            return true;
        }
        return $this->authorizer()->can($uid, Authorizer::DELETE, $projectId);
    }

    /** @return array<string, mixed>|null 目前使用者有權讀取的附件 */
    private function accessible(int $id): ?array
    {
        $att = $this->attachments()->find($id);
        if ($att === null) {
            return null;
        }
        $projectId = (int)$att['project_id'];
        if ($att['status'] === 'pending') {
            // 暫存附件只有上傳者本人（且仍有編輯權限）看得到
            return (int)$att['uploader_id'] === $this->uid() && $this->authorizer()->can($this->uid(), Authorizer::EDIT, $projectId) ? $att : null;
        }
        $note = $att['note_id'] === null ? null : (new NoteRepository($this->app->db))->find($projectId, (int)$att['note_id']);
        if ($note === null || !$this->authorizer()->can($this->uid(), Authorizer::VIEW, $projectId)) {
            return null;
        }
        return $att;
    }

    private function serve(string $stored, string $mime, ?string $downloadName): Response
    {
        $storage = $this->storage();
        if (!$storage->exists($stored)) {
            return Response::html($this->app->plainPage('找不到檔案', '檔案已不存在。'), 404);
        }
        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string)$storage->size($stored),
            'X-Content-Type-Options' => 'nosniff',
            // 即使檔案內容被瀏覽器誤判為網頁，也不允許執行任何腳本或載入任何資源
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            // 撤銷權限後不可從快取取得；只允許使用者自己的瀏覽器短暫保留
            'Cache-Control' => 'private, no-store',
        ];
        if ($downloadName !== null) {
            $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'download';
            $headers['Content-Disposition'] = 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName);
        } else {
            $headers['Content-Disposition'] = 'inline';
        }
        return Response::stream(static function () use ($storage, $stored): void {
            $storage->stream($stored);
        }, $headers);
    }

    /** @param array<string, mixed> $att */
    private function remove(array $att): void
    {
        $this->attachments()->markDeleted((int)$att['id'], $this->uid());
        $this->storage()->delete((string)$att['stored_name']);
        if ($att['preview_stored_name'] !== null) {
            $this->storage()->delete((string)$att['preview_stored_name']);
        }
    }

    /** 無 Cron：上傳時順便清理過期的暫存附件，每次最多 20 個。 */
    private function cleanupExpired(): void
    {
        foreach ($this->attachments()->expiredPending(20) as $att) {
            $this->attachments()->markDeleted((int)$att['id'], $this->uid());
            $this->storage()->delete((string)$att['stored_name']);
            if ($att['preview_stored_name'] !== null) {
                $this->storage()->delete((string)$att['preview_stored_name']);
            }
        }
    }

    private function denyAttachment(string $action, int $attachmentId): Response
    {
        $att = $this->attachments()->find($attachmentId);
        $this->app->logger->log('project', $action, Log::RESULT_DENIED, $this->uid(), $this->app->auth->label(),
            "附件 #{$attachmentId}", $att === null ? null : (int)$att['project_id']);
        return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
    }

    private function fail(string $message): Response
    {
        return Response::json(['error' => $message], 422);
    }

    private function log(string $action, int $projectId, ?string $detail, string $result = Log::RESULT_SUCCESS): void
    {
        $this->app->logger->log('data', $action, $result, $this->uid(), $this->app->auth->label(), $detail, $projectId, $this->projectName($projectId));
    }

    private function projectName(int $id): ?string
    {
        $p = $this->projects()->find($id);
        return $p === null ? null : (string)$p['name'];
    }

    /** 原始檔名只用於顯示：移除路徑與控制字元，限制長度。 */
    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name) === '' ? 'file' : trim($name);
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 190) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }

    private function uid(): int
    {
        return (int)$this->app->auth->userId();
    }

    private function attachments(): AttachmentRepository
    {
        return new AttachmentRepository($this->app->db);
    }

    private function storage(): LocalFileStorage
    {
        return new LocalFileStorage($this->app->storagePath() . '/uploads');
    }
}
