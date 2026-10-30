<?php

declare(strict_types=1);

namespace ProjectHub\Support;

use RuntimeException;

/**
 * 以應用金鑰進行加密與 HMAC。
 *
 * 不同用途使用由主金鑰衍生的子金鑰，避免同一把金鑰跨用途重複使用。
 */
final class Crypto
{
    public function __construct(private string $masterKey)
    {
        if (strlen($masterKey) !== 32) {
            throw new RuntimeException('金鑰長度錯誤');
        }
    }

    /** 加密後格式：nonce(24) || secretbox 密文。 */
    public function encrypt(string $plain, string $purpose): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->subKey('enc:' . $purpose));
    }

    public function decrypt(string $blob, string $purpose): string
    {
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) <= $nonceLen) {
            throw new RuntimeException('密文格式錯誤');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($blob, $nonceLen),
            substr($blob, 0, $nonceLen),
            $this->subKey('enc:' . $purpose)
        );
        if ($plain === false) {
            throw new RuntimeException('解密失敗');
        }
        return $plain;
    }

    /** 回傳 32 bytes 二進位 HMAC-SHA256。 */
    public function mac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, $this->subKey('mac:' . $purpose), true);
    }

    private function subKey(string $label): string
    {
        return hash_hmac('sha256', $label, $this->masterKey, true);
    }
}
