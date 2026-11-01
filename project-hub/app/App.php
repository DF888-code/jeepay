<?php

declare(strict_types=1);

namespace ProjectHub;

use ProjectHub\Activity\ActivityLogger;
use ProjectHub\Auth\AuthState;
use ProjectHub\Auth\LoginThrottle;
use ProjectHub\Auth\UserRepository;
use ProjectHub\Controllers\AccountController;
use ProjectHub\Controllers\AuthController;
use ProjectHub\Controllers\HomeController;
use ProjectHub\Controllers\InstallController;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Http\Session;
use ProjectHub\Settings\Settings;
use ProjectHub\Support\Config;
use ProjectHub\Support\Crypto;
use ProjectHub\Support\Database;
use ProjectHub\Support\View;
use Throwable;

/**
 * 應用程式進入點：組裝服務、套用安全中介層、依路由的存取等級把關，最後才交給控制器。
 */
final class App
{
    /** 路由存取等級 */
    public const ACCESS_GUEST = 'guest';      // 未登入才能使用（登入頁）
    public const ACCESS_TOTP = 'totp';        // 已過密碼、待驗證碼
    public const ACCESS_ENROLL = 'enroll';    // 已過密碼、必須綁定 2FA
    public const ACCESS_SESSION = 'session';  // 任何已過密碼的狀態（登出）
    public const ACCESS_AUTH = 'auth';        // 完整登入（密碼 + 2FA）
    public const ACCESS_INSTALL = 'install';  // 安裝流程，自行檢查

    public Config $config;
    public Database $db;
    public Session $session;
    public Settings $settings;
    public View $view;
    public Crypto $crypto;
    public UserRepository $users;
    public LoginThrottle $throttle;
    public AuthState $auth;
    public ActivityLogger $logger;
    public Request $request;

    public function __construct(public readonly string $basePath)
    {
    }

    /** @return array<string, array{0:string,1:string,2:string}> 「方法 路徑」→ [存取等級, 控制器, 方法] */
    private static function routes(): array
    {
        return [
            'GET /' => [self::ACCESS_AUTH, HomeController::class, 'index'],
            'GET /login' => [self::ACCESS_GUEST, AuthController::class, 'showLogin'],
            'POST /login' => [self::ACCESS_GUEST, AuthController::class, 'login'],
            'GET /login/totp' => [self::ACCESS_TOTP, AuthController::class, 'showTotp'],
            'POST /login/totp' => [self::ACCESS_TOTP, AuthController::class, 'verifyTotp'],
            'GET /2fa/setup' => [self::ACCESS_ENROLL, AuthController::class, 'showEnroll'],
            'POST /2fa/setup' => [self::ACCESS_ENROLL, AuthController::class, 'enroll'],
            'POST /logout' => [self::ACCESS_SESSION, AuthController::class, 'logout'],
            'GET /account' => [self::ACCESS_AUTH, AccountController::class, 'index'],
            'POST /account/password' => [self::ACCESS_AUTH, AccountController::class, 'changePassword'],
            'POST /account/2fa/change' => [self::ACCESS_AUTH, AccountController::class, 'startTotpChange'],
            'GET /account/2fa/change' => [self::ACCESS_AUTH, AccountController::class, 'showTotpChange'],
            'POST /account/2fa/confirm' => [self::ACCESS_AUTH, AccountController::class, 'confirmTotpChange'],
            'GET /api/me' => [self::ACCESS_AUTH, AccountController::class, 'apiMe'],
            'GET /install' => [self::ACCESS_INSTALL, InstallController::class, 'show'],
            'POST /install' => [self::ACCESS_INSTALL, InstallController::class, 'install'],
        ];
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $requestId = bin2hex(random_bytes(6));
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            // 詳細錯誤只寫入伺服器日誌，畫面只顯示追蹤代碼，避免洩漏路徑、SQL 或設定
            error_log(sprintf('[project-hub] #%s %s: %s @ %s:%d', $requestId, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            $response = $request->wantsJson()
                ? Response::json(['error' => '系統發生錯誤', 'trace_id' => $requestId], 500)
                : Response::html($this->plainPage('系統發生錯誤', '請稍後再試。若持續發生，請將追蹤代碼提供給管理者：' . $requestId), 500);
        }
        return $this->withSecurityHeaders($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        $config = Config::load($this->basePath);
        $installed = is_file(($config === null ? $this->basePath . '/storage' : $this->storagePathFor($config)) . '/installed.lock');
        if ($config === null || $config->problems() !== []) {
            // 尚未安裝時才顯示設定說明；已安裝後設定損壞只顯示一般錯誤，不提供任何提示
            if ($installed) {
                throw new \RuntimeException('設定檔無效');
            }
            return InstallController::configHelp($this, $config);
        }
        $this->config = $config;

        if ($config->bool('force_https', true) && !$request->isHttps()) {
            if (!$request->isSafeMethod()) {
                return Response::html($this->plainPage('需要 HTTPS', '請改用 https:// 連線。'), 400);
            }
            return Response::redirect($this->baseUrl() . $request->uri(), 301);
        }

        $this->boot($request);
        $this->session->start(
            $config->string('session_cookie', 'phsid'),
            $request->isHttps(),
            $this->sessionPath(),
            $this->settings->int('security.session_absolute_hours') * 3600
        );

        $route = self::routes()[$request->method . ' ' . $request->path]
            ?? ($request->method === 'HEAD' ? (self::routes()['GET ' . $request->path] ?? null) : null);
        if ($route === null) {
            return $this->notFound($request);
        }
        [$access, $controller, $action] = $route;

        if (!$installed && $access !== self::ACCESS_INSTALL) {
            return Response::redirect('/install');
        }
        if ($installed && $access !== self::ACCESS_INSTALL) {
            $reason = $this->auth->resolve();
            if ($reason !== null) {
                $this->session->flash('warning', $reason);
            }
            $gate = $this->gate($access, $request);
            if ($gate !== null) {
                return $gate;
            }
        }

        if (!$request->isSafeMethod()) {
            $token = $request->input('_csrf') ?: $request->header('X-CSRF-Token');
            if (!$this->session->validCsrf($token)) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'CSRF 驗證失敗'], 400)
                    : Response::html($this->plainPage('表單已過期', '請重新整理頁面後再試一次。'), 400);
            }
        }

