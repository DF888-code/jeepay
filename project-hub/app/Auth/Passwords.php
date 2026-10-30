<?php

declare(strict_types=1);

namespace ProjectHub\Auth;

final class Passwords
{
    /**
     * 帳號不存在時用來比對的假雜湊（對應一組無人知道的隨機密碼）。
     * 讓「帳號不存在」與「密碼錯誤」花費相近的時間，避免以回應時間判斷帳號是否存在。
     */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$dDlEMHJHUVRVOTVUNDFVdQ$qewqBtkzFbNlmFrmLZTkrHXBjQ2OLmOgolSW+657R38';
    private const DUMMY_BCRYPT = '$2y$12$zizo527fEYj94M2DqTvHruVwX2yNzl/5AvV3FKlXo6lZiP5nxTV72';
    private const MAX_LENGTH = 128;

    public static function hash(string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null) {
            password_verify($password, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT);
            return false;
        }
        return password_verify($password, $hash);
    }

    /** 回傳錯誤訊息；符合規則回傳 null。 */
    public static function policyError(string $password, string $confirm, int $minLength): ?string
    {
        $len = mb_strlen($password);
        if ($len < $minLength) {
            return "新密碼至少需要 {$minLength} 個字元。";
        }
        if ($len > self::MAX_LENGTH) {
            return '新密碼不可超過 ' . self::MAX_LENGTH . ' 個字元。';
        }
        if (!hash_equals($password, $confirm)) {
            return '兩次輸入的新密碼不一致。';
        }
        return null;
    }
}
