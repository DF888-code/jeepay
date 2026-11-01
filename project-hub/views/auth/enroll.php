<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">設定兩步驟驗證</h1>
    <p>您的帳號需要先綁定 Google Authenticator，完成後才能使用系統。</p>
    <?php require __DIR__ . '/../partials/totp_setup.php'; ?>
    <form method="post" action="/2fa/setup">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-4">
        <label for="code" class="form-label">輸入驗證器顯示的 6 位數驗證碼</label>
        <input id="code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">完成綁定</button>
    </form>
  </div>
</div>
