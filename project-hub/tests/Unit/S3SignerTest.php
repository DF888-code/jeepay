<?php

declare(strict_types=1);

/**
 * AWS Signature V4：以 AWS 官方文件「Signature Calculations for the Authorization Header」的範例驗證。
 * Cloudflare R2 使用相同演算法（region 為 auto）。
 */

use ProjectHub\Attachment\S3Signer;

test('SigV4：AWS 官方 GET Object 範例（含 Range 標頭）', function (): void {
    $s = new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    $h = $s->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', ['Range' => 'bytes=0-9'], hash('sha256', ''), gmmktime(0, 0, 0, 5, 24, 2013));
    assert_same('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $h['authorization']);
});

test('SigV4：AWS 官方 PUT Object 範例（路徑含特殊字元 $）', function (): void {
    $s = new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    $h = $s->sign('PUT', 'https://examplebucket.s3.amazonaws.com/test$file.text',
        ['Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'], hash('sha256', 'Welcome to Amazon S3.'), gmmktime(0, 0, 0, 5, 24, 2013));
    assert_true(str_ends_with($h['authorization'], 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd'));
});
