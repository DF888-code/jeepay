<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\App;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\UserRepository;
use ProjectHub\Database\Migrator;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Config;
use ProjectHub\Support\View;
use Throwable;

/**
 * 首次安裝：建立資料表與第一位最高權限管理者。
 *
 * 安全設計：
 * - 必須輸入 config.php 中的 install_token，避免網站剛上線時被陌生人搶先建立管理者；
 * - 資料庫已有使用者時一律拒絕，不覆蓋、不刪除任何資料；
 * - 完成後寫入 storage/installed.lock，安裝頁永久停用。
 */
final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        if ($this->isLocked()) {
            return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
        }
        try {
            if ($this->hasUsers()) {
                // 已有使用者但缺少 lock（例如 storage 被清空）：補回 lock，不做任何資料變更
                $this->writeLock();
                return Response::redirect('/login');
            }
        } catch (Throwable) {
            return $this->render('install/form', ['title' => '安裝', 'dbError' => true, 'old' => []], 500);
        }
        return $this->render('install/form', ['title' => '安裝', 'dbError' => false, 'old' => [],
            'minLength' => $this->app->settings->int('security.password_min_length')]);
    }

    public function install(Request $request): Response
    {
        if ($this->isLocked()) {
            return Response::html($this->app->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
        }
        $old = [
            'username' => trim($request->input('username')),
            'display_name' => trim($request->input('display_name')),
            'email' => trim($request->input('email')),
        ];
        $minLength = $this->app->settings->int('security.password_min_length');
        $fail = fn(string $msg, int $status = 422): Response => $this->render(
            'install/form',
            ['title' => '安裝', 'dbError' => false, 'old' => $old, 'error' => $msg, 'minLength' => $minLength],
            $status
        );

        $expected = $this->app->config->string('install_token');
        if (strlen($expected) < 32) {
            return $fail('config.php 尚未設定 install_token（至少 32 字元）。');
        }
        if (!hash_equals($expected, $request->input('install_token'))) {
            return $fail('安裝金鑰錯誤。', 403);
        }
        if (!UserRepository::isValidUsername($old['username'])) {
            return $fail('帳號需為 3–50 個英數字，可包含 . _ -。');
        }
        if ($old['display_name'] === '' || mb_strlen($old['display_name']) > 100) {
            return $fail('請輸入顯示名稱（最多 100 字）。');
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            return $fail('請輸入有效的 Email（最高權限管理者救援信會寄到這裡）。');
        }
        $error = Passwords::policyError($request->input('password'), $request->input('password_confirm'), $minLength);
        if ($error !== null) {
            return $fail(str_replace('新密碼', '密碼', $error));
        }

        try {
            (new Migrator($this->app->db, $this->app->basePath . '/database/migrations'))->migrate();
        } catch (Throwable $e) {
            error_log('[project-hub] 安裝時資料表建立失敗：' . get_class($e) . ' ' . $e->getMessage());
            return $fail('資料表建立失敗，請檢查資料庫權限。', 500);
        }
        if ($this->hasUsers()) {
            $this->writeLock();
            return $fail('系統已經安裝過，不會建立新的管理者。', 409);
        }

        $id = $this->app->users->create(
            $old['username'],
            $old['display_name'],
            $old['email'],
            Passwords::hash($request->input('password')),
            true,
            null
        );
        if (!$this->writeLock()) {
            return $fail('管理者已建立，但無法寫入 storage/installed.lock，請確認 storage 目錄可寫入。', 500);
        }
        $this->app->logger->log('admin', 'system.install', Log::RESULT_SUCCESS, $id, $old['username'], '建立第一位最高權限管理者', null, null, $id);
        return $this->back('/login', 'success', '安裝完成，請登入並設定兩步驟驗證。');
    }

    /** 設定檔不存在或不完整時顯示的說明頁（僅在尚未安裝時出現）。 */
    public static function configHelp(App $app, ?Config $config): Response
    {
        $view = new View($app->basePath . '/views', ['siteName' => 'Project Hub', 'csrf' => '', 'flash' => [], 'currentUser' => null, 'authStage' => 'none']);
        return Response::html($view->render('install/config_help', [
            'title' => '尚未完成設定',
            'problems' => $config === null ? ['找不到 config/config.php'] : $config->problems(),
            // 每次產生新的隨機值，只是方便複製的範例；本系統不會保存它們
            'sampleAppKey' => base64_encode(random_bytes(32)),
            'sampleInstallToken' => bin2hex(random_bytes(24)),
        ]), 503);
    }

    private function hasUsers(): bool
    {
        try {
            return $this->app->users->count() > 0;
        } catch (Throwable $e) {
            // 資料表尚未建立
            if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), '1146')) {
                return false;
            }
            throw $e;
        }
    }

    private function lockFile(): string
    {
        return $this->app->storagePath() . '/installed.lock';
    }

    private function isLocked(): bool
    {
        return is_file($this->lockFile());
    }

    private function writeLock(): bool
    {
        $dir = dirname($this->lockFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return @file_put_contents($this->lockFile(), gmdate('c') . "\n") !== false;
    }
}
