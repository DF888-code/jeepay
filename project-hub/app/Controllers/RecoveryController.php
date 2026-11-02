<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Mail\MailException;
use ProjectHub\Mail\Message;
use ProjectHub\Recovery\RecoveryTokenRepository;
use ProjectHub\Support\Clock;
use Throwable;

/**
 * 最高權限管理者 Email 救援（階段 10，規格第六節；設計見 docs/04 第 3 節）。
 *
 * 安全要點：
 * - 申請頁對任何輸入都回應同一句話，不透露帳號或 Email 是否存在、寄信是否成功；
 * - 信件連結（GET）只顯示確認頁、不改任何狀態，郵件掃描器預先開啟連結不會完成救援；
 * - 完成時以原子性 UPDATE 使用 Token，並與重設密碼／2FA 在同一個交易提交；
 * - 救援完成不建立登入狀態，一律回到登入頁從頭走密碼＋2FA；
 * - Token、密碼、驗證碼不寫入任何紀錄或錯誤訊息。
 */
final class RecoveryController extends Controller
{
    private const STAGE_REQUEST = 'recovery_request';
    private const GENERIC_SENT = '若帳號與 Email 正確，系統已寄出救援信，請在有效時間內開啟信中的連結。沒收到信請檢查垃圾郵件匣，或稍後再試。';

    public const PURPOSE_LABELS = [
        'reset_password' => '忘記密碼（驗證器仍在）',
        'reset_2fa' => '遺失驗證器（記得密碼）',
        'reset_both' => '密碼與驗證器都遺失',
    ];

    public function showRequest(Request $request): Response
    {
        return $this->render('recovery/request', ['title' => '管理者救援', 'purposes' => self::PURPOSE_LABELS, 'old' => []]);
    }

    public function request(Request $request): Response
    {
        $identifier = trim($request->input('account'));
        $purpose = $request->input('purpose');
        if ($identifier === '' || mb_strlen($identifier) > 254 || !isset(self::PURPOSE_LABELS[$purpose])) {
            return $this->render('recovery/request', [
                'title' => '管理者救援', 'purposes' => self::PURPOSE_LABELS,
                'old' => ['purpose' => $purpose], 'error' => '請輸入帳號或 Email，並選擇救援情境。',
            ], 422);
        }

        $ip = $request->ip();
        $key = $this->app->throttle->accountKey($identifier);
        $limited = $this->limited($key, $ip);
        // 申請紀錄以「成功」寫入：只用來計算申請頻率，不能影響登入失敗鎖定
        $this->app->throttle->record($key, $ip, self::STAGE_REQUEST, true);

        $user = $this->app->db->one(
            "SELECT * FROM users WHERE is_super_admin = 1 AND status = 'active'
                AND (username_norm = ? OR (email IS NOT NULL AND LOWER(email) = LOWER(?))) ORDER BY id LIMIT 1",
            [\ProjectHub\Auth\UserRepository::normalizeUsername($identifier), $identifier]
        );
        $label = self::PURPOSE_LABELS[$purpose];
        if ($user === null) {
            // 不保存輸入的原文：可能是誤填的密碼
            $this->logSecurity('recovery.request', Log::RESULT_DENIED, null, '(未知帳號)', "{$label}：帳號或 Email 不符");
        } elseif ($limited) {
            $this->logSecurity('recovery.request', Log::RESULT_DENIED, (int)$user['id'], (string)$user['username'], "{$label}：申請次數超過上限，未寄信");
        } elseif ((string)($user['email'] ?? '') === '' || !$this->app->mailer()->enabled()) {
            $this->logSecurity('recovery.request', Log::RESULT_FAILURE, (int)$user['id'], (string)$user['username'], "{$label}：未設定救援 Email 或寄信方式，無法寄信");
        } elseif ($purpose === 'reset_password' && (int)$user['totp_enabled'] !== 1) {
            // 沒有驗證器時無法用「忘記密碼」完成，直接告知會讓帳號被探測；只記錄
            $this->logSecurity('recovery.request', Log::RESULT_FAILURE, (int)$user['id'], (string)$user['username'], "{$label}：帳號尚未綁定驗證器，請改選「密碼與驗證器都遺失」");
        } else {
            $this->issue($user, $purpose, $ip);
        }
        return $this->back('/recovery', 'success', self::GENERIC_SENT);
    }

    /** 信件連結：只顯示確認頁，不做任何狀態變更。 */
    public function showConfirm(Request $request): Response
    {
        return $this->confirmPage($request->query('token'), null, 200, true);
    }

