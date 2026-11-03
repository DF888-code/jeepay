<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-4">登入</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form data-turbo="false" method="post" action="/login">
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
    <p class="text-body-secondary small mt-3 mb-0">登入需要密碼與 Google Authenticator 驗證碼。忘記密碼或遺失手機請聯絡最高權限管理者。</p>
    <p class="small mt-2 mb-0"><a href="/recovery">最高權限管理者救援（忘記密碼／遺失驗證器）</a></p>
  </div>
</div>
