<?php

declare(strict_types=1);

namespace ProjectHub\Controllers;

use ProjectHub\Activity\ActivityLogger as Log;
use ProjectHub\Auth\Passwords;
use ProjectHub\Auth\UserRepository;
use ProjectHub\Authorization\GlobalPermissions;
use ProjectHub\Database\Migrator;
use ProjectHub\Http\Request;
use ProjectHub\Http\Response;
use ProjectHub\Support\Clock;

/**
 * 管理後台：使用者管理與全域權限（階段 4）。
 *
 * 路由層已確保只有完整登入的最高權限管理者能進入，其他人一律看到 404。
 * 會改變帳號安全狀態的操作（建立帳號、停用、重設密碼／2FA、修改權限）需要「管理者再驗證」：
 * 輸入自己的驗證碼後 10 分鐘內有效，避免每個操作都要等下一組驗證碼。
 */
final class AdminUserController extends AdminController
{

    public function home(Request $request): Response
    {
        return Response::redirect('/admin/users');
    }

    public function index(Request $request): Response
    {
        return $this->renderUsers([]);
    }

    public function create(Request $request): Response
    {
        if (($deny = $this->requireElevated('/admin/users')) !== null) {
            return $deny;
        }
        $old = [
            'username' => trim($request->input('username')),
            'display_name' => trim($request->input('display_name')),
            'email' => trim($request->input('email')),
        ];
        $error = null;
        if (!UserRepository::isValidUsername($old['username'])) {
            $error = '帳號需為 3–50 個英數字，可包含 . _ -。';
        } elseif ($this->app->users->findByUsername($old['username']) !== null) {
            $error = '此帳號已存在（帳號不分大小寫）。';
        } elseif ($old['display_name'] === '' || mb_strlen($old['display_name']) > 100) {
            $error = '請輸入顯示名稱（最多 100 字）。';
        } elseif ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Email 格式不正確。';
        }
        if ($error !== null) {
            return $this->renderUsers($old, $error, 422);
        }

