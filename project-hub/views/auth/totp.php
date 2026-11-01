<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">兩步驟驗證</h1>
    <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <p>請開啟 Google Authenticator，輸入 <?= e($siteName) ?> 的 6 位數驗證碼。</p>
    <form method="post" action="/login/totp">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-4">
        <label for="code" class="form-label">驗證碼</label>
        <input id="code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-100">驗證並登入</button>
    </form>
  </div>
</div>
