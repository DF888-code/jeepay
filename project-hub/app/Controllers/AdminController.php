<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

/**
 * 管理後台控制器的共用功能：管理者再驗證、操作對象紀錄。
 * 路由層已限制只有最高權限管理者能進入。
 */
abstract class AdminController extends Controller
{
    protected const REAUTH_KEY = 'admin_reauth_until';
    protected const REAUTH_SECONDS = 600;

    /** 再驗證後可返回的頁面（白名單，避免開放式轉址） */
    protected static function safeReturn(string $path): string
    {
        return preg_match('#^/admin/(users|permissions|projects(/[1-9][0-9]{0,8}/permissions)?)$#', $path) ? $path : '/admin/users';
    }

    protected function elevated(): bool
    {
        $until = $this->app->session->get(self::REAUTH_KEY);
        return is_int($until) && $until >= Clock::now();
    }

    protected function requireElevated(string $return): ?Response
    {
        if ($this->elevated()) {
            return null;
        }
        return $this->back($return, 'error', '此操作需要先輸入您的驗證碼完成身分驗證。');
    }

    /**
     * 驗證管理者自己目前的驗證碼（並標記為已使用，同一組碼不能重複使用）。
     * 回傳錯誤訊息；驗證成功回傳 null。
     */
    protected function verifyAdminCode(Request $request): ?string
    {
        $admin = $this->admin();
        $key = $this->app->throttle->accountKey((string)$admin['username']);
        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return '嘗試次數過多，請稍後再試。';
        }
        $secret = $this->app->crypto->decrypt((string)$admin['totp_secret_enc'], 'totp');
        $lastStep = $admin['totp_last_used_step'] === null ? null : (int)$admin['totp_last_used_step'];
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), $lastStep, Clock::now());
        if ($step === null || !$this->app->users->consumeTotpStep((int)$admin['id'], $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            return '驗證碼錯誤或已使用過，請輸入驗證器上的最新驗證碼（同一組驗證碼只能使用一次）。';
        }
        return null;
    }

    /** @return array<string, mixed> */
    protected function admin(): array
    {
        return (array)$this->app->auth->user();
    }

    protected function log(string $action, ?string $detail, ?int $targetUserId = null, ?int $projectId = null, ?string $projectLabel = null, string $result = Log::RESULT_SUCCESS): void
    {
        $admin = $this->admin();
        $this->app->logger->log('admin', $action, $result, (int)$admin['id'], (string)$admin['username'], $detail, $projectId, $projectLabel, $targetUserId);
    }

    protected function notFound(): Response
    {
        return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
    }
}