        $this->view->share('csrf', $this->session->csrfToken());
        $this->view->share('flash', $this->session->takeFlash());
        $this->view->share('currentUser', $installed ? $this->auth->user() : null);
        $this->view->share('authStage', $installed ? $this->auth->stage() : AuthState::NONE);

        return (new $controller($this))->{$action}($request);
    }

    /** 依登入狀態決定能否進入路由；不符合時導向正確的步驟。 */
    private function gate(string $access, Request $request): ?Response
    {
        $stage = $this->auth->stage();
        $allowed = match ($access) {
            self::ACCESS_GUEST => $stage === AuthState::NONE,
            self::ACCESS_TOTP => $stage === AuthState::TOTP,
            self::ACCESS_ENROLL => $stage === AuthState::ENROLL,
            self::ACCESS_SESSION => $stage !== AuthState::NONE,
            self::ACCESS_AUTH => $stage === AuthState::FULL,
            default => false,
        };
        if ($allowed) {
            return null;
        }
        if ($request->wantsJson()) {
            return $stage === AuthState::ENROLL
                ? Response::json(['error' => '需要先完成 2FA 綁定'], 403)
                : Response::json(['error' => '需要登入'], 401);
        }
        return Response::redirect(match ($stage) {
            AuthState::TOTP => '/login/totp',
            AuthState::ENROLL => '/2fa/setup',
            AuthState::FULL => '/',
            default => '/login',
        });
    }

    private function boot(Request $request): void
    {
        $this->db = new Database((array)$this->config->get('db', []));
        $this->settings = new Settings($this->db);
        $this->crypto = new Crypto($this->config->appKey());
        $this->session = new Session();
        $this->users = new UserRepository($this->db);
        $this->throttle = new LoginThrottle($this->db, $this->settings, $this->crypto);
        $this->auth = new AuthState($this->session, $this->users, $this->settings);
        $this->logger = new ActivityLogger($this->db, $request);
        $this->view = new View($this->basePath . '/views', [
            'siteName' => $this->settings->string('system.site_name'),
            'timezone' => $this->settings->string('system.timezone'),
        ]);
    }

    /** 執行期資料目錄（Session、安裝鎖）。測試時可用 storage_dir 指到獨立目錄。 */
    public function storagePath(): string
    {
        return $this->storagePathFor($this->config);
    }

    private function storagePathFor(Config $config): string
    {
        $dir = $config->string('storage_dir');
        return $dir !== '' ? rtrim($dir, '/') : $this->basePath . '/storage';
    }

    private function sessionPath(): ?string
    {
        $dir = $this->storagePath() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    public function baseUrl(): string
    {
        $configured = rtrim($this->config->string('base_url'), '/');
        if ($configured !== '') {
            return $configured;
        }
        // 未設定 base_url 時才使用 Host 標頭，並限制字元避免標頭注入
        $host = preg_match('/^[A-Za-z0-9.\-:]+$/', $this->request->host()) ? $this->request->host() : 'localhost';
        return 'https://' . $host;
    }

    private function notFound(Request $request): Response
    {
        return $request->wantsJson()
            ? Response::json(['error' => '找不到資源'], 404)
            : Response::html($this->plainPage('找不到頁面', '您要找的頁面不存在。'), 404);
    }

    /** 不依賴資料庫與樣板的簡單頁面（錯誤時也能顯示）。 */
    public function plainPage(string $title, string $message): string
    {
        return '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . '</title><script src="/assets/theme.js"></script>'
            . '<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.8/bootstrap.min.css">'
            . '<link rel="stylesheet" href="/assets/app.css"></head>'
            . '<body class="bg-body-tertiary"><main class="container py-5"><div class="card shadow-sm auth-card"><div class="card-body p-4">'
            . '<h1 class="h4">' . e($title) . '</h1><p>' . e($message) . '</p>'
            . '<a class="btn btn-outline-primary" href="/">回首頁</a></div></div></main></body></html>';
    }

    private function withSecurityHeaders(Response $response, Request $request): Response
    {
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'")
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (!isset($response->headers['Cache-Control'])) {
            // 登入後頁面含個人資料，不允許瀏覽器或代理快取
            $response->withHeader('Cache-Control', 'no-store');
        }
        if ($request->isHttps()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
