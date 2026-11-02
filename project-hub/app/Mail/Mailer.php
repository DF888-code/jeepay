<?php

declare(strict_types=1);

namespace ProjectHub\Mail;

use ProjectHub\Settings\SecretSettings;
use ProjectHub\Settings\Settings;
use ProjectHub\Support\HttpClient;

/**
 * 依系統設定選擇寄信方式（SMTP 或 Brevo HTTPS API）。
 *
 * 呼叫端只需要 send()；寄信方式、帳號與機密都從設定中心讀取。
 */
final class Mailer
{
    public const BREVO_ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** @var list<string> */
    private array $transcript = [];

    /** @param array<string, string> $endpoints 只供測試覆寫外部服務網址 */
    public function __construct(
        private Settings $settings,
        private SecretSettings $secrets,
        private array $endpoints = [],
        private ?HttpClient $http = null,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->string('mail.driver') !== 'none';
    }

    /** @return list<string> 最近一次 SMTP 對話紀錄（已遮蔽帳號密碼），供設定頁協助排除問題 */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /** 只測試連線與登入，不寄信。 */
    public function check(): void
    {
        $driver = $this->settings->string('mail.driver');
        if ($driver === 'smtp') {
            $smtp = $this->smtp();
            try {
                $smtp->check();
            } finally {
                $this->transcript = $smtp->transcript();
            }
            return;
        }
        if ($driver === 'brevo_api') {
            // Brevo 沒有不寄信的驗證端點可用，改為檢查金鑰是否已設定；實際可用與否請用「寄送測試信」
            if ($this->secrets->get('mail.brevo_api_key') === null) {
                throw new MailException('尚未設定 Brevo API 金鑰');
            }
            return;
        }
        throw new MailException('寄信方式設為「不寄信」');
    }

    public function send(Message $message): void
    {
        $from = $this->settings->string('mail.from_address');
        if ($from === '') {
            throw new MailException('尚未設定寄件者 Email');
        }
        $fromName = $this->settings->string('mail.from_name') ?: $this->settings->string('system.site_name');
        match ($this->settings->string('mail.driver')) {
            'smtp' => $this->sendSmtp($message, $from, $fromName),
            'brevo_api' => $this->sendBrevo($message, $from, $fromName),
            default => throw new MailException('寄信方式設為「不寄信」'),
        };
    }

    private function sendSmtp(Message $message, string $from, string $fromName): void
    {
        $smtp = $this->smtp();
        try {
            $smtp->send($from, self::compose($message, $from, $fromName), $message->to);
        } finally {
            $this->transcript = $smtp->transcript();
        }
    }

    private function sendBrevo(Message $message, string $from, string $fromName): void
    {
        $key = $this->secrets->get('mail.brevo_api_key');
        if ($key === null) {
            throw new MailException('尚未設定 Brevo API 金鑰');
        }
        $payload = json_encode([
            'sender' => ['name' => $fromName, 'email' => $from],
            'to' => [['email' => $message->to]],
            'subject' => $message->subject,
            'textContent' => $message->text,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            $res = ($this->http ?? new HttpClient(20))->request('POST', $this->endpoints['brevo'] ?? self::BREVO_ENDPOINT, [
                'api-key: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ], $payload);
        } catch (\RuntimeException $e) {
            throw new MailException($e->getMessage());
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $err = json_decode($res['body'], true);
            $detail = is_array($err) ? (string)($err['message'] ?? $err['code'] ?? '') : '';
            throw new MailException('Brevo 拒絕寄送（HTTP ' . $res['status'] . ($detail !== '' ? '：' . mb_substr($detail, 0, 200) : '') . '）');
        }
    }

    private function smtp(): SmtpTransport
    {
        $host = $this->settings->string('mail.smtp_host');
        $username = $this->settings->string('mail.smtp_username');
        $password = $this->secrets->get('mail.smtp_password') ?? '';
        if ($username !== '' && $password === '') {
            throw new MailException('已設定 SMTP 帳號但尚未設定密碼');
        }
        // EHLO 名稱使用寄件網域，避免送出主機內部名稱
        $from = $this->settings->string('mail.from_address');
        $helo = $from !== '' && str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : 'localhost';
        return new SmtpTransport($host, $this->settings->int('mail.smtp_port'), $this->settings->string('mail.smtp_encryption'),
            $username, $password, (string)preg_replace('/[^A-Za-z0-9.-]/', '', $helo) ?: 'localhost');
    }

    /** 組成 RFC 5322 信件：標頭以 RFC 2047 編碼中文，內文以 Base64 傳送 UTF-8。 */
    public static function compose(Message $message, string $from, string $fromName): string
    {
        $domain = substr($from, strrpos($from, '@') + 1);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader(str_replace(["\r", "\n", '"'], '', $fromName)) . ' <' . $from . '>',
            'To: <' . $message->to . '>',
            'Subject: ' . self::encodeHeader($message->subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($message->text), 76, "\r\n"));
    }

    private static function encodeHeader(string $text): string
    {
        return preg_match('/[^\x20-\x7E]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }
}
