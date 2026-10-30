<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\AuthState;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

/**
 * 登入流程：帳號密碼 → TOTP（或強制綁定 2FA）→ 完整登入。
 * 不提供備用驗證碼。
 */
final class AuthController extends Controller
{
    private const GENERIC_LOGIN_ERROR = '帳號或密碼錯誤。';
    private const LOCKED_ERROR = '嘗試次數過多，請稍後再試。';

    public function showLogin(Request $request): Response
    {
        return $this->render('auth/login', ['title' => '登入', 'username' => '']);
    }

    public function login(Request $request): Response
    {
        $username = trim($request->input('username'));
        $password = $request->input('password');
        if ($username === '' || $password === '') {
            return $this->render('auth/login', ['title' => '登入', 'username' => $username, 'error' => '請輸入帳號與密碼。'], 422);
        }

        $key = $this->app->throttle->accountKey($username);
        $user = $this->app->users->findByUsername($username);
        $actorId = $user === null ? null : (int)$user['id'];
        // 帳號不存在時不記錄使用者輸入的字串，避免把誤輸入的密碼寫進紀錄
        $actorLabel = $user === null ? '(未知帳號)' : (string)$user['username'];

        if ($this->app->throttle->isLocked($key, $request->ip())) {
            $this->app->logger->log('auth', 'login.failure', Log::RESULT_DENIED, $actorId, $actorLabel, '嘗試次數過多，暫時鎖定');
            return $this->render('auth/login', ['title' => '登入', 'username' => $username, 'error' => self::LOCKED_ERROR], 429);
        }

        $passwordOk = Passwords::verify($password, $user === null ? null : (string)$user['password_hash']);
        if ($user === null || !$passwordOk || $user['status'] !== 'active') {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_PASSWORD, false);
            $reason = $user === null ? '帳號不存在' : (!$passwordOk ? '密碼錯誤' : '帳號已停用');
            $this->app->logger->log('auth', 'login.failure', Log::RESULT_FAILURE, $actorId, $actorLabel, $reason);
            return $this->render('auth/login', ['title' => '登入', 'username' => $username, 'error' => self::GENERIC_LOGIN_ERROR], 401);
        }

        $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_PASSWORD, true);
        if (Passwords::needsRehash((string)$user['password_hash'])) {
            $this->app->users->updatePasswordHash((int)$user['id'], Passwords::hash($password), false);
        }

        $stage = $this->app->auth->beginSecondFactor($user);
        return Response::redirect($stage === AuthState::ENROLL ? '/2fa/setup' : '/login/totp');
    }

    public function showTotp(Request $request): Response
    {
        return $this->render('auth/totp', ['title' => '兩步驟驗證']);
    }

    public function verifyTotp(Request $request): Response
    {
        $user = (array)$this->app->auth->user();
        $id = (int)$user['id'];
        $key = $this->app->throttle->accountKey((string)$user['username']);

        if ($this->app->throttle->isLocked($key, $request->ip())) {
            $this->app->logger->log('auth', 'login.failure', Log::RESULT_DENIED, $id, (string)$user['username'], '嘗試次數過多，暫時鎖定');
            $this->app->auth->logout();
            return $this->back('/login', 'error', self::LOCKED_ERROR);
        }

        $secret = $this->app->crypto->decrypt((string)$user['totp_secret_enc'], 'totp');
        $lastStep = $user['totp_last_used_step'] === null ? null : (int)$user['totp_last_used_step'];
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), $lastStep, Clock::now());

        if ($step === null || !$this->app->users->consumeTotpStep($id, $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_TOTP, false);
            $this->app->logger->log('auth', 'login.failure', Log::RESULT_FAILURE, $id, (string)$user['username'], '2FA 驗證碼錯誤或重複使用');
            return $this->render('auth/totp', ['title' => '兩步驟驗證', 'error' => '驗證碼錯誤或已使用過，請輸入驗證器上的最新驗證碼。'], 401);
        }

        $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_TOTP, true);
        $this->app->users->touchLogin($id);
        $this->app->auth->complete((int)$user['auth_version']);
        $this->app->logger->log('auth', 'login.success', Log::RESULT_SUCCESS, $id, (string)$user['username']);
        return Response::redirect('/');
    }

    public function showEnroll(Request $request): Response
    {
        $user = (array)$this->app->users->find((int)$this->app->auth->userId());
        $secret = $this->pendingSecret($user);
        return $this->render('auth/enroll', ['title' => '設定兩步驟驗證'] + $this->enrollmentView($user, $secret));
    }

    public function enroll(Request $request): Response
    {
        $user = (array)$this->app->users->find((int)$this->app->auth->userId());
        $id = (int)$user['id'];
        $key = $this->app->throttle->accountKey((string)$user['username']);
        $pending = $user['totp_pending_secret_enc'];

        if ($this->app->throttle->isLocked($key, $request->ip())) {
            $this->app->auth->logout();
            return $this->back('/login', 'error', self::LOCKED_ERROR);
        }
        if (!is_string($pending) || (string)$user['totp_pending_expires_at'] <= Clock::utc()) {
            return $this->back('/2fa/setup', 'error', 'QR Code 已逾時，請重新掃描新的 QR Code。');
        }

        $secret = $this->app->crypto->decrypt($pending, 'totp');
        $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), null, Clock::now());
        if ($step === null || !$this->app->users->activatePendingTotp($id, $pending, $step)) {
            $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_TOTP, false);
            $this->app->logger->log('auth', '2fa.enroll', Log::RESULT_FAILURE, $id, (string)$user['username'], '驗證碼錯誤');
            return $this->back('/2fa/setup', 'error', '驗證碼錯誤，請確認已掃描本頁的 QR Code，並輸入最新的驗證碼。');
        }

        $this->app->throttle->record($key, $request->ip(), LoginThrottle::STAGE_TOTP, true);
        $this->app->users->touchLogin($id);
        $fresh = (array)$this->app->users->find($id);
        $this->app->auth->complete((int)$fresh['auth_version']);
        $this->app->logger->log('auth', '2fa.enroll', Log::RESULT_SUCCESS, $id, (string)$user['username']);
        $this->app->logger->log('auth', 'login.success', Log::RESULT_SUCCESS, $id, (string)$user['username'], '完成 2FA 綁定後登入');
        return $this->back('/', 'success', '兩步驟驗證設定完成。');
    }

    public function logout(Request $request): Response
    {
        $this->app->logger->log('auth', 'logout', Log::RESULT_SUCCESS, $this->app->auth->userId(), $this->app->auth->label());
        $this->app->auth->logout();
        return $this->back('/login', 'success', '已登出。');
    }
}
