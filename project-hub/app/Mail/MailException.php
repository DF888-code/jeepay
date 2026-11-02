<?php

declare(strict_types=1);

namespace ProjectHub\Mail;

use RuntimeException;

/** 寄信失敗。訊息會顯示給最高權限管理者，因此只可包含伺服器回覆與連線描述，不可含密碼或金鑰。 */
final class MailException extends RuntimeException
{
}
