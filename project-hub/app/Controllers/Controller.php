<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\App;
use ProjectHub\Auth\QrCodeRenderer;
use ProjectHub\Auth\Totp;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

abstract class Controller
{
    /** 新產生的待確認 2FA Secret 有效時間 */
    protected const PENDING_TOTP_SECONDS = 600;

    public function __construct(protected App $app)
    {
    }

    /** @param array<string, mixed> $vars */
    protected function render(string $template, array $vars = [], int $status = 200): Response
    {
        return Response::html($this->app->view->render($template, $vars), $status);
    }

    protected function back(string $location, string $type, string $message): Response
    {
        $this->app->session->flash($type, $message);
        return Response::redirect($location);
    }

    /**
     * 取得（必要時產生）使用者的待確認 2FA Secret。
     * 重新整理頁面時沿用同一把，避免使用者掃描到已被取代的 QR Code。
     *
     * @param array<string, mixed> $user
     */
    protected function pendingSecret(array $user, bool $forceNew = false): string
    {
        $pending = $user['totp_pending_secret_enc'] ?? null;
        $expires = $user['totp_pending_expires_at'] ?? null;
        if (!$forceNew && is_string($pending) && is_string($expires) && $expires > Clock::utc()) {
            return $this->app->crypto->decrypt($pending, 'totp');
        }
        $secret = Totp::generateSecret();
        $this->app->users->setPendingTotp((int)$user['id'], $this->app->crypto->encrypt($secret, 'totp'), Clock::now() + self::PENDING_TOTP_SECONDS);
        return $secret;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{qrSvg:string,secretText:string,accountLabel:string}
     */
    protected function enrollmentView(array $user, string $secret): array
    {
        $issuer = $this->app->settings->string('system.site_name');
        $uri = Totp::provisioningUri($issuer, (string)$user['username'], $secret);
        return [
            'qrSvg' => QrCodeRenderer::svg($uri),
            'secretText' => Totp::formatSecret($secret),
            'accountLabel' => $issuer . '：' . $user['username'],
        ];
    }

    protected function totpWindow(): int
    {
        return $this->app->settings->int('security.totp_window');
    }
}
