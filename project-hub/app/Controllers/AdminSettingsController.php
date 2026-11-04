<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Attachment\AccountMismatchException;
use ProjectHub\Attachment\AttachmentRepository;
use ProjectHub\Attachment\DriveException;
use ProjectHub\Attachment\GoogleDrive;
use ProjectHub\Attachment\GoogleDriveStorage;
use ProjectHub\Attachment\R2Storage;
use ProjectHub\Attachment\StorageManager;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Mail\MailException;
use ProjectHub\Mail\Message;
use ProjectHub\Settings\Settings;
use ProjectHub\Settings\SettingsRegistry;
use ProjectHub\Support\Clock;
use ProjectHub\Support\HttpClient;
use Throwable;

/**
 * 系統設定中心（階段 9，規格第十二節）：一般設定、寄信設定、附件儲存位置（Google Drive）。
 *
 * - 所有變更都需要管理者再驗證（決議 #30），並寫入歷史紀錄（新舊值；機密只記「已更新」）；
 * - 機密值（SMTP 密碼、API 金鑰、OAuth 密鑰）只寫不讀：畫面只顯示「已設定」，留空表示不變更。
 */
final class AdminSettingsController extends AdminController
{
    private const OAUTH_STATE_KEY = 'google_oauth_state';
    private const OAUTH_STATE_SECONDS = 600;
    private const MAIL_TRANSCRIPT_KEY = 'mail_test_transcript';
    private const PREVIOUS_CONNECTION_KEY = 'google.previous_connection';
    private const SECRET_MAX = 1000;

    /** 機密欄位：表單欄位名稱 → [secret_settings 鍵, 顯示名稱] */
    private const MAIL_SECRETS = [
        'smtp_password' => ['mail.smtp_password', 'SMTP 密碼'],
        'brevo_api_key' => ['mail.brevo_api_key', 'Brevo API 金鑰'],
    ];

    // ------------------------------------------------------------------
    // 一般設定
    // ------------------------------------------------------------------

    public function general(Request $request): Response
    {
        return $this->renderGeneral($this->currentValues('general'), []);
    }

