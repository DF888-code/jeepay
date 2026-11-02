<?php

declare(strict_types=1);

namespace ProjectHub\Mail;

/**
 * 最小化的 SMTP 用戶端（InfinityFree 無法用 Composer 以外的方式安裝套件，也不能執行 sendmail）。
 *
 * 只實作寄送交易郵件需要的指令：EHLO、STARTTLS、AUTH LOGIN／PLAIN、MAIL、RCPT、DATA、QUIT。
 * 注意事項：
 * - 對話紀錄（transcript）會遮蔽 AUTH 之後送出的帳號與密碼，只保留伺服器回覆；
 * - 一律驗證伺服器憑證；
 * - 逾時 15 秒，避免卡住主機 60 秒的執行上限。
 */
final class SmtpTransport
{
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    /** @var list<string> 給管理者看的對話紀錄（已遮蔽機密） */
    private array $transcript = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $heloName,
    ) {
    }

    /** @return list<string> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /** 只連線並登入，不寄信（設定頁的「測試連線」）。 */
    public function check(): void
    {
        try {
            $this->open();
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    public function send(string $from, string $rawMessage, string $to): void
    {
        try {
            $this->open();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            // 以 CRLF 結尾並做 dot-stuffing：行首的「.」要重複一次，否則會被當成信件結束
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $rawMessage));
            $data = str_replace("\n", "\r\n", rtrim((string)$data, "\n")) . "\r\n.";
            $this->command($data, [250], '[信件內容]');
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    private function open(): void
    {
        if ($this->host === '') {
            throw new MailException('尚未設定 SMTP 主機');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($scheme . $this->host . ':' . $this->port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("無法連線到 {$this->host}:{$this->port}（{$errstr}）");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;
        $this->expect([220]);
        $this->ehlo();
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if ($ok !== true) {
                throw new MailException('STARTTLS 加密失敗（伺服器憑證無效或不支援 TLS 1.2 以上）');
            }
            $this->ehlo(); // TLS 之後必須重新 EHLO，伺服器才會列出 AUTH
        }
        if ($this->username !== '') {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], '[帳號已遮蔽]');
            $this->command(base64_encode($this->password), [235], '[密碼已遮蔽]');
        }
    }

    private function ehlo(): void
    {
        $this->command('EHLO ' . $this->heloName, [250]);
    }

    /** @param list<int> $okCodes */
    private function command(string $line, array $okCodes, ?string $logAs = null): string
    {
        $this->transcript[] = 'C: ' . ($logAs ?? $line);
        if (@fwrite($this->socket, $line . "\r\n") === false) {
            throw new MailException('與 SMTP 伺服器的連線中斷');
        }
        return $this->expect($okCodes);
    }

    /** @param list<int> $okCodes */
    private function expect(array $okCodes): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            // 多行回覆以「代碼-」接續，最後一行是「代碼 空白」
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($reply === '') {
            $meta = stream_get_meta_data($this->socket);
            throw new MailException(!empty($meta['timed_out']) ? 'SMTP 伺服器回應逾時' : 'SMTP 伺服器關閉了連線');
        }
        $this->transcript[] = 'S: ' . trim(mb_substr($reply, 0, 300));
        $code = (int)substr($reply, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new MailException('SMTP 伺服器拒絕：' . trim(mb_substr($reply, 0, 300)));
        }
        return $reply;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
