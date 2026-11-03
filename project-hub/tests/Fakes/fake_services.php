<?php

declare(strict_types=1);

/**
 * 整合測試用的假外部服務（以 php -S 執行）：模擬 Google OAuth、Google Drive v3、Brevo 寄信 API。
 * 狀態存放在環境變數 FAKE_STATE_DIR 指定的目錄，測試程式可直接讀檔驗證。
 *
 * 只實作本平台會用到的行為；回應格式依照官方文件。
 */

$dir = (string)getenv('FAKE_STATE_DIR');
@mkdir($dir . '/files', 0700, true);
$method = $_SERVER['REQUEST_METHOD'];
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
file_put_contents($dir . '/requests.log', $method . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);

function out(int $status, mixed $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function flag(string $name): bool
{
    return is_file(getenv('FAKE_STATE_DIR') . '/flag_' . $name);
}

function bearer(array $headers): void
{
    $auth = $headers['authorization'] ?? '';
    $valid = (string)@file_get_contents(getenv('FAKE_STATE_DIR') . '/valid_access_token');
    if ($valid === '' || $auth !== 'Bearer ' . $valid) {
        out(401, ['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']]);
    }
}

if ($method === 'POST' && $path === '/token') {
    parse_str($raw, $p);
    file_put_contents($dir . '/last_token_request.json', json_encode($p));
    if (($p['client_id'] ?? '') !== 'test-client-id.apps.googleusercontent.com' || ($p['client_secret'] ?? '') !== 'test-client-secret') {
        out(401, ['error' => 'invalid_client']);
    }
    $access = 'at-' . bin2hex(random_bytes(6));
    if (($p['grant_type'] ?? '') === 'authorization_code') {
        if (($p['code'] ?? '') !== 'good-code') {
            out(400, ['error' => 'invalid_grant']);
        }
        $refresh = 'rt-' . bin2hex(random_bytes(6));
        file_put_contents($dir . '/valid_refresh_token', $refresh);
        file_put_contents($dir . '/valid_access_token', $access);
        out(200, ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive.file', 'token_type' => 'Bearer']);
    }
    if (($p['grant_type'] ?? '') === 'refresh_token') {
        if (flag('revoked') || ($p['refresh_token'] ?? '') !== (string)@file_get_contents($dir . '/valid_refresh_token')) {
            out(400, ['error' => 'invalid_grant']);
        }
        file_put_contents($dir . '/valid_access_token', $access);
        out(200, ['access_token' => $access, 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }
    out(400, ['error' => 'unsupported_grant_type']);
}

if ($method === 'POST' && $path === '/revoke') {
    file_put_contents($dir . '/revoked.log', $raw . "\n", FILE_APPEND);
    out(200, []);
}

if ($method === 'GET' && $path === '/drive/v3/about') {
    bearer($headers);
    out(200, ['user' => ['emailAddress' => (string)(@file_get_contents($dir . '/account_email') ?: 'owner@example.com')]]);
}

if ($method === 'POST' && $path === '/drive/v3/files') {
    bearer($headers);
    $meta = json_decode($raw, true);
    $id = 'folder' . bin2hex(random_bytes(8));
    file_put_contents($dir . '/folder_' . $id, json_encode($meta, JSON_UNESCAPED_UNICODE));
    out(200, ['id' => $id]);
}

if ($method === 'POST' && $path === '/upload/drive/v3/files') {
    bearer($headers);
    if (flag('quota')) {
        out(403, ['error' => ['code' => 403, 'errors' => [['reason' => 'storageQuotaExceeded']]]]);
    }
    if (!preg_match('/boundary=([A-Za-z0-9]+)/', $_SERVER['CONTENT_TYPE'] ?? '', $m)) {
        out(400, ['error' => 'no boundary']);
    }
    $parts = explode('--' . $m[1], $raw);
    // parts[1] = 中繼資料，parts[2] = 檔案內容
    [, $metaBody] = explode("\r\n\r\n", $parts[1], 2);
    [, $content] = explode("\r\n\r\n", $parts[2], 2);
    $content = substr($content, 0, -2); // 去掉結尾的 \r\n
    $meta = json_decode(trim($metaBody), true);
    $id = 'file' . bin2hex(random_bytes(10));
    file_put_contents($dir . '/files/' . $id, $content);
    file_put_contents($dir . '/files/' . $id . '.meta', json_encode($meta));
    out(200, ['id' => $id]);
}

if (preg_match('#^/drive/v3/files/([A-Za-z0-9_-]+)$#', $path, $m)) {
    bearer($headers);
    $id = $m[1];
    if ($method === 'GET' && ($_GET['alt'] ?? '') === 'media') {
        if (!is_file($dir . '/files/' . $id)) {
            out(404, ['error' => ['code' => 404, 'errors' => [['reason' => 'notFound']]]]);
        }
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        readfile($dir . '/files/' . $id);
        exit;
    }
    if ($method === 'GET') {
        out(is_file($dir . '/folder_' . $id) ? 200 : 404, ['id' => $id, 'trashed' => false]);
    }
    if ($method === 'DELETE') {
        @unlink($dir . '/files/' . $id);
        @unlink($dir . '/files/' . $id . '.meta');
        http_response_code(204);
        exit;
    }
}

if ($method === 'POST' && $path === '/brevo') {
    if (($headers['api-key'] ?? '') !== 'brevo-test-key') {
        out(401, ['code' => 'unauthorized', 'message' => 'Key not found']);
    }
    file_put_contents($dir . '/brevo_last.json', $raw);
    file_put_contents($dir . '/brevo_all.jsonl', json_encode(json_decode($raw, true), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    out(201, ['messageId' => '<fake@brevo>']);
}

// ---- 假 Cloudflare R2（S3 相容）：/r2/{bucket}/{key} ----
if (preg_match('#^/r2/([^/]+)/(.+)$#', $path, $m)) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $xml = static function (int $status, string $code): never {
        http_response_code($status);
        header('Content-Type: application/xml');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>{$code}</Code><Message>fake</Message></Error>";
        exit;
    };
    [$bucket, $key] = [rawurldecode($m[1]), rawurldecode($m[2])];
    $auth = $headers['authorization'] ?? '';
    if (!str_starts_with($auth, 'AWS4-HMAC-SHA256 Credential=r2testaccess/')) {
        $xml(403, 'InvalidAccessKeyId');
    }
    // 以相同演算法重算簽章：確認標頭、日期與內容雜湊一致（演算法本身的正確性由 AWS 官方測試向量驗證）
    $hash = $headers['x-amz-content-sha256'] ?? '';
    if ($hash !== hash('sha256', $raw)) {
        $xml(400, 'XAmzContentSHA256Mismatch');
    }
    $date = DateTime::createFromFormat('Ymd\THis\Z', $headers['x-amz-date'] ?? '', new DateTimeZone('UTC'));
    $extra = isset($headers['content-type']) && $method === 'PUT' ? ['content-type' => $headers['content-type']] : [];
    $expected = (new ProjectHub\Attachment\S3Signer('r2testaccess', 'r2testsecret'))
        ->sign($method, 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], $extra, $hash, $date ? $date->getTimestamp() : 0)['authorization'];
    if (!hash_equals($expected, $auth)) {
        $xml(403, 'SignatureDoesNotMatch');
    }
    if ($bucket !== 'ph-bucket') {
        $xml(404, 'NoSuchBucket');
    }
    @mkdir($dir . '/r2', 0700, true);
    $file = $dir . '/r2/' . hash('sha256', $key);
    file_put_contents($dir . '/r2_requests.log', $method . ' ' . $key . "\n", FILE_APPEND);
    if ($method === 'PUT') {
        file_put_contents($file, $raw);
        file_put_contents($file . '.key', $key);
        http_response_code(200);
        exit;
    }
    if ($method === 'GET') {
        if (!is_file($file)) {
            $xml(404, 'NoSuchKey');
        }
        header('Content-Type: application/octet-stream');
        readfile($file);
        exit;
    }
    if ($method === 'DELETE') {
        @unlink($file);
        @unlink($file . '.key');
        http_response_code(204);
        exit;
    }
}

out(404, ['error' => 'not found: ' . $method . ' ' . $path]);
