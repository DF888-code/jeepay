<div class="card narrow">
  <h1>登入</h1>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>帳號
      <input name="username" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" required autofocus>
    </label>
    <label>密碼
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn btn-primary">下一步</button>
  </form>
  <p class="muted small">登入需要密碼與 Google Authenticator 驗證碼。遺失手機請聯絡最高權限管理者。</p>
</div>
