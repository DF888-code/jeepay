<?php

declare(strict_types=1);

/**
 * 整合測試用的假 SMTP 伺服器（不加密）。依序處理連線，把完整對話寫到 transcript 檔。
 * 只接受帳號 smtp-user／密碼 smtp-pass。
 *
 *   php fake_smtp.php <port> <輸出目錄>
 */

[, $port, $dir] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
$n = 0;
while ($conn = @stream_socket_accept($server, -1)) {
    $n++;
    $log = [];
    $send = static function (string $line) use ($conn, &$log): void {
        fwrite($conn, $line . "\r\n");
        $log[] = 'S: ' . $line;
    };
    $send('220 fake.smtp ESMTP');
    $authStep = 0;
    $user = '';
    $inData = false;
    $data = '';
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($inData) {
            if ($line === '.') {
                $inData = false;
                file_put_contents($dir . '/smtp_message_' . $n . '.eml', $data);
                $send('250 OK queued');
                continue;
            }
            $data .= $line . "\r\n";
            continue;
        }
        $log[] = 'C: ' . $line;
        if ($authStep === 1) {
            $user = base64_decode($line);
            $authStep = 2;
            $send('334 UGFzc3dvcmQ6');
            continue;
        }
        if ($authStep === 2) {
            $authStep = 0;
            $send($user === 'smtp-user' && base64_decode($line) === 'smtp-pass' ? '235 Authentication successful' : '535 Authentication failed');
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        match (true) {
            $cmd === 'EHLO' => (function () use ($send): void { $send('250-fake.smtp'); $send('250 AUTH LOGIN PLAIN'); })(),
            $line === 'AUTH LOGIN' => (function () use ($send, &$authStep): void { $authStep = 1; $send('334 VXNlcm5hbWU6'); })(),
            $cmd === 'MAIL', $cmd === 'RCPT' => $send('250 OK'),
            $cmd === 'DATA' => (function () use ($send, &$inData, &$data): void { $inData = true; $data = ''; $send('354 End data with <CR><LF>.<CR><LF>'); })(),
            $cmd === 'QUIT' => $send('221 Bye'),
            default => $send('502 Command not implemented'),
        };
        if ($cmd === 'QUIT') {
            break;
        }
    }
    fclose($conn);
    file_put_contents($dir . '/smtp_transcript_' . $n . '.log', implode("\n", $log));
}
