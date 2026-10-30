<div class="card narrow">
  <h1>更換 2FA 驗證器</h1>
  <p>請用<strong>新的</strong>驗證器掃描。確認成功後，舊驗證器會立即失效。</p>
  <?php require __DIR__ . '/../partials/totp_setup.php'; ?>
  <form method="post" action="/account/2fa/confirm" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>新驗證器顯示的 6 位數驗證碼
      <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required class="code-input">
    </label>
    <div class="actions">
      <button type="submit" class="btn btn-primary">確認更換</button>
      <a href="/account" class="btn">取消</a>
    </div>
  </form>
</div>
