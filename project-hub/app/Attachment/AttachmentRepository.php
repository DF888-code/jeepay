<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use ProjectHub\Search\TextNormalizer;
use ProjectHub\Support\Clock;
use ProjectHub\Support\Database;

final class AttachmentRepository
{
    /** 未綁定到隨手記的暫存附件保留時間 */
    public const PENDING_TTL_SECONDS = 86400;

    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function createPending(array $data): int
    {
        $this->db->run(
            'INSERT INTO note_attachments
                (project_id, uploader_id, original_name, original_name_norm, kind, mime_type, size_bytes, sha256, storage_driver,
                 stored_name, preview_stored_name, preview_mime_type, image_width, image_height, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)',
            [
                $data['project_id'], $data['uploader_id'], $data['original_name'], TextNormalizer::normalize((string)$data['original_name']),
                $data['kind'], $data['mime_type'], $data['size_bytes'], $data['sha256'], $data['storage_driver'], $data['stored_name'],
                $data['preview_stored_name'], $data['preview_mime_type'], $data['image_width'], $data['image_height'], Clock::utc(),
            ]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM note_attachments WHERE id = ? AND status <> \'deleted\'', [$id]);
    }

    /**
     * 把暫存附件綁定到隨手記。只接受「同一位上傳者、同一個專案、仍在暫存期限內」的附件，
     * 因此無法把別人的檔案或其他專案的檔案掛到自己的隨手記上。
     *
     * @param list<int> $ids
     * @return int 實際綁定的數量
     */
    public function attach(array $ids, int $noteId, int $projectId, int $uploaderId): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run(
            "UPDATE note_attachments SET note_id = ?, status = 'attached', attached_at = ?
              WHERE id IN ({$placeholders}) AND status = 'pending' AND project_id = ? AND uploader_id = ? AND created_at > ?",
            array_merge([$noteId, Clock::utc()], $ids, [$projectId, $uploaderId, Clock::utc(Clock::now() - self::PENDING_TTL_SECONDS)])
        )->rowCount();
    }

    /**
     * @param list<int> $noteIds
     * @return array<int, list<array<string, mixed>>> 隨手記 ID → 附件
     */
    public function forNotes(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $rows = $this->db->all(
            "SELECT id, note_id, original_name, kind, mime_type, size_bytes, image_width, image_height
               FROM note_attachments WHERE note_id IN ({$placeholders}) AND status = 'attached' ORDER BY id",
            $noteIds
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['note_id']][] = $r;
        }
        return $map;
    }

    public function countForNote(int $noteId): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM note_attachments WHERE note_id = ? AND status = 'attached'", [$noteId]);
    }

    /** @return array{bytes:int, files:int} 目前占用（含暫存，不含已刪除） */
    public function usage(): array
    {
        $row = (array)$this->db->one("SELECT COALESCE(SUM(size_bytes), 0) AS bytes, COUNT(*) AS files FROM note_attachments WHERE status <> 'deleted'");
        return ['bytes' => (int)$row['bytes'], 'files' => (int)$row['files']];
    }

    public function markDeleted(int $id, int $userId): bool
    {
        return $this->db->run(
            "UPDATE note_attachments SET status = 'deleted', deleted_at = ?, deleted_by = ? WHERE id = ? AND status <> 'deleted'",
            [Clock::utc(), $userId, $id]
        )->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> 過期的暫存附件（供分批清理） */
    public function expiredPending(int $limit): array
    {
        return $this->db->all(
            "SELECT id, stored_name, preview_stored_name FROM note_attachments WHERE status = 'pending' AND created_at < ? ORDER BY id LIMIT {$limit}",
            [Clock::utc(Clock::now() - self::PENDING_TTL_SECONDS)]
        );
    }
}
