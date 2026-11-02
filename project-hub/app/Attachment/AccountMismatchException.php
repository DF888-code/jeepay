<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

/** 重新連結時使用了與既有附件不同的 Google 帳號。 */
final class AccountMismatchException extends DriveException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct('Google 帳號不符：' . $email);
    }
}
