<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Mail\Message;
use ProjectHub\Recovery\RecoveryTokenRepository;
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
        $user = (array)$this->app->auth->user();
        $isAdmin = (int)$user['is_super_admin'] === 1;
        return $this->render('account/index', [
            'title' => '帳號設定',
            'user' => $user,
            'minLength' => $this->app->settings->int('security.password_min_length'),
            'isSuperAdmin' => $isAdmin,
            'recoveryPending' => $isAdmin ? (new RecoveryTokenRepository($this->app->db))->pending((int)$user['id']) : [],
            'mailEnabled' => $isAdmin && $this->app->mailer()->enabled(),
            'baseUrlSet' => $this->app->hasConfiguredBaseUrl(),
        ]);
    }

    /**
     * 變更救援 Email（只有最高權限管理者）：每次都要輸入目前驗證碼，完成後通知新舊 Email，
     * 並讓尚未使用的救援連結失效（docs/04 第 3.3 節）。
     */
    public function changeRecoveryEmail(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        if ((int)$user['is_super_admin'] !== 1) {
            return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
        }
        $id = (int)$user['id'];
        $label = (string)$user['username'];
        $key = $this->app->throttle->accountKey($label);
        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return $this->back('/account', 'error', '嘗試次數過多，請稍後再試。');
        }
        $email = trim($request->input('email'));
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->back('/account', 'error', '救援 Email 格式不正確。');
        }
        $secret = $this->app->crypto->decrypt((string)$user['totp_secret_enc'], 'totp');
        $lastStep = $user['totp_last_used_step'] === null ? null : (int)$user['totp_last_used_step'];
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), $lastStep, Clock::now());
        if ($step === null || !$this->app->users->consumeTotpStep($id, $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            $this->app->logger->log('security', 'recovery.email_change', Log::RESULT_FAILURE, $id, $label, '驗證碼錯誤');
            return $this->back('/account', 'error', '驗證碼錯誤或已使用過，請輸入驗證器上的最新驗證碼。');
        }
        $old = (string)($user['email'] ?? '');
        if (strcasecmp($old, $email) === 0) {
            return $this->back('/account', 'success', '救援 Email 沒有變更。');
        }
        $this->app->users->updateProfile($id, (string)$user['display_name'], $email);
        (new RecoveryTokenRepository($this->app->db))->invalidateAll($id);
        $this->app->logger->log('security', 'recovery.email_change', Log::RESULT_SUCCESS, $id, $label,
            ($old === '' ? '（未設定）' : self::maskEmail($old)) . ' → ' . self::maskEmail($email));

        $site = $this->app->settings->string('system.site_name');
        $text = "您好：\n\n{$site} 管理者帳號「{$label}」的救援 Email 已變更為 " . self::maskEmail($email) . "。\n"
            . '變更時間：' . Clock::display(Clock::utc(), $this->app->settings->string('system.timezone')) . '，來源 IP：' . $request->ip() . "\n\n"
            . '若這不是您本人的操作，表示管理者帳號可能已被他人使用，請立即處理。';
        $sent = true;
        foreach (array_unique(array_filter([$old, $email])) as $to) {
            try {
                $this->app->mailer()->send(new Message($to, "【{$site}】救援 Email 已變更", $text));
            } catch (\Throwable $e) {
                $sent = false;
                error_log('[project-hub] 救援 Email 變更通知寄送失敗：' . $e->getMessage());
            }
        }
        return $this->back('/account', 'success', '救援 Email 已更新，尚未使用的救援連結已全部失效。'
            . ($sent ? '已寄送通知到新舊 Email。' : '（通知信寄送失敗，請檢查寄信設定。）'));
    }

    /** 取消所有尚未使用的救援申請（本人仍能登入時，用來阻止他人冒用救援）。 */
    public function cancelRecovery(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        if ((int)$user['is_super_admin'] !== 1) {
            return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
        }
        $n = (new RecoveryTokenRepository($this->app->db))->invalidateAll((int)$user['id']);
        $this->app->logger->log('security', 'recovery.cancel', Log::RESULT_SUCCESS, (int)$user['id'], (string)$user['username'], "取消 {$n} 個救援申請");
        return $this->back('/account', 'success', $n > 0 ? "已取消 {$n} 個救援申請，信中的連結已失效。" : '目前沒有待處理的救援申請。');
    }

    /** 紀錄中只保存部分遮蔽的 Email，避免紀錄本身成為個資外洩來源 */
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
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

    /** 立即登出此帳號在其他裝置與瀏覽器的登入狀態，本裝置保持登入。 */
    public function revokeOtherSessions(Request $request): Response
    {
        $id = (int)$this->app->auth->userId();
        $this->app->auth->adoptAuthVersion($this->app->users->bumpAuthVersion($id));
        $this->app->logger->log('account', 'sessions.revoke_others', Log::RESULT_SUCCESS, $id, $this->app->auth->label());
        return $this->back('/account', 'success', '已登出其他所有裝置。');
    }

    /** 管理者建立帳號或重設密碼後，本人必須先把臨時密碼換成自己的密碼。 */
    public function showRequiredPassword(Request $request): Response
    {
        return $this->render('account/password_required', [
            'title' => '請變更密碼',
            'minLength' => $this->app->settings->int('security.password_min_length'),
        ]);
    }

    public function changeRequiredPassword(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        $id = (int)$user['id'];
        $label = (string)$user['username'];
        $key = $this->app->throttle->accountKey($label);
        if ($this->app->throttle->isLocked($key, $request->ip())) {
            return $this->back('/account/password-required', 'error', '嘗試次數過多，請稍後再試。');
        }
        $current = $request->input('current_password');
        if (!Passwords::verify($current, (string)$user['password_hash'])) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_REAUTH, false);
            return $this->back('/account/password-required', 'error', '臨時密碼錯誤。');
        }
        $new = $request->input('new_password');
        $error = Passwords::policyError($new, $request->input('new_password_confirm'), $this->app->settings->int('security.password_min_length'));
        if ($error === null && hash_equals($current, $new)) {
            $error = '新密碼不可與臨時密碼相同。';
        }
        if ($error !== null) {
            return $this->back('/account/password-required', 'error', $error);
        }
        $this->app->users->completeRequiredPasswordChange($id, Passwords::hash($new));
        $this->app->auth->adoptAuthVersion((int)$this->app->users->find($id)['auth_version']);
        $this->app->logger->log('account', 'password.change', Log::RESULT_SUCCESS, $id, $label, '變更管理者提供的臨時密碼');
        return $this->back('/', 'success', '密碼已變更，歡迎使用。');
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
