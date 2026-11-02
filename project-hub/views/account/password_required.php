<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">請變更密碼</h1>
    <p>您目前使用的是管理者提供的臨時密碼，請先設定自己的密碼才能繼續使用系統。</p>
    <form method="post" action="/account/password-required">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-3">
        <label for="rp-current" class="form-label">臨時密碼</label>
        <input id="rp-current" type="password" name="current_password" class="form-control" autocomplete="current-password" required autofocus>
      </div>
      <div class="mb-3">
        <label for="rp-new" class="form-label">新密碼</label>
        <input id="rp-new" type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required aria-describedby="rp-help">
        <div id="rp-help" class="form-text">至少 <?= (int)$minLength ?> 個字元。</div>
      </div>
      <div class="mb-4">
        <label for="rp-confirm" class="form-label">再次輸入新密碼</label>
        <input id="rp-confirm" type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">設定新密碼</button>
    </form>
  </div>
</div>
