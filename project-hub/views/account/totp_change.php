<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">更換 2FA 驗證器</h1>
    <p>請用<strong>新的</strong>驗證器掃描。確認成功後，舊驗證器會立即失效。</p>
    <?php require __DIR__ . '/../partials/totp_setup.php'; ?>
    <form method="post" action="/account/2fa/confirm">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="mb-4">
        <label for="code" class="form-label">新驗證器顯示的 6 位數驗證碼</label>
        <input id="code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill">確認更換</button>
        <a href="/account" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
