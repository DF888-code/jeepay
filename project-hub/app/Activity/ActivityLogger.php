<?php

declare(strict_types=1);

namespace ProjectHub\Activity;

use ProjectHub\Http\Request;
use ProjectHub\Support\Database;
use Throwable;

/**
 * 歷史操作紀錄：只由伺服器端呼叫。
 *
 * 注意：$detail 只能放程式內固定的說明文字或非機密的識別資訊；
 * 絕不可傳入密碼、驗證碼、Token、2FA Secret、隨手記或專案說明內文。
 */
final class ActivityLogger
{
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';
    public const RESULT_DENIED = 'denied';

    public function __construct(private Database $db, private ?Request $request = null)
    {
    }

    public function log(
        string $category,
        string $action,
        string $result,
        ?int $actorId,
        string $actorLabel,
        ?string $detail = null,
        ?int $projectId = null,
        ?string $projectLabel = null,
        ?int $targetUserId = null
    ): void {
        $t = microtime(true);
        $occurredAt = gmdate('Y-m-d H:i:s', (int)$t) . sprintf('.%03d', (int)(($t - floor($t)) * 1000));
        try {
            $this->db->run(
                'INSERT INTO activity_logs
                    (occurred_at, actor_user_id, actor_label, category, action, result,
                     project_id, project_label, target_user_id, detail, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $occurredAt,
                    $actorId,
                    mb_substr($actorLabel, 0, 100),
                    $category,
                    $action,
                    $result,
                    $projectId,
                    $projectLabel === null ? null : mb_substr($projectLabel, 0, 200),
                    $targetUserId,
                    $detail === null ? null : mb_substr($detail, 0, 500),
                    $this->request?->ip(),
                    $this->request?->userAgent(),
                ]
            );
        } catch (Throwable $e) {
            // 紀錄失敗不應中斷使用者操作，但必須留下伺服器錯誤日誌（不含請求內容）
            error_log('[project-hub] 寫入歷史操作紀錄失敗：' . $action . ' / ' . get_class($e));
        }
    }
}
