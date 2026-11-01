<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-4">登入</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-3">
        <label for="username" class="form-label">帳號</label>
        <input id="username" name="username" class="form-control" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" required autofocus>
      </div>
      <div class="mb-4">
        <label for="password" class="form-label">密碼</label>
        <input id="password" type="password" name="password" class="form-control" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">下一步</button>
    </form>
    <p class="text-body-secondary small mt-3 mb-0">登入需要密碼與 Google Authenticator 驗證碼。遺失手機請聯絡最高權限管理者。</p>
  </div>
</div>
