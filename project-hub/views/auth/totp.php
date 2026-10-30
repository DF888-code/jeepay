<div class="card narrow">
  <h1>兩步驟驗證</h1>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p>請開啟 Google Authenticator，輸入 <?= e($siteName) ?> 的 6 位數驗證碼。</p>
  <form method="post" action="/login/totp" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>驗證碼
      <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus class="code-input">
    </label>
    <button type="submit" class="btn btn-primary">驗證並登入</button>
  </form>
</div>
