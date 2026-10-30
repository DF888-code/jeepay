<div class="card narrow">
  <h1>設定兩步驟驗證</h1>
  <p>您的帳號需要先綁定 Google Authenticator，完成後才能使用系統。</p>
  <?php require __DIR__ . '/../partials/totp_setup.php'; ?>
  <form method="post" action="/2fa/setup" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>輸入驗證器顯示的 6 位數驗證碼
      <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required class="code-input">
    </label>
    <button type="submit" class="btn btn-primary">完成綁定</button>
  </form>
</div>
