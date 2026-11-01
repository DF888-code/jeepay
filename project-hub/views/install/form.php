<div class="card shadow-sm auth-card">
  <div class="card-body p-4">
    <h1 class="h4 mb-3">系統安裝</h1>
    <?php if (!empty($dbError)): ?>
      <div class="alert alert-danger mb-0">無法連線資料庫，請檢查 config/config.php 中的 db 設定。</div>
    <?php else: ?>
      <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <p>此頁會建立資料表與第一位<strong>最高權限管理者</strong>。安裝完成後本頁將永久停用。</p>
      <form method="post" action="/install" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="mb-3">
          <label for="install_token" class="form-label">安裝金鑰</label>
          <input id="install_token" type="password" name="install_token" class="form-control" required aria-describedby="token-help">
          <div id="token-help" class="form-text">config.php 中的 install_token。</div>
        </div>
        <div class="mb-3">
          <label for="username" class="form-label">管理者帳號</label>
          <input id="username" name="username" class="form-control" value="<?= e($old['username'] ?? '') ?>" autocapitalize="none" required aria-describedby="user-help">
          <div id="user-help" class="form-text">3–50 個英數字，可含 . _ -</div>
        </div>
        <div class="mb-3">
          <label for="display_name" class="form-label">顯示名稱</label>
          <input id="display_name" name="display_name" class="form-control" value="<?= e($old['display_name'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
          <label for="email" class="form-label">救援 Email</label>
          <input id="email" type="email" name="email" class="form-control" value="<?= e($old['email'] ?? '') ?>" required aria-describedby="email-help">
          <div id="email-help" class="form-text">最高權限管理者的帳號救援信會寄到這裡。</div>
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">密碼</label>
          <input id="password" type="password" name="password" class="form-control" autocomplete="new-password" required aria-describedby="pw-help">
          <div id="pw-help" class="form-text">至少 <?= (int)($minLength ?? 10) ?> 個字元。</div>
        </div>
        <div class="mb-4">
          <label for="password_confirm" class="form-label">再次輸入密碼</label>
          <input id="password_confirm" type="password" name="password_confirm" class="form-control" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">開始安裝</button>
      </form>
    <?php endif; ?>
  </div>
</div>
