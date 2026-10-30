<div class="card narrow">
  <h1>系統安裝</h1>
  <?php if (!empty($dbError)): ?>
    <div class="alert alert-error">無法連線資料庫，請檢查 config/config.php 中的 db 設定。</div>
  <?php else: ?>
    <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <p>此頁會建立資料表與第一位<strong>最高權限管理者</strong>。安裝完成後本頁將永久停用。</p>
    <form method="post" action="/install" class="form" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>安裝金鑰（config.php 的 install_token）
        <input type="password" name="install_token" required>
      </label>
      <label>管理者帳號（3–50 個英數字，可含 . _ -）
        <input name="username" value="<?= e($old['username'] ?? '') ?>" autocapitalize="none" required>
      </label>
      <label>顯示名稱
        <input name="display_name" value="<?= e($old['display_name'] ?? '') ?>" required>
      </label>
      <label>救援 Email（帳號救援信會寄到這裡）
        <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required>
      </label>
      <label>密碼（至少 <?= (int)($minLength ?? 10) ?> 個字元）
        <input type="password" name="password" autocomplete="new-password" required>
      </label>
      <label>再次輸入密碼
        <input type="password" name="password_confirm" autocomplete="new-password" required>
      </label>
      <button type="submit" class="btn btn-primary">開始安裝</button>
    </form>
  <?php endif; ?>
</div>
