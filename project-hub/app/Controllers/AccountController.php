<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

/**
 * 個人帳號設定：修改密碼、查看與更換 2FA。
 * 重要操作都要求重新驗證，且成功後讓其他裝置的 Session 失效。
 */
final class AccountController extends Controller
{
    private const CHANGE_FLAG = 'totp_change_until';

    public function index(Request $request): Response
    {
        return $this->render('account/index', [
            'title' => '帳號設定',
            'user' => $this->app->auth->user(),
            'minLength' => $this->app->settings->int('security.password_min_length'),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        $id = (int)$user['id'];
        $label = (string)$user['username'];
        $key = $this->app->throttle->accountKey($label);

        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return $this->back('/account', 'error', '嘗試次數過多，請稍後再試。');
        }
        $current = $request->input('current_password');
        if (!Passwords::verify($current, (string)$user['password_hash'])) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            $this->app->logger->log('account', 'password.change', Log::RESULT_FAILURE, $id, $label, '目前密碼錯誤');
            return $this->back('/account', 'error', '目前密碼錯誤。');
        }
        $new = $request->input('new_password');
        $error = Passwords::policyError($new, $request->input('new_password_confirm'), $this->app->settings->int('security.password_min_length'));
        if ($error === null && hash_equals($current, $new)) {
            $error = '新密碼不可與目前密碼相同。';
        }
        if ($error !== null) {
            return $this->back('/account', 'error', $error);
        }

        $this->app->users->updatePasswordHash($id, Passwords::hash($new), true);
        $this->app->auth->adoptAuthVersion((int)$this->app->users->find($id)['auth_version']);
        $this->app->logger->log('account', 'password.change', Log::RESULT_SUCCESS, $id, $label);
        return $this->back('/account', 'success', '密碼已更新，其他裝置的登入狀態已失效。');
    }

    /** 更換 2FA 第一步：以目前密碼與目前驗證器的驗證碼確認身分。 */
    public function startTotpChange(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        $id = (int)$user['id'];
        $label = (string)$user['username'];
        $key = $this->app->throttle->accountKey($label);

        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return $this->back('/account', 'error', '嘗試次數過多，請稍後再試。');
        }
        $passwordOk = Passwords::verify($request->input('current_password'), (string)$user['password_hash']);
        $secret = $this->app->crypto->decrypt((string)$user['totp_secret_enc'], 'totp');
        $lastStep = $user['totp_last_used_step'] === null ? null : (int)$user['totp_last_used_step'];
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), $lastStep, Clock::now());

        if (!$passwordOk || $step === null || !$this->app->users->consumeTotpStep($id, $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            $this->app->logger->log('account', '2fa.change', Log::RESULT_FAILURE, $id, $label, '更換前身分驗證失敗');
            return $this->back('/account', 'error', '目前密碼或驗證碼錯誤（同一組驗證碼不能重複使用）。');
        }

        $this->pendingSecret($user, true);
        $this->app->session->set(self::CHANGE_FLAG, Clock::now() + self::PENDING_TOTP_SECONDS);
        return Response::redirect('/account/2fa/change');
    }

    /** 更換 2FA 第二步：顯示新的 QR Code。舊驗證器在完成前仍然有效。 */
    public function showTotpChange(Request $request): Response
    {
        $user = $this->changeContext();
        if ($user === null) {
            return $this->back('/account', 'error', '更換流程已逾時，請重新驗證身分。');
        }
        return $this->render('account/totp_change', ['title' => '更換 2FA 驗證器']
            + $this->enrollmentView($user, $this->pendingSecret($user)));
    }

    /** 更換 2FA 第三步：輸入新驗證器的驗證碼，確認後才原子性替換。 */
    public function confirmTotpChange(Request $request): Response
    {
        $user = $this->changeContext();
        if ($user === null) {
            return $this->back('/account', 'error', '更換流程已逾時，請重新驗證身分。');
        }
        $id = (int)$user['id'];
        $label = (string)$user['username'];
        $key = $this->app->throttle->accountKey($label);
        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return $this->back('/account', 'error', '嘗試次數過多，請稍後再試。');
        }

        $pending = (string)$user['totp_pending_secret_enc'];
        $secret = $this->app->crypto->decrypt($pending, 'totp');
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), null, Clock::now());
        if ($step === null || !$this->app->users->activatePendingTotp($id, $pending, $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            $this->app->logger->log('account', '2fa.change', Log::RESULT_FAILURE, $id, $label, '新驗證器驗證碼錯誤');
            return $this->back('/account/2fa/change', 'error', '新驗證器的驗證碼錯誤，請確認已掃描本頁的 QR Code。');
        }

        $this->app->session->remove(self::CHANGE_FLAG);
        $this->app->auth->adoptAuthVersion((int)$this->app->users->find($id)['auth_version']);
        $this->app->logger->log('account', '2fa.change', Log::RESULT_SUCCESS, $id, $label);
        return $this->back('/account', 'success', '已更換 2FA 驗證器，舊驗證器已失效，其他裝置的登入狀態也已失效。');
    }

    public function apiMe(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        return Response::json([
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'display_name' => (string)$user['display_name'],
            'is_super_admin' => (int)$user['is_super_admin'] === 1,
        ]);
    }

    /** @return array<string, mixed>|null 更換流程仍有效時回傳最新的使用者資料 */
    private function changeContext(): ?array
    {
        $until = $this->app->session->get(self::CHANGE_FLAG);
        if (!is_int($until) || $until < Clock::now()) {
            $this->app->session->remove(self::CHANGE_FLAG);
            return null;
        }
        $user = $this->app->users->find((int)$this->app->auth->userId());
        if ($user === null || !is_string($user['totp_pending_secret_enc']) || (string)$user['totp_pending_expires_at'] <= Clock::utc()) {
            return null;
        }
        return $user;
    }
}
