<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

use ProjectHub\Http\Session;
use ProjectHub\Settings\Settings;
use ProjectHub\Support\Clock;

/**
 * 目前請求的登入狀態。
 *
 * 狀態機：none → totp（已過密碼、待驗證碼）或 enroll（已過密碼、必須綁定 2FA）→ full（完整登入）。
 * 每個請求都重新從資料庫讀取使用者，帳號停用、auth_version 改變（改密碼、重設 2FA）時立即失效。
 */
final class AuthState
{
    public const NONE = 'none';
    public const TOTP = 'totp';
    public const ENROLL = 'enroll';
    public const FULL = 'full';

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private string $stage = self::NONE;

    public function __construct(private Session $session, private UserRepository $users, private Settings $settings)
    {
    }

    /** 回傳失效原因（供提示訊息），沒有失效回傳 null。 */
    public function resolve(): ?string
    {
        $uid = $this->session->get('uid');
        if (!is_int($uid)) {
            return null;
        }
        $user = $this->users->find($uid);
        $now = Clock::now();
        $stage = (string)$this->session->get('stage', self::NONE);

        if ($user === null || $user['status'] !== 'active' || (int)$user['auth_version'] !== (int)$this->session->get('auth_version')) {
            $this->session->clearAuth();
            return '登入狀態已失效，請重新登入。';
        }
        if ($stage === self::TOTP || $stage === self::ENROLL) {
            if ((int)$this->session->get('stage_at', 0) + $this->settings->int('security.pre_auth_minutes') * 60 < $now) {
                $this->session->clearAuth();
                return '驗證逾時，請重新登入。';
            }
        } elseif ($stage === self::FULL) {
            $idle = $this->settings->int('security.session_idle_minutes') * 60;
            $absolute = $this->settings->int('security.session_absolute_hours') * 3600;
            if ((int)$this->session->get('last_seen', 0) + $idle < $now || (int)$this->session->get('login_at', 0) + $absolute < $now) {
                $this->session->clearAuth();
                return '閒置過久或登入時間已到，請重新登入。';
            }
            $this->session->set('last_seen', $now);
        } else {
            $this->session->clearAuth();
            return null;
        }

        // 2FA 被管理者重設後，即使在待驗證碼階段也必須改走綁定流程
        if ($stage === self::TOTP && ((int)$user['must_enroll_2fa'] === 1 || (int)$user['totp_enabled'] !== 1)) {
            $stage = self::ENROLL;
            $this->session->set('stage', $stage);
        }

        $this->user = $user;
        $this->stage = $stage;
        return null;
    }

    /** 密碼驗證成功後進入「待 2FA」或「強制綁定」狀態。 */
    public function beginSecondFactor(array $user): string
    {
        $this->session->regenerate(); // 防止 Session 固定攻擊
        $stage = ((int)$user['must_enroll_2fa'] === 1 || (int)$user['totp_enabled'] !== 1) ? self::ENROLL : self::TOTP;
        $this->session->set('uid', (int)$user['id']);
        $this->session->set('auth_version', (int)$user['auth_version']);
        $this->session->set('stage', $stage);
        $this->session->set('stage_at', Clock::now());
        $this->user = $user;
        $this->stage = $stage;
        return $stage;
    }

    public function complete(int $authVersion): void
    {
        $this->session->regenerate();
        $now = Clock::now();
        $this->session->set('auth_version', $authVersion);
        $this->session->set('stage', self::FULL);
        $this->session->set('login_at', $now);
        $this->session->set('last_seen', $now);
        $this->session->remove('stage_at');
        $this->stage = self::FULL;
    }

    /** 自己修改密碼或 2FA 後，更新本 Session 的版本號（其他 Session 因版本不符而失效）。 */
    public function adoptAuthVersion(int $authVersion): void
    {
        $this->session->regenerate();
        $this->session->set('auth_version', $authVersion);
    }

    public function logout(): void
    {
        $this->session->clearAuth();
        $this->user = null;
        $this->stage = self::NONE;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function userId(): ?int
    {
        return $this->user === null ? null : (int)$this->user['id'];
    }

    public function label(): string
    {
        return $this->user === null ? '(未登入)' : (string)$this->user['username'];
    }
}