    public function confirm(Request $request): Response
    {
        $token = $request->input('token');
        $repo = $this->tokens();
        $row = $repo->find($token);
        $status = RecoveryTokenRepository::status($row, $this->cooldown());
        $user = $row === null ? null : $this->app->users->find((int)$row['user_id']);
        if ($status !== 'ok' || $user === null || (int)$user['is_super_admin'] !== 1 || $user['status'] !== 'active') {
            return $this->confirmPage($token, null, 200, true);
        }
        $purpose = (string)$row['purpose'];
        $uid = (int)$user['id'];
        $username = (string)$user['username'];
        $throttleKey = $this->app->throttle->accountKey($username);
        if ($this->app->throttle->isLocked($throttleKey, $request->ip())) {
            return $this->confirmPage($token, '嘗試次數過多，請稍後再試。', 429);
        }

        $newPassword = $request->input('new_password');
        if ($purpose !== 'reset_2fa') {
            $error = Passwords::policyError($newPassword, $request->input('new_password_confirm'), $this->app->settings->int('security.password_min_length'));
            if ($error !== null) {
                return $this->confirmPage($token, $error, 422);
            }
        }
        $factorError = $this->verifyFactor($purpose, $user, $request);
        if ($factorError !== null) {
            $this->app->throttle->record($throttleKey, $request->ip(), LoginThrottle::STAGE_RECOVERY, false);
            $this->logSecurity('recovery.complete', Log::RESULT_FAILURE, $uid, $username, self::PURPOSE_LABELS[$purpose] . '：' . $factorError);
            return $this->confirmPage($token, $factorError, 422);
        }

        $hash = $purpose === 'reset_2fa' ? null : Passwords::hash($newPassword);
        $done = $this->app->db->transaction(function () use ($repo, $token, $purpose, $uid, $hash): bool {
            if (!$repo->consume($token, $purpose, $this->cooldown())) {
                return false; // 同時送出兩次、或剛好過期：只有一次會成功
            }
            if ($hash !== null) {
                $this->app->users->completeRequiredPasswordChange($uid, $hash);
            }
            if ($purpose !== 'reset_password') {
                $this->app->users->resetTotp($uid);
            }
            $repo->invalidateAll($uid);
            return true;
        });
        if (!$done) {
            return $this->confirmPage($token, null, 200, true);
        }

        $this->logSecurity('recovery.complete', Log::RESULT_SUCCESS, $uid, $username, self::PURPOSE_LABELS[$purpose]);
        $this->notifyCompleted($user, $purpose, $request->ip());
        // 不建立登入狀態；若此瀏覽器原本登入了其他帳號，也一併登出
        $this->app->session->clearAuth();
        return $this->back('/login', 'success', match ($purpose) {
            'reset_password' => '密碼已重設，請用新密碼與驗證器登入。',
            'reset_2fa' => '已清除原本的驗證器，請用密碼登入後重新綁定 Google Authenticator。',
            default => '密碼已重設、原本的驗證器已清除，請用新密碼登入後重新綁定 Google Authenticator。',
        });
    }

    /** @param array<string, mixed> $user */
    private function verifyFactor(string $purpose, array $user, Request $request): ?string
    {
        if ($purpose === 'reset_2fa') {
            return Passwords::verify($request->input('current_password'), (string)$user['password_hash']) ? null : '目前密碼錯誤。';
        }
        if ($purpose === 'reset_password') {
            if ((int)$user['totp_enabled'] !== 1 || !is_string($user['totp_secret_enc'])) {
                return '此帳號尚未綁定驗證器，請改用「密碼與驗證器都遺失」重新申請。';
            }
            $secret = $this->app->crypto->decrypt((string)$user['totp_secret_enc'], 'totp');
            $last = $user['totp_last_used_step'] === null ? null : (int)$user['totp_last_used_step'];
            $step = Totp::match($secret, $request->input('code'), $this->totpWindow(), $last, Clock::now());
            return $step !== null && $this->app->users->consumeTotpStep((int)$user['id'], $step)
                ? null : '驗證碼錯誤或已使用過，請輸入驗證器上的最新驗證碼。';
        }
        return null; // reset_both：只有 Email，已由冷卻時間與通知補強
    }

    /** @param array<string, mixed> $user */
    private function issue(array $user, string $purpose, string $ip): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Clock::now();
        $cooldown = $purpose === 'reset_both' ? $this->cooldown() : 0;
        $expires = $now + $cooldown + $this->app->settings->int('recovery.token_minutes') * 60;
        $repo = $this->tokens();
        $repo->create((int)$user['id'], $purpose, $token, $expires, $ip);

