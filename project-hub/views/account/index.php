<h1>帳號設定</h1>
<div class="grid-2">
  <section class="card">
    <h2>基本資料</h2>
    <dl class="kv">
      <dt>帳號</dt><dd><?= e($user['username']) ?></dd>
      <dt>顯示名稱</dt><dd><?= e($user['display_name']) ?></dd>
      <dt>身分</dt><dd><?= (int)$user['is_super_admin'] === 1 ? '最高權限管理者' : '一般使用者' ?></dd>
      <dt>上次登入</dt><dd><?= e(\ProjectHub\Support\Clock::display($user['last_login_at'], $timezone)) ?></dd>
    </dl>
  </section>

  <section class="card">
    <h2>兩步驟驗證（2FA）</h2>
    <p>
      狀態：
      <?php if ((int)$user['totp_enabled'] === 1): ?>
        <span class="badge badge-ok">已綁定 Google Authenticator</span>
      <?php else: ?>
        <span class="badge badge-warn">未綁定</span>
      <?php endif; ?>
    </p>
    <h3>更換驗證器</h3>
    <p class="small muted">需要目前的密碼與目前驗證器的驗證碼。完成新驗證器確認前，舊驗證器仍然有效。手機遺失無法取得驗證碼時，請聯絡最高權限管理者重設。</p>
    <form method="post" action="/account/2fa/change" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>目前密碼
        <input type="password" name="current_password" autocomplete="current-password" required>
      </label>
      <label>目前驗證器的驗證碼
        <input name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required class="code-input">
      </label>
      <button type="submit" class="btn">開始更換</button>
    </form>
  </section>

  <section class="card">
    <h2>修改密碼</h2>
    <form method="post" action="/account/password" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>目前密碼
        <input type="password" name="current_password" autocomplete="current-password" required>
      </label>
      <label>新密碼（至少 <?= (int)$minLength ?> 個字元）
        <input type="password" name="new_password" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
      </label>
      <label>再次輸入新密碼
        <input type="password" name="new_password_confirm" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
      </label>
      <button type="submit" class="btn btn-primary">更新密碼</button>
    </form>
    <p class="small muted">修改成功後，其他裝置上的登入狀態會立即失效。</p>
  </section>
</div>