    public function saveGeneral(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings')) !== null) {
            return $deny;
        }
        [$values, $errors] = $this->collect('general', $request);
        if ($errors !== []) {
            return $this->renderGeneral($values, $errors, 422);
        }
        $changes = $this->apply($values);
        $this->logChanges('settings.update', '一般設定', $changes);
        return $this->back('/admin/settings', 'success', $changes === [] ? '設定沒有變更。' : '設定已儲存。');
    }

    // ------------------------------------------------------------------
    // 寄信設定
    // ------------------------------------------------------------------

    public function mail(Request $request): Response
    {
        return $this->renderMail($this->currentValues('mail'), []);
    }

    public function saveMail(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/mail')) !== null) {
            return $deny;
        }
        [$values, $errors] = $this->collect('mail', $request);
        $secretOps = [];
        foreach (self::MAIL_SECRETS as $field => [$key, $label]) {
            $clear = $request->input('clear_' . $field) === '1';
            $new = $request->input($field);
            if ($clear) {
                $secretOps[$key] = [null, $label];
            } elseif ($new !== '') {
                if (strlen($new) > self::SECRET_MAX || preg_match('/[\x00-\x1F\x7F]/', $new)) {
                    $errors[$field] = '內容過長或含有不允許的字元。';
                    continue;
                }
                $secretOps[$key] = [$new, $label];
            }
        }
        if ($errors === [] && $values['mail.driver'] !== 'none' && $values['mail.from_address'] === '') {
            $errors['mail.from_address'] = '啟用寄信時必須填寫寄件者 Email。';
        }
        if ($errors === [] && $values['mail.driver'] === 'smtp' && $values['mail.smtp_host'] === '') {
            $errors['mail.smtp_host'] = '使用 SMTP 時必須填寫 SMTP 主機。';
        }
        if ($errors !== []) {
            return $this->renderMail($values, $errors, 422);
        }
        $changes = $this->apply($values);
        foreach ($secretOps as $key => [$value, $label]) {
            if ($value === null) {
                if ($this->app->secrets->has($key)) {
                    $this->app->secrets->delete($key);
                    $changes[] = $label . '：已清除';
                }
                continue;
            }
            $this->app->secrets->set($key, $value, (int)$this->admin()['id']);
            $changes[] = $label . '：已更新'; // 機密值本身絕不寫入紀錄
        }
        $this->logChanges('settings.update', '寄信設定', $changes);
        return $this->back('/admin/settings/mail', 'success', $changes === [] ? '設定沒有變更。' : '寄信設定已儲存。');
    }

    /** 測試連線（mode=check）或寄一封測試信到管理者自己的 Email（mode=send）。 */
    public function testMail(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/mail')) !== null) {
            return $deny;
        }
        $admin = $this->admin();
        $send = $request->input('mode') === 'send';
        $mailer = $this->app->mailer();
        try {
            if ($send) {
                $to = (string)($admin['email'] ?? '');
                if ($to === '') {
                    return $this->back('/admin/settings/mail', 'error', '您的帳號沒有設定 Email，請先到「使用者管理」填寫後再寄送測試信。');
                }
                $site = $this->app->settings->string('system.site_name');
                $mailer->send(new Message($to, "【{$site}】測試信", "這是 {$site} 的測試信。\n\n收到這封信表示寄信設定正確。\n寄送時間（UTC）：" . Clock::utc() . "\n\n若您沒有進行此操作，請檢查管理後台的歷史操作紀錄。"));
                $message = "測試信已送出到 {$to}，請到信箱確認（也請檢查垃圾郵件匣）。";
            } else {
                $mailer->check();
                $message = '連線測試成功。';
            }
            $this->app->session->set(self::MAIL_TRANSCRIPT_KEY, $mailer->transcript());
            $this->log('mail.test', ($send ? '寄送測試信' : '測試連線') . '：成功');
            return $this->back('/admin/settings/mail', 'success', $message);
        } catch (MailException $e) {
            $this->app->session->set(self::MAIL_TRANSCRIPT_KEY, $mailer->transcript());
            $this->log('mail.test', ($send ? '寄送測試信' : '測試連線') . '：' . $e->getMessage(), null, null, null, Log::RESULT_FAILURE);
            return $this->back('/admin/settings/mail', 'error', '失敗：' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 附件儲存位置（Google Drive，決議 #33）
    // ------------------------------------------------------------------

    public function storage(Request $request): Response
    {
        return $this->renderStorage($this->currentValues('storage'), []);
    }

    /** 儲存「新上傳附件的位置」與 Drive 容量上限。 */
    public function saveStorage(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        $defs = SettingsRegistry::forPage('storage');
        // 用戶端 ID、R2 帳號資訊在各自的表單
        unset($defs['storage.google_client_id'], $defs['storage.r2_endpoint'], $defs['storage.r2_bucket']);
        [$values, $errors] = $this->collectDefs($defs, $request);
        if ($errors === [] && $values['attachments.storage'] === GoogleDriveStorage::DRIVER && !$this->drive()->connected()) {
            $errors['attachments.storage'] = '請先完成「連結 Google 帳號」，才能把新附件存到 Google Drive。';
        }
        if ($errors === [] && $values['attachments.storage'] === R2Storage::DRIVER && !$this->r2()->configured()) {
            $errors['attachments.storage'] = '請先完成 Cloudflare R2 設定並按「測試連線」，才能把新附件存到 R2。';
        }
        if ($errors !== []) {
            return $this->renderStorage($values + $this->currentValues('storage'), $errors, 422);
        }
        $changes = $this->apply($values);
        $this->logChanges('settings.update', '附件儲存位置', $changes);
        return $this->back('/admin/settings/storage', 'success', $changes === [] ? '設定沒有變更。' : '附件儲存設定已儲存。');
    }

    /** 儲存 Cloudflare R2 的帳戶 ID、bucket 與 API 金鑰（金鑰留空表示不變更）。 */
    public function saveR2(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        $all = SettingsRegistry::definitions();
        // 管理者通常直接貼上 Cloudflare 顯示的「S3 API」網址（結尾帶 bucket 名稱），在此拆成端點與 bucket
        [$endpoint, $pathBucket] = R2Storage::splitEndpoint($request->input(self::field('storage.r2_endpoint')));
        $bucket = strtolower(trim($request->input(self::field('storage.r2_bucket'))));
        $errors = [];
        if ($pathBucket !== '') {
            if ($bucket === '') {
                $bucket = $pathBucket;
            } elseif ($bucket !== $pathBucket) {
                $errors['storage.r2_bucket'] = "端點網址中的 bucket（{$pathBucket}）與 bucket 欄位（{$bucket}）不同，請確認。";
            }
        }
        $values = ['storage.r2_endpoint' => $endpoint, 'storage.r2_bucket' => $bucket];
        foreach ($values as $k => $v) {
            if (!isset($errors[$k]) && Settings::validate($all[$k], $v) === null) {
                $errors[$k] = $k === 'storage.r2_endpoint'
                    ? '端點格式不正確，應為 https://（32 碼帳戶 ID）.r2.cloudflarestorage.com，歐盟儲存區為 .eu.r2.cloudflarestorage.com。'
                    : self::invalidMessage($all[$k]);
            }
        }
        $secrets = ['r2_access_key_id' => [R2Storage::ACCESS_KEY, 'R2 Access Key ID'], 'r2_secret_access_key' => [R2Storage::SECRET_KEY, 'R2 Secret Access Key']];
        $newSecrets = [];
        foreach ($secrets as $field => [$key, $label]) {
            $v = trim($request->input($field));
            if ($v === '') {
                continue;
            }
            if (strlen($v) > 200 || !preg_match('/^[A-Za-z0-9]+$/', $v)) {
                $errors[$field] = '金鑰格式不正確（只會有英文與數字）。';
                continue;
            }
            $newSecrets[$key] = [$v, $label];
        }
        $usage = (new AttachmentRepository($this->app->db))->usage(R2Storage::DRIVER);
        $moving = $values['storage.r2_endpoint'] !== $this->app->settings->string('storage.r2_endpoint')
            || $values['storage.r2_bucket'] !== $this->app->settings->string('storage.r2_bucket');
        if ($errors === [] && $moving && $usage['files'] > 0) {
            // 舊附件存在原本的 bucket；換位置會讓那些檔案全部讀不到
            $errors['storage.r2_bucket'] = "已有 {$usage['files']} 個附件存放在目前的 R2 bucket，不能更換端點或 bucket。";
        }
        if ($errors !== []) {
            return $this->renderStorage($this->currentValues('storage'), $errors, 422);
        }
        $changes = $this->apply($values);
        foreach ($newSecrets as $key => [$v, $label]) {
            $this->app->secrets->set($key, $v, (int)$this->admin()['id']);
            $changes[] = $label . '：已更新'; // 金鑰本身絕不寫入紀錄
        }
        $this->logChanges('settings.update', 'Cloudflare R2', $changes);
        return $this->back('/admin/settings/storage', 'success', $changes === [] ? '設定沒有變更。' : 'Cloudflare R2 設定已儲存，請按「測試連線」確認。');
    }

    /** 寫入、讀回、刪除一個測試物件，確認帳戶 ID、bucket 與金鑰權限都正確。 */
    public function testR2(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        try {
            $this->r2()->selfTest();
        } catch (DriveException $e) {
            $this->log('storage.r2.test', $e->getMessage(), null, null, null, Log::RESULT_FAILURE);
            return $this->back('/admin/settings/storage', 'error', '測試失敗：' . $e->getMessage());
        }
        $this->log('storage.r2.test', '寫入、讀取、刪除測試檔皆成功');
        return $this->back('/admin/settings/storage', 'success', 'Cloudflare R2 連線測試成功（寫入、讀取、刪除都正常）。現在可以把「新上傳附件的儲存位置」改為 Cloudflare R2。');
    }

    private function r2(): R2Storage
    {
        return (new StorageManager($this->app))->r2();
    }

    /** 儲存 Google OAuth 用戶端 ID 與密鑰。 */
    public function saveGoogleClient(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        [$values, $errors] = $this->collectDefs(['storage.google_client_id' => SettingsRegistry::definitions()['storage.google_client_id']], $request);
        $secret = $request->input('google_client_secret');
        if ($secret !== '' && (strlen($secret) > self::SECRET_MAX || !preg_match('/^[\x21-\x7E]+$/', $secret))) {
            $errors['google_client_secret'] = '用戶端密鑰格式不正確。';
        }
        $drive = $this->drive();
        $changingClient = $values['storage.google_client_id'] !== $drive->clientId() || $secret !== '';
        if ($errors === [] && $drive->connection() !== null && $changingClient) {
            // 授權綁定在用戶端上；直接更換會讓既有授權失效，必須先中斷連結
            $errors['storage.google_client_id'] = '目前已連結 Google 帳號，請先「中斷連結」再更換用戶端 ID 或密鑰。';
        }
        if ($errors !== []) {
            return $this->renderStorage($this->currentValues('storage'), $errors, 422);
        }
        $changes = $this->apply($values);
        if ($secret !== '') {
            $this->app->secrets->set(GoogleDrive::CLIENT_SECRET_KEY, $secret, (int)$this->admin()['id']);
            $changes[] = 'Google OAuth 用戶端密鑰：已更新';
        }
        $this->logChanges('settings.update', 'Google OAuth 用戶端', $changes);
        return $this->back('/admin/settings/storage', 'success', $changes === [] ? '設定沒有變更。' : 'Google OAuth 用戶端已儲存，下一步請按「連結 Google 帳號」。');
    }

    /**
     * 開始 OAuth 授權。用 GET（連結）而不是表單 POST：CSP 的 form-action 'self' 會擋下
     * 表單送出後轉址到 accounts.google.com。CSRF 由 state 參數防護（只存在本人的 Session）。
     */
    public function connectGoogle(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        $drive = $this->drive();
        if (!$drive->configured()) {
            return $this->back('/admin/settings/storage', 'error', '請先填寫 Google OAuth 用戶端 ID 與密鑰。');
        }
        if (!HttpClient::available()) {
            return $this->back('/admin/settings/storage', 'error', '主機未啟用 cURL，無法連線 Google。');
        }
        $state = bin2hex(random_bytes(32));
        $this->app->session->set(self::OAUTH_STATE_KEY, ['state' => $state, 'expires' => Clock::now() + self::OAUTH_STATE_SECONDS]);
        return Response::redirect($drive->authUrl($state, $this->redirectUri()), 302);
    }

    public function googleCallback(Request $request): Response
    {
        $saved = $this->app->session->get(self::OAUTH_STATE_KEY);
        $this->app->session->remove(self::OAUTH_STATE_KEY); // state 只能用一次
        $state = $request->query('state');
        if (!is_array($saved) || !is_string($saved['state'] ?? null) || (int)($saved['expires'] ?? 0) < Clock::now()
            || $state === '' || !hash_equals($saved['state'], $state)) {
            $this->log('storage.google.connect', '授權回應驗證失敗（state 不符或已過期）', null, null, null, Log::RESULT_DENIED);
            return $this->back('/admin/settings/storage', 'error', '授權已過期或來源不明，請重新按「連結 Google 帳號」。');
        }
        if ($request->query('error') !== '') {
            $this->log('storage.google.connect', '使用者在 Google 取消授權', null, null, null, Log::RESULT_FAILURE);
            return $this->back('/admin/settings/storage', 'warning', '已取消 Google 授權，沒有任何變更。');
        }
        $code = $request->query('code');
        if ($code === '' || strlen($code) > 1000) {
            return $this->back('/admin/settings/storage', 'error', 'Google 沒有回傳授權碼，請重試。');
        }
        $drive = $this->drive();
        $previous = $drive->connection() ?? $this->app->secrets->getJson(self::PREVIOUS_CONNECTION_KEY);
        $usage = (new AttachmentRepository($this->app->db))->usage(GoogleDriveStorage::DRIVER);
        // 既有附件存在原本的帳號；換帳號會讓那些檔案全部讀不到
        $required = $previous !== null && $usage['files'] > 0 && !empty($previous['email']) ? (string)$previous['email'] : null;
        try {
            $result = $drive->completeAuthorization($code, $this->redirectUri(), $previous, $required);
        } catch (AccountMismatchException $e) {
            $this->log('storage.google.connect', "拒絕連結 {$e->email}：既有附件存放在 {$required}", null, null, null, Log::RESULT_DENIED);
            return $this->back('/admin/settings/storage', 'error',
                "已有 {$usage['files']} 個附件存放在 Google 帳號 {$required}，請改用該帳號連結；換成其他帳號會讓這些檔案無法讀取。");
        } catch (DriveException | \RuntimeException $e) {
            $this->log('storage.google.connect', '連結失敗：' . $e->getMessage(), null, null, null, Log::RESULT_FAILURE);
            return $this->back('/admin/settings/storage', 'error', '連結失敗：' . $e->getMessage());
        }
        // 不撤銷舊的 Refresh Token：對同一用戶端與帳號撤銷時，Google 可能連同剛取得的新授權一起撤銷
        $this->app->secrets->setJson(GoogleDrive::CONNECTION_KEY, $result + [
            'connected_at' => Clock::utc(),
            'connected_by' => (int)$this->admin()['id'],
        ], (int)$this->admin()['id']);
        $this->app->secrets->delete(self::PREVIOUS_CONNECTION_KEY);
        $this->log('storage.google.connect', '已連結 Google 帳號 ' . $result['email']);
        return $this->back('/admin/settings/storage', 'success', '已連結 Google 帳號 ' . $result['email'] . '。若要讓新附件存到 Google Drive，請在下方「新上傳附件的儲存位置」選擇 Google Drive。');
    }

    public function disconnectGoogle(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/settings/storage')) !== null) {
            return $deny;
        }
        $drive = $this->drive();
        $connection = $drive->connection();
        if ($connection === null) {
            return $this->back('/admin/settings/storage', 'info', '目前沒有連結 Google 帳號。');
        }
        // 記住原本的帳號與資料夾：重新連結時檢查是否為同一帳號，並沿用原資料夾
        $this->app->secrets->setJson(self::PREVIOUS_CONNECTION_KEY, ['email' => $connection['email'], 'folder_id' => $connection['folder_id']], (int)$this->admin()['id']);
        $drive->revoke();
        $changes = ['中斷連結 Google 帳號 ' . $connection['email']];
        if ($this->app->settings->string('attachments.storage') === GoogleDriveStorage::DRIVER) {
            $this->app->settings->set('attachments.storage', 'local', (int)$this->admin()['id']);
            $changes[] = '新上傳附件的儲存位置：Google Drive → 本機';
        }
        $this->log('storage.google.disconnect', implode('；', $changes));
        return $this->back('/admin/settings/storage', 'success', '已中斷連結。存放在 Google Drive 的檔案沒有被刪除，但在重新連結同一個帳號前無法讀取。');
    }

    // ------------------------------------------------------------------
    // 共用
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function currentValues(string $page): array
    {
        $values = [];
        foreach (array_keys(SettingsRegistry::forPage($page)) as $key) {
            $values[$key] = $this->app->settings->get($key);
        }
        return $values;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function collect(string $page, Request $request): array
    {
        return $this->collectDefs(SettingsRegistry::forPage($page), $request);
    }

    /**
     * 讀取並驗證表單。欄位名稱把「.」換成「__」（PHP 會把表單名稱中的「.」轉成「_」）。
     *
     * @param array<string, array<string, mixed>> $defs
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function collectDefs(array $defs, Request $request): array
    {
        $values = [];
        $errors = [];
        foreach ($defs as $key => $def) {
            $raw = $request->input(self::field($key));
            if ($def['type'] === 'bool') {
                $raw = $raw === '1' ? '1' : '0';
            } elseif ($def['type'] === 'string') {
                $raw = trim($raw);
            }
            $valid = Settings::validate($def, $raw);
            if ($valid === null) {
                $errors[$key] = self::invalidMessage($def);
                $values[$key] = $raw;
                continue;
            }
            $values[$key] = $valid;
        }
        return [$values, $errors];
    }

    /**
     * 儲存並回傳變更說明（新舊值）。
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function apply(array $values): array
    {
        $defs = SettingsRegistry::definitions();
        $changes = [];
        foreach ($values as $key => $value) {
            $old = $this->app->settings->get($key);
            if ($old === $value) {
                continue;
            }
            $this->app->settings->set($key, $value, (int)$this->admin()['id']);
            $changes[] = $defs[$key]['label'] . '：' . self::display($defs[$key], $old) . ' → ' . self::display($defs[$key], $value);
        }
        return $changes;
    }

    /** @param list<string> $changes */
    private function logChanges(string $action, string $section, array $changes): void
    {
        if ($changes !== []) {
            $this->log($action, $section . '：' . mb_substr(implode('；', $changes), 0, 900));
        }
    }

    /** @param array<string, mixed> $def */
    public static function display(array $def, mixed $value): string
    {
        return match ($def['type']) {
            'bool' => $value ? '開啟' : '關閉',
            'enum' => (string)($def['options'][$value] ?? $value),
            default => (string)$value === '' ? '（空白）' : (string)$value,
        };
    }

    /** @param array<string, mixed> $def */
    private static function invalidMessage(array $def): string
    {
        return match ($def['type']) {
            'int' => "請輸入 {$def['min']}–{$def['max']} 之間的整數。",
            'enum' => '請從選項中選擇。',
            'timezone' => '請選擇有效的時區。',
            'string' => match ($def['format'] ?? '') {
                'email' => 'Email 格式不正確。',
                'host' => '主機名稱格式不正確（只能有英數字、點與連字號）。',
                'google_client_id' => '用戶端 ID 格式不正確。',
                default => '最多 ' . ($def['max'] ?? 255) . ' 個字，且不可換行。',
            },
            default => '值不正確。',
        };
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function drive(): GoogleDrive
    {
        return (new StorageManager($this->app))->drive();
    }

    /** 必須與 Google Cloud Console「已授權的重新導向 URI」完全相同 */
    private function redirectUri(): string
    {
        return $this->app->baseUrl() . '/admin/settings/storage/google/callback';
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderGeneral(array $values, array $errors, int $status = 200): Response
    {
        $groups = [];
        foreach (SettingsRegistry::forPage('general') as $key => $def) {
            $groups[$def['category']][$key] = $def;
        }
        return $this->render('admin/settings_general', [
            'scripts' => ['/assets/admin-settings.js'],
            'title' => '系統設定',
            'groups' => $groups,
            'values' => $values,
            'errors' => $errors,
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/settings',
        ], $status);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderMail(array $values, array $errors, int $status = 200): Response
    {
        $transcript = $this->app->session->get(self::MAIL_TRANSCRIPT_KEY);
        $this->app->session->remove(self::MAIL_TRANSCRIPT_KEY);
        return $this->render('admin/settings_mail', [
            'title' => '寄信設定',
            'defs' => SettingsRegistry::forPage('mail'),
            'values' => $values,
            'errors' => $errors,
            'secretsSet' => [
                'smtp_password' => $this->app->secrets->has('mail.smtp_password'),
                'brevo_api_key' => $this->app->secrets->has('mail.brevo_api_key'),
            ],
            'adminEmail' => (string)($this->admin()['email'] ?? ''),
            'transcript' => is_array($transcript) ? $transcript : [],
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/settings/mail',
        ], $status);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderStorage(array $values, array $errors, int $status = 200): Response
    {
        $drive = $this->drive();
        $repo = new AttachmentRepository($this->app->db);
        return $this->render('admin/settings_storage', [
            'title' => '附件儲存位置',
            'defs' => SettingsRegistry::forPage('storage'),
            'values' => $values,
            'errors' => $errors,
            'clientSecretSet' => $this->app->secrets->has(GoogleDrive::CLIENT_SECRET_KEY),
            'r2KeysSet' => [
                'r2_access_key_id' => $this->app->secrets->has(R2Storage::ACCESS_KEY),
                'r2_secret_access_key' => $this->app->secrets->has(R2Storage::SECRET_KEY),
            ],
            'r2Configured' => $this->r2()->configured(),
            'connection' => $drive->connection(),
            'previous' => $this->app->secrets->getJson(self::PREVIOUS_CONNECTION_KEY),
            'redirectUri' => $this->redirectUri(),
            'curl' => HttpClient::available(),
            'usage' => [
                'local' => $repo->usage('local'),
                GoogleDriveStorage::DRIVER => $repo->usage(GoogleDriveStorage::DRIVER),
                R2Storage::DRIVER => $repo->usage(R2Storage::DRIVER),
            ],
            'quota' => [
                'local' => $this->app->settings->int('attachments.quota_mb'),
                GoogleDriveStorage::DRIVER => $this->app->settings->int('attachments.drive_quota_mb'),
                R2Storage::DRIVER => $this->app->settings->int('attachments.r2_quota_mb'),
            ],
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/settings/storage',
        ], $status);
    }
}
