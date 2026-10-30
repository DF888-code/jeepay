<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * 在伺服器端產生 SVG QR Code。
 *
 * 為什麼不用線上 QR 產生服務：otpauth 連結內含 2FA Secret，送到第三方等同把第二因素交給外人。
 */
final class QrCodeRenderer
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ]);
        return (new QRCode($options))->render($data);
    }
}
