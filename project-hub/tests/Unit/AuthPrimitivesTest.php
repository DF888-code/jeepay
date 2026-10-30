<?php

declare(strict_types=1);

use ProjectHub\Auth\Base32;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\Totp;
use ProjectHub\Database\Migrator;
use ProjectHub\Settings\Settings;
use ProjectHub\Settings\SettingsRegistry;
use ProjectHub\Support\Crypto;

// RFC 6238 附錄 B 的 SHA1 測試向量（取末 6 位）
test('TOTP 符合 RFC 6238 測試向量', function (): void {
    $secret = '12345678901234567890';
    $vectors = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'];
    foreach ($vectors as $time => $code) {
        assert_same($code, Totp::code($secret, Totp::step($time)), "時間 {$time}");
    }
});

test('TOTP 時間容許範圍：前後一步可通過，兩步外拒絕', function (): void {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;
    $step = Totp::step($now);
    assert_same($step - 1, Totp::match($secret, Totp::code($secret, $step - 1), 1, null, $now));
    assert_same($step + 1, Totp::match($secret, Totp::code($secret, $step + 1), 1, null, $now));
    assert_same(null, Totp::match($secret, Totp::code($secret, $step - 2), 1, null, $now));
    assert_same(null, Totp::match($secret, Totp::code($secret, $step + 1), 0, null, $now), '容許範圍 0 時只接受目前時間步');
});

test('TOTP 防重放：已使用過的時間步（含更早）一律拒絕', function (): void {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;
    $step = Totp::step($now);
    $code = Totp::code($secret, $step);
    assert_same(null, Totp::match($secret, $code, 1, $step, $now));
    assert_same(null, Totp::match($secret, Totp::code($secret, $step - 1), 1, $step, $now));
    assert_same($step + 1, Totp::match($secret, Totp::code($secret, $step + 1), 1, $step, $now));
});

test('TOTP 拒絕格式錯誤的輸入，接受中間有空白的 6 位數', function (): void {
    $secret = Totp::generateSecret();
    $now = 1_800_000_000;
    $code = Totp::code($secret, Totp::step($now));
    foreach (['', '12345', '1234567', 'abcdef', '12345a', "123456\n7"] as $bad) {
        assert_same(null, Totp::match($secret, $bad, 1, null, $now), '輸入：' . json_encode($bad));
    }
    assert_same(Totp::step($now), Totp::match($secret, substr($code, 0, 3) . ' ' . substr($code, 3), 1, null, $now));
});

test('Base32 往返編碼並符合 RFC 4648 範例', function (): void {
    assert_same('MZXW6YTBOI', Base32::encode('foobar'));
    assert_same('foobar', Base32::decode('MZXW6YTBOI'));
    $raw = random_bytes(20);
    assert_same($raw, Base32::decode(Base32::encode($raw)));
    assert_same(null, Base32::decode('不是base32'));
});

test('otpauth 連結含 issuer、帳號與 Base32 Secret', function (): void {
    $uri = Totp::provisioningUri('Project Hub', 'admin', 'foobar');
    assert_true(str_starts_with($uri, 'otpauth://totp/Project%20Hub:admin?'), $uri);
    assert_contains('secret=MZXW6YTBOI', $uri);
    assert_contains('issuer=Project%20Hub', $uri);
});

test('加密：可解密、每次密文不同、不同用途無法互解、竄改即失敗', function (): void {
    $crypto = new Crypto(random_bytes(32));
    $a = $crypto->encrypt('secret-value', 'totp');
    $b = $crypto->encrypt('secret-value', 'totp');
    assert_true($a !== $b, '同一明文每次密文應不同');
    assert_same('secret-value', $crypto->decrypt($a, 'totp'));
    foreach ([fn() => $crypto->decrypt($a, 'other'), fn() => $crypto->decrypt(substr($a, 0, -1) . chr(ord($a[-1]) ^ 1), 'totp')] as $bad) {
        $threw = false;
        try {
            $bad();
        } catch (RuntimeException) {
            $threw = true;
        }
        assert_true($threw, '應拒絕解密');
    }
    assert_same(32, strlen($crypto->mac('x', 'y')));
    assert_true($crypto->mac('a', 'y') !== $crypto->mac('b', 'y'), '不同用途的 MAC 應不同');
});

test('密碼：雜湊可驗證、帳號不存在時仍執行比對並回傳 false、規則檢查', function (): void {
    $hash = Passwords::hash('correct horse battery');
    assert_true(Passwords::verify('correct horse battery', $hash));
    assert_true(!Passwords::verify('wrong', $hash));
    assert_true(!Passwords::verify('anything', null));
    assert_same(null, Passwords::policyError('abcdefghij', 'abcdefghij', 10));
    assert_true(Passwords::policyError('short', 'short', 10) !== null);
    assert_true(Passwords::policyError('abcdefghij', 'abcdefghiX', 10) !== null);
    assert_true(Passwords::policyError(str_repeat('a', 129), str_repeat('a', 129), 10) !== null);
});

test('系統設定：型別與範圍驗證，不合法值回退預設', function (): void {
    $def = SettingsRegistry::definitions()['security.login_max_failures'];
    assert_same(7, Settings::validate($def, 7));
    assert_same(7, Settings::validate($def, '7'));
    assert_same(null, Settings::validate($def, 2), '低於下限');
    assert_same(null, Settings::validate($def, 21), '高於上限');
    assert_same(null, Settings::validate($def, 'abc'));
    $tz = SettingsRegistry::definitions()['system.timezone'];
    assert_same('Asia/Taipei', Settings::validate($tz, 'Asia/Taipei'));
    assert_same(null, Settings::validate($tz, 'Mars/Base'));
    foreach (SettingsRegistry::definitions() as $key => $d) {
        assert_true(Settings::validate($d, $d['default']) !== null, "預設值本身必須合法：{$key}");
    }
});

test('遷移檔拆分：12 張資料表、不含註解行', function (): void {
    $sql = (string)file_get_contents(__DIR__ . '/../../database/migrations/0001_initial_schema.sql');
    $stmts = Migrator::statements($sql);
    $creates = array_filter($stmts, fn(string $s): bool => str_starts_with($s, 'CREATE TABLE'));
    assert_same(12, count($creates));
    foreach ($stmts as $s) {
        assert_true(!str_starts_with($s, '--'), '不應包含註解');
    }
});