        $admin = $this->admin();
        $temporary = Passwords::temporary();
        $id = $this->app->db->transaction(function () use ($old, $temporary, $admin): int {
            $id = $this->app->users->create(
                $old['username'],
                $old['display_name'],
                $old['email'] === '' ? null : $old['email'],
                Passwords::hash($temporary),
                false,
                (int)$admin['id'],
                true
            );
            // 新帳號預設沒有任何全域權限（預設拒絕），由管理者另行設定
            $this->globalPermissions()->ensureRow($id, (int)$admin['id']);
            return $id;
        });
        $this->app->logger->log('admin', 'admin.user.create', Log::RESULT_SUCCESS, (int)$admin['id'], (string)$admin['username'],
            '建立使用者 ' . $old['username'], null, null, $id);
        $this->app->session->set('one_time_password', ['name' => $old['display_name'], 'password' => $temporary]);
        return $this->back('/admin/users', 'success', "已建立使用者「{$old['display_name']}」。新帳號目前沒有任何權限，請到「全域權限」設定。");
    }

    public function updateProfile(Request $request, int $id): Response
    {
        $target = $this->app->users->findManageable($id);
        if ($target === null) {
            return $this->notFound();
        }
        $name = trim($request->input('display_name'));
        $email = trim($request->input('email'));
        if ($name === '' || mb_strlen($name) > 100) {
            return $this->back('/admin/users', 'error', '請輸入顯示名稱（最多 100 字）。');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->back('/admin/users', 'error', 'Email 格式不正確。');
        }
        $this->app->users->updateProfile($id, $name, $email === '' ? null : $email);
        $this->logTarget('admin.user.update_profile', $target, '修改顯示名稱或 Email');
        return $this->back('/admin/users', 'success', "已更新「{$name}」的基本資料。");
    }

    public function updateStatus(Request $request, int $id): Response
    {
        if (($deny = $this->requireElevated('/admin/users')) !== null) {
            return $deny;
        }
        $target = $this->app->users->findManageable($id);
        if ($target === null) {
            return $this->notFound();
        }
        $status = $request->input('status');
        if (!in_array($status, ['active', 'disabled'], true)) {
            return $this->back('/admin/users', 'error', '狀態不正確。');
        }
        $this->app->users->setStatus($id, $status);
        $disabled = $status === 'disabled';
        $this->logTarget($disabled ? 'admin.user.disable' : 'admin.user.enable', $target);
        return $this->back('/admin/users', 'success', $disabled
            ? "已停用「{$target['display_name']}」，對方的登入狀態已立即失效。"
            : "已重新啟用「{$target['display_name']}」。");
    }

    public function resetTotp(Request $request, int $id): Response
    {
        $target = $this->app->users->findManageable($id);
        if ($target === null) {
            return $this->notFound();
        }
        // 高風險操作：不沿用 10 分鐘的再驗證狀態，每次都必須在確認視窗輸入新的驗證碼
        if (($error = $this->verifyAdminCode($request)) !== null) {
            $this->logTarget('admin.user.2fa_reset', $target, '驗證碼錯誤，未執行', Log::RESULT_FAILURE);
            return $this->back('/admin/users', 'error', '重設 2FA未執行：' . $error);
        }
        $this->app->users->resetTotp($id);
        $this->logTarget('admin.user.2fa_reset', $target, '解除 2FA 綁定，下次登入需重新綁定');
        return $this->back('/admin/users', 'success', "已解除「{$target['display_name']}」的 2FA 綁定。對方下次登入時必須重新掃描 QR Code，完成前無法使用系統。");
    }

    public function resetPassword(Request $request, int $id): Response
    {
        $target = $this->app->users->findManageable($id);
        if ($target === null) {
            return $this->notFound();
        }
        // 高風險操作：不沿用 10 分鐘的再驗證狀態，每次都必須在確認視窗輸入新的驗證碼
        if (($error = $this->verifyAdminCode($request)) !== null) {
            $this->logTarget('admin.user.password_reset', $target, '驗證碼錯誤，未執行', Log::RESULT_FAILURE);
            return $this->back('/admin/users', 'error', '重設密碼未執行：' . $error);
        }
        $temporary = Passwords::temporary();
        $this->app->users->setTemporaryPassword($id, Passwords::hash($temporary));
        $this->logTarget('admin.user.password_reset', $target, '設定臨時密碼，下次登入需變更');
        $this->app->session->set('one_time_password', ['name' => (string)$target['display_name'], 'password' => $temporary]);
        return $this->back('/admin/users', 'success', "已重設「{$target['display_name']}」的密碼，對方所有裝置已登出。");
    }

    public function permissions(Request $request): Response
    {
        return $this->render('admin/permissions', [
            'title' => '全域權限',
            'users' => $this->app->users->listManageable(),
            'fields' => GlobalPermissions::FIELDS,
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/permissions',
            'scripts' => ['/assets/admin-permissions.js'],
        ]);
    }

    /** 只更新網址中指定的這一位員工；其他員工的資料不會被送出，也不會被修改。 */
    public function savePermissions(Request $request, int $id): Response
    {
        if (($deny = $this->requireElevated('/admin/permissions')) !== null) {
            return $deny;
        }
        $target = $this->app->users->findManageable($id);
        $before = $target === null ? null : $this->globalPermissions()->find($id);
        if ($target === null || $before === null) {
            return $this->notFound();
        }
        $values = [];
        foreach (array_keys(GlobalPermissions::FIELDS) as $field) {
            $values[$field] = $request->input($field) === '1';
        }
        // 決議 #8：編輯、刪除都必須能先看到專案，因此自動加上「查看」
        $autoView = !$values['can_view_projects'] && ($values['can_edit_projects'] || $values['can_delete_projects']);
        if ($autoView) {
            $values['can_view_projects'] = true;
        }

        $name = (string)$target['display_name'];
        $saved = $this->globalPermissions()->save($id, $values, (int)$request->input('row_version', '0'), (int)$this->admin()['id']);
        if (!$saved) {
            $this->logTarget('admin.permission.update', $target, '版本衝突，未儲存', Log::RESULT_FAILURE);
            return $this->back('/admin/permissions', 'error', "「{$name}」的權限已被其他管理者修改，本次未儲存。請重新載入頁面確認後再設定。");
        }

        $changes = [];
        foreach (GlobalPermissions::FIELDS as $field => $label) {
            if ((bool)$before[$field] !== $values[$field]) {
                $changes[] = $label . '：' . ($before[$field] ? '有' : '無') . '→' . ($values[$field] ? '有' : '無');
            }
        }
        $this->logTarget('admin.permission.update', $target, $changes === [] ? '內容未變更' : implode('，', $changes));
        return $this->back('/admin/permissions', 'success', "已儲存「{$name}」的全域權限。"
            . ($autoView ? '（編輯或刪除需要能查看專案，已自動加上「查看」）' : ''));
    }

    /** 管理者再驗證：輸入自己目前的驗證碼，10 分鐘內可執行敏感的管理操作。 */
    public function reauth(Request $request): Response
    {
        $return = self::safeReturn($request->input('return'));
        $admin = $this->admin();
        if (($error = $this->verifyAdminCode($request)) !== null) {
            $this->app->logger->log('admin', 'admin.reauth', Log::RESULT_FAILURE, (int)$admin['id'], (string)$admin['username']);
            return $this->back($return, 'error', $error);
        }
        $this->app->session->set(self::REAUTH_KEY, Clock::now() + self::REAUTH_SECONDS);
        $this->app->logger->log('admin', 'admin.reauth', Log::RESULT_SUCCESS, (int)$admin['id'], (string)$admin['username']);
        return $this->back($return, 'success', '身分驗證完成，10 分鐘內可執行管理操作。');
    }

    public function showMigrations(Request $request): Response
    {
        return $this->render('admin/migrate', ['title' => '資料庫更新', 'pending' => $this->migrator()->pending()]);
    }

    public function migrate(Request $request): Response
    {
        $done = $this->migrator()->migrate();
        $admin = $this->admin();
        $this->app->logger->log('admin', 'system.migrate', Log::RESULT_SUCCESS, (int)$admin['id'], (string)$admin['username'],
            $done === [] ? '沒有待套用的更新' : '套用 ' . implode('、', $done));
        return $this->back('/admin/users', 'success', $done === [] ? '資料庫已是最新版本。' : '資料庫更新完成。');
    }

    /** @param array<string, string> $old */
    private function renderUsers(array $old, ?string $error = null, int $status = 200): Response
    {
        // 臨時密碼只顯示一次：取出後立即從 Session 移除，且不寫入任何紀錄
        $oneTime = $this->app->session->get('one_time_password');
        $this->app->session->remove('one_time_password');
        return $this->render('admin/users', [
            'title' => '使用者管理',
            'users' => $this->app->users->listManageable(),
            'elevated' => $this->elevated(),
            'returnTo' => '/admin/users',
            'old' => $old,
            'error' => $error,
            'oneTimePassword' => is_array($oneTime) ? $oneTime : null,
        ], $status);
    }




    /** @param array<string, mixed> $target */
    private function logTarget(string $action, array $target, ?string $detail = null, string $result = Log::RESULT_SUCCESS): void
    {
        $this->log($action, '對象：' . $target['username'] . ($detail === null ? '' : '；' . $detail), (int)$target['id'], null, null, $result);
    }

    private function globalPermissions(): GlobalPermissions
    {
        return new GlobalPermissions($this->app->db);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->app->db, $this->app->basePath . '/database/migrations');
    }

}
