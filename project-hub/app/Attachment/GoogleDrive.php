<?php

declare(strict_types=1);

namespace ProjectHub\Attachment;

use ProjectHub\Settings\SecretSettings;
use ProjectHub\Settings\Settings;
use ProjectHub\Support\Clock;
use ProjectHub\Support\HttpClient;
use RuntimeException;

/**
 * Google Drive API 用戶端（OAuth 2.0 授權碼流程＋Drive v3 REST）。
 *
 * 安全設計（決議 #33）：
 * - 只要求 drive.file 範圍：本平台只能看到「自己建立的檔案」，無法讀取該 Google 帳號裡的其他檔案；
 * - Refresh Token 與 Access Token 以 app_key 加密後存在 secret_settings，不回顯、不寫入紀錄；
 * - 雲端檔名使用與資料庫相同的 64 位隨機名稱，不帶原始檔名與副檔名，雲端端看不出內容；
 * - 檔案不設定任何分享，只能用本平台的授權讀取，並經 PHP 代理做雙重授權。
 */
final class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    public const CONNECTION_KEY = 'google.connection';
    public const CLIENT_SECRET_KEY = 'google.client_secret';
    private const TOKEN_CACHE_KEY = 'google.access_token';
    public const FOLDER_NAME = '專案管理平台附件（請勿手動修改）';

    private const DEFAULT_ENDPOINTS = [
        'google_auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'google_token' => 'https://oauth2.googleapis.com/token',
        'google_revoke' => 'https://oauth2.googleapis.com/revoke',
        'google_api' => 'https://www.googleapis.com/drive/v3',
        'google_upload' => 'https://www.googleapis.com/upload/drive/v3',
    ];

    /** @var array<string, string> */
    private array $endpoints;

    /** @param array<string, string> $endpoints 只供測試以假服務取代 Google 網址 */
    public function __construct(
        private Settings $settings,
        private SecretSettings $secrets,
        array $endpoints = [],
        private HttpClient $http = new HttpClient(),
    ) {
        $this->endpoints = array_intersect_key($endpoints, self::DEFAULT_ENDPOINTS) + self::DEFAULT_ENDPOINTS;
    }

    public function clientId(): string
    {
        return $this->settings->string('storage.google_client_id');
    }

    /** 已填入 OAuth 用戶端 ID 與密鑰 */
    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->secrets->has(self::CLIENT_SECRET_KEY);
    }

    /** @return array{refresh_token:string, folder_id:string, email:string, connected_at:string, connected_by:int}|null */
    public function connection(): ?array
    {
        $c = $this->secrets->getJson(self::CONNECTION_KEY);
        return is_array($c) && isset($c['refresh_token'], $c['folder_id']) ? $c : null;
    }

    public function connected(): bool
    {
        return $this->configured() && $this->connection() !== null;
    }

    public function authUrl(string $state, string $redirectUri): string
    {
        return $this->endpoints['google_auth'] . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            // 每次都要求同意畫面，Google 才會再次發給 Refresh Token
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 以授權碼換取 Token，取得帳號 Email 並建立（或沿用）專用資料夾。
     *
     * @param array<string, mixed>|null $previous 先前連結的帳號與資料夾
     * @param string|null $requiredEmail 已有附件存在某個帳號時，只接受該帳號（在建立資料夾前檢查，避免在錯的帳號留下資料夾）
     * @return array{refresh_token:string, folder_id:string, email:string}
     */
    public function completeAuthorization(string $code, string $redirectUri, ?array $previous, ?string $requiredEmail = null): array
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        if (!isset($tokens['refresh_token']) || !is_string($tokens['refresh_token'])) {
            throw new DriveException('Google 沒有發給長期授權（Refresh Token）。請到 Google 帳戶的「第三方存取權」移除本平台後重新連結。');
        }
        $access = (string)$tokens['access_token'];
        $scopes = explode(' ', (string)($tokens['scope'] ?? self::SCOPE));
        if (!in_array(self::SCOPE, $scopes, true)) {
            throw new DriveException('授權時沒有勾選「存取本平台建立的 Google 雲端硬碟檔案」，請重新連結並允許此權限。');
        }
        $email = $this->accountEmail($access);
        if ($requiredEmail !== null && strcasecmp($email, $requiredEmail) !== 0) {
            throw new AccountMismatchException($email);
        }
        $folderId = null;
        // 重新連結同一個帳號時沿用原本的資料夾，舊檔案仍在同一處
        if ($previous !== null && ($previous['email'] ?? '') === $email && $this->folderExists($access, (string)$previous['folder_id'])) {
            $folderId = (string)$previous['folder_id'];
        }
        $folderId ??= $this->createFolder($access);
        $this->cacheToken($access, (int)($tokens['expires_in'] ?? 3600));
        return ['refresh_token' => (string)$tokens['refresh_token'], 'folder_id' => $folderId, 'email' => $email];
    }

    /** 撤銷授權（盡力而為：網路失敗時仍會在本機刪除 Token）。 */
    public function revoke(): void
    {
        $c = $this->connection();
        if ($c !== null) {
            try {
                $this->http->request('POST', $this->endpoints['google_revoke'], ['Content-Type: application/x-www-form-urlencoded'],
                    http_build_query(['token' => $c['refresh_token']]));
            } catch (RuntimeException $e) {
                error_log('[project-hub] 撤銷 Google 授權失敗：' . $e->getMessage());
            }
        }
        $this->secrets->delete(self::CONNECTION_KEY);
        $this->secrets->delete(self::TOKEN_CACHE_KEY);
    }

    public function upload(string $name, string $sourcePath): string
    {
        $c = $this->requireConnection();
        $boundary = 'ph' . bin2hex(random_bytes(12));
        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new RuntimeException('無法讀取暫存檔');
        }
        $meta = json_encode(['name' => $name, 'parents' => [$c['folder_id']], 'mimeType' => 'application/octet-stream'], JSON_THROW_ON_ERROR);
        // 一律以 application/octet-stream 存放：避免 Google 依內容轉檔或在雲端預覽
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
            . "--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$boundary}--";
        unset($content);
        $res = $this->api('POST', $this->endpoints['google_upload'] . '/files?uploadType=multipart&fields=id',
            ['Content-Type: multipart/related; boundary=' . $boundary], $body);
        $id = $this->decode($res)['id'] ?? '';
        return self::validId((string)$id) ? (string)$id : throw new DriveException('Google Drive 回傳的檔案代號格式不正確');
    }

    /** 下載到指定路徑；檔案不存在（被手動刪除）回傳 false。 */
    public function download(string $fileId, string $dest): bool
    {
        if (!self::validId($fileId)) {
            return false;
        }
        $res = $this->api('GET', $this->endpoints['google_api'] . '/files/' . rawurlencode($fileId) . '?alt=media', [], null, $dest);
        if ($res['status'] === 404) {
            @unlink($dest);
            return false;
        }
        if ($res['status'] !== 200) {
            @unlink($dest);
            $this->decode($res);
        }
        return true;
    }

    public function delete(string $fileId): void
    {
        if (!self::validId($fileId)) {
            return;
        }
        $res = $this->api('DELETE', $this->endpoints['google_api'] . '/files/' . rawurlencode($fileId));
        if ($res['status'] !== 204 && $res['status'] !== 200 && $res['status'] !== 404) {
            $this->decode($res);
        }
    }

    public static function validId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id);
    }

    private function accountEmail(string $access): string
    {
        $res = $this->http->request('GET', $this->endpoints['google_api'] . '/about?fields=user(emailAddress)', ['Authorization: Bearer ' . $access]);
        $email = (string)($this->decode($res)['user']['emailAddress'] ?? '');
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : throw new DriveException('無法取得 Google 帳號資訊');
    }

    private function folderExists(string $access, string $folderId): bool
    {
        if (!self::validId($folderId)) {
            return false;
        }
        $res = $this->http->request('GET', $this->endpoints['google_api'] . '/files/' . rawurlencode($folderId) . '?fields=id,trashed',
            ['Authorization: Bearer ' . $access]);
        return $res['status'] === 200 && empty($this->decode($res)['trashed']);
    }

    private function createFolder(string $access): string
    {
        $res = $this->http->request('POST', $this->endpoints['google_api'] . '/files?fields=id', [
            'Authorization: Bearer ' . $access,
            'Content-Type: application/json; charset=UTF-8',
        ], json_encode(['name' => self::FOLDER_NAME, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $id = (string)($this->decode($res)['id'] ?? '');
        return self::validId($id) ? $id : throw new DriveException('無法在 Google Drive 建立資料夾');
    }

    /**
     * 帶 Access Token 呼叫 API；Token 失效（401）時換新後重試一次。
     *
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    private function api(string $method, string $url, array $headers = [], ?string $body = null, ?string $sink = null): array
    {
        $res = $this->http->request($method, $url, array_merge(['Authorization: Bearer ' . $this->accessToken()], $headers), $body, $sink);
        if ($res['status'] === 401) {
            $res = $this->http->request($method, $url, array_merge(['Authorization: Bearer ' . $this->accessToken(true)], $headers), $body, $sink);
        }
        return $res;
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $cached = $this->secrets->getJson(self::TOKEN_CACHE_KEY);
        // 提前 60 秒視為過期，避免請求途中失效
        if (!$forceRefresh && is_array($cached) && (int)($cached['expires'] ?? 0) > Clock::now() + 60) {
            return (string)$cached['token'];
        }
        $c = $this->requireConnection();
        $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token']]);
        $this->cacheToken((string)$tokens['access_token'], (int)($tokens['expires_in'] ?? 3600));
        return (string)$tokens['access_token'];
    }

    private function cacheToken(string $token, int $expiresIn): void
    {
        $this->secrets->setJson(self::TOKEN_CACHE_KEY, ['token' => $token, 'expires' => Clock::now() + max(60, $expiresIn)], null);
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        $secret = $this->secrets->get(self::CLIENT_SECRET_KEY);
        if ($this->clientId() === '' || $secret === null) {
            throw new DriveException('尚未設定 Google OAuth 用戶端 ID 與密鑰');
        }
        $res = $this->http->request('POST', $this->endpoints['google_token'], ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params + ['client_id' => $this->clientId(), 'client_secret' => $secret]));
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $error = is_array($data) ? (string)($data['error'] ?? '') : '';
            if ($error === 'invalid_grant') {
                throw new DriveException('Google 授權已失效或被撤銷，請到「附件儲存」頁重新連結 Google 帳號。');
            }
            if ($error === 'invalid_client') {
                throw new DriveException('Google OAuth 用戶端 ID 或密鑰錯誤。');
            }
            throw new DriveException('Google 授權伺服器拒絕請求（HTTP ' . $res['status'] . ($error !== '' ? '，' . $error : '') . '）');
        }
        return $data;
    }

    /** @return array{refresh_token:string, folder_id:string, email:string} */
    private function requireConnection(): array
    {
        return $this->connection() ?? throw new DriveException('尚未連結 Google 帳號');
    }

    /**
     * @param array{status:int, body:string} $res
     * @return array<string, mixed>
     */
    private function decode(array $res): array
    {
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return is_array($data) ? $data : [];
        }
        $reason = is_array($data) ? (string)($data['error']['errors'][0]['reason'] ?? $data['error']['status'] ?? '') : '';
        if ($reason === 'storageQuotaExceeded') {
            throw new DriveException('Google Drive 空間已滿');
        }
        throw new DriveException('Google Drive 回應錯誤（HTTP ' . $res['status'] . ($reason !== '' ? '，' . $reason : '') . '）');
    }
}
