<?php

declare(strict_types=1);

namespace ProjectHub\Mail;

/** 純文字信件（第一版不寄 HTML 信，避免信件內容被當成網頁解析）。 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
    ) {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('收件者 Email 格式不正確');
        }
        if (preg_match('/[\r\n]/', $to . $subject)) {
            // 防止標頭注入：收件者與主旨不可含換行
            throw new MailException('收件者或主旨含有不允許的字元');
        }
    }
}
