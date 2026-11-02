<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use RuntimeException;

/** Google Drive 錯誤。訊息會顯示給管理者，不可包含 Token 或密鑰。 */
class DriveException extends RuntimeException
{
}