        $tz = $this->app->settings->string('system.timezone');
        $site = $this->app->settings->string('system.site_name');
        $link = $this->app->baseUrl() . '/recovery/confirm?token=' . $token;
        $lines = [
            "{$user['display_name']} 您好：",
            '',
            "有人在 {$site} 申請最高權限管理者救援：" . self::PURPOSE_LABELS[$purpose] . '。',
            '申請時間：' . Clock::display(Clock::utc($now), $tz, 'Y-m-d H:i') . "（{$tz}）",
            '申請來源 IP：' . $ip,
            '',
        ];
        if ($cooldown > 0) {
            $lines[] = '為了安全，此連結需等待 ' . intdiv($cooldown, 60) . ' 分鐘，' . Clock::display(Clock::utc($now + $cooldown), $tz, 'H:i') . ' 之後才能使用。';
            $lines[] = '若不是您本人申請，請盡快以其他方式登入並到「帳號設定」取消，或確認信箱是否遭他人存取。';
        }
        $lines[] = '請開啟以下連結完成救援（' . Clock::display(Clock::utc($expires), $tz, 'H:i') . ' 前有效，只能使用一次）：';
        $lines[] = $link;
        $lines[] = '';
        $lines[] = '若不是您本人申請，請忽略此信，您的帳號不會有任何變更。';
        try {
            $this->app->mailer()->send(new Message((string)$user['email'], "【{$site}】管理者救援連結", implode("\n", $lines)));
            $this->logSecurity('recovery.request', Log::RESULT_SUCCESS, (int)$user['id'], (string)$user['username'], self::PURPOSE_LABELS[$purpose] . '：已寄出救援信');
        } catch (MailException $e) {
            // 寄不出去的連結作廢；錯誤原因只給管理紀錄（不含 Token）
            $repo->invalidateAll((int)$user['id']);
            $this->logSecurity('recovery.request', Log::RESULT_FAILURE, (int)$user['id'], (string)$user['username'], self::PURPOSE_LABELS[$purpose] . '：寄送失敗（' . mb_substr($e->getMessage(), 0, 200) . '）');
        }
    }

    /** @param array<string, mixed> $user */
    private function notifyCompleted(array $user, string $purpose, string $ip): void
    {
        $site = $this->app->settings->string('system.site_name');
        $tz = $this->app->settings->string('system.timezone');
        $text = "{$user['display_name']} 您好：\n\n您的 {$site} 管理者帳號已完成救援：" . self::PURPOSE_LABELS[$purpose] . "。\n"
            . '完成時間：' . Clock::display(Clock::utc(), $tz, 'Y-m-d H:i') . "（{$tz}）\n來源 IP：{$ip}\n\n"
            . "所有裝置的登入狀態都已失效。若這不是您本人的操作，請立即檢查信箱安全並重新救援。";
        try {
            $this->app->mailer()->send(new Message((string)$user['email'], "【{$site}】管理者救援已完成", $text));
        } catch (Throwable $e) {
            error_log('[project-hub] 救援完成通知寄送失敗：' . $e->getMessage());
        }
    }

    private function confirmPage(string $token, ?string $error, int $status, bool $logInvalid = false): Response
    {
        $repo = $this->tokens();
        $row = $repo->find($token);
        $state = RecoveryTokenRepository::status($row, $this->cooldown());
        $user = $row === null ? null : $this->app->users->find((int)$row['user_id']);
        if ($user === null || (int)$user['is_super_admin'] !== 1 || $user['status'] !== 'active') {
            $state = $state === 'ok' || $state === 'cooling' ? 'invalidated' : $state;
        }
        if ($state !== 'ok' && $state !== 'cooling' && $logInvalid) {
            $reasons = ['not_found' => '連結不存在', 'used' => '連結已使用過', 'invalidated' => '連結已失效', 'expired' => '連結已過期'];
            $this->logSecurity('recovery.complete', Log::RESULT_DENIED, $user === null ? null : (int)$user['id'],
                $user === null ? '(未知帳號)' : (string)$user['username'], $reasons[$state] ?? $state);
        }
        $response = $this->render('recovery/confirm', [
            'title' => '管理者救援',
            'state' => $state,
            'token' => $state === 'ok' ? $token : '',
            'purpose' => $row['purpose'] ?? '',
            'purposeLabel' => self::PURPOSE_LABELS[$row['purpose'] ?? ''] ?? '',
            'usableAt' => $row === null ? null : Clock::utc(RecoveryTokenRepository::usableAt($row, $this->cooldown())),
            'expiresAt' => $row['expires_at'] ?? null,
            'error' => $error,
            'minLength' => $this->app->settings->int('security.password_min_length'),
        ], $status);
        // 頁面網址含 Token：禁止透過 Referer 外洩，也禁止快取
        return $response->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function limited(string $accountKey, string $ip): bool
    {
        $since = Clock::utc(Clock::now() - 3600);
        $perAccount = $this->app->settings->int('recovery.requests_per_hour');
        $byAccount = (int)$this->app->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE account_key = ? AND stage = ? AND attempted_at > ?',
            [$accountKey, self::STAGE_REQUEST, $since]
        );
        $byIp = (int)$this->app->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND stage = ? AND attempted_at > ?',
            [$ip, self::STAGE_REQUEST, $since]
        );
        return $byAccount >= $perAccount || $byIp >= $perAccount * 3;
    }

    private function cooldown(): int
    {
        return $this->app->settings->int('recovery.both_cooldown_minutes') * 60;
    }

    private function tokens(): RecoveryTokenRepository
    {
        return new RecoveryTokenRepository($this->app->db);
    }

    private function logSecurity(string $action, string $result, ?int $userId, string $label, string $detail): void
    {
        $this->app->logger->log('security', $action, $result, $userId, $label, $detail);
    }
}
