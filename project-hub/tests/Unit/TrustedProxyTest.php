<?php

declare(strict_types=1);

/** 使用者 IP 來源判斷（Cloudflare、阿里雲高防、自訂代理）：重點是偽造的標頭不可被採用。 */

use ProjectHub\Http\TrustedProxy;

test('IP 段比對：IPv4／IPv6、邊界、格式錯誤', function (): void {
    assert_true(TrustedProxy::inRange('173.245.48.1', '173.245.48.0/20'));
    assert_true(TrustedProxy::inRange('173.245.63.255', '173.245.48.0/20'));
    assert_true(!TrustedProxy::inRange('173.245.64.0', '173.245.48.0/20'));
    assert_true(TrustedProxy::inRange('2606:4700::1111', '2606:4700::/32'));
    assert_true(!TrustedProxy::inRange('2606:4701::1', '2606:4700::/32'));
    assert_true(TrustedProxy::inRange('10.0.0.5', '10.0.0.5'));
    assert_true(!TrustedProxy::inRange('10.0.0.5', '2606:4700::/32'), 'IPv4 不會符合 IPv6 範圍');
    assert_true(!TrustedProxy::inRange('not-ip', '10.0.0.0/8'));
});

test('直接連線：不論帶什麼標頭都使用連線來源', function (): void {
    $r = (new TrustedProxy('direct'))->resolve(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']);
    assert_same('198.51.100.9', $r['ip']);
    assert_same(false, $r['trusted']);
});

test('Cloudflare：只有來自 Cloudflare IP 的連線才採用 CF-Connecting-IP；偽造者被忽略', function (): void {
    $p = new TrustedProxy('cloudflare');
    $ok = $p->resolve(['REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50', 'HTTP_X_FORWARDED_PROTO' => 'https']);
    assert_same('203.0.113.50', $ok['ip']);
    assert_same(true, $ok['https'], '採信代理告知的 https');
    $spoof = $p->resolve(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50', 'HTTP_X_FORWARDED_PROTO' => 'https']);
    assert_same('198.51.100.9', $spoof['ip'], '直連主機並偽造標頭：不採用');
    assert_same(false, $spoof['https']);
    $bad = $p->resolve(['REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '<script>']);
    assert_same('162.158.1.2', $bad['ip'], '標頭不是合法 IP 時不採用');
});

test('阿里雲高防：採用 X-Forwarded-For 最右邊的非代理 IP，前段偽造的值無效', function (): void {
    $p = new TrustedProxy('aliyun', ['47.100.0.0/16']);
    assert_same('203.0.113.7', $p->resolve(['REMOTE_ADDR' => '47.100.3.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])['ip']);
    assert_same('203.0.113.7', $p->resolve(['REMOTE_ADDR' => '47.100.3.4', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.7'])['ip'], '使用者自己在前面加的偽造 IP 被略過');
    assert_same('203.0.113.7', $p->resolve(['REMOTE_ADDR' => '47.100.3.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 47.100.9.9'])['ip'], '中間的代理 IP 被略過');
    assert_same('198.51.100.9', $p->resolve(['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])['ip'], '不是來自回源 IP 段：不採用');
});

test('自訂代理與 IP 段輸入檢查：拒絕過大範圍與錯誤格式', function (): void {
    $p = new TrustedProxy('custom', ['10.1.0.0/16'], 'X-Real-IP');
    assert_same('203.0.113.8', $p->resolve(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_REAL_IP' => '203.0.113.8'])['ip']);
    [$ranges, $err] = TrustedProxy::parseRanges('203.0.113.0/24, 198.51.100.7  2001:db8::/32');
    assert_same(null, $err);
    assert_same(['203.0.113.0/24', '198.51.100.7/32', '2001:db8::/32'], $ranges);
    foreach (['0.0.0.0/0', '10.0.0.0/4', '::/0', 'abc', '1.2.3.4/33', '1.2.3.4/x'] as $bad) {
        assert_true(TrustedProxy::parseRanges($bad)[1] !== null, $bad);
    }
});
