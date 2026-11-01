<h1 class="h3 mb-4">帳號設定</h1>
<div class="row g-4 align-items-start">
  <div class="col-12 col-lg-6">
    <section class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">基本資料</div>
      <div class="card-body">
        <dl class="kv">
          <dt>帳號</dt><dd><?= e($user['username']) ?></dd>
          <dt>顯示名稱</dt><dd><?= e($user['display_name']) ?></dd>
          <dt>身分</dt><dd><?= (int)$user['is_super_admin'] === 1 ? '最高權限管理者' : '一般使用者' ?></dd>
          <dt>上次登入</dt><dd><?= e(\ProjectHub\Support\Clock::display($user['last_login_at'], $timezone)) ?></dd>
        </dl>
      </div>
    </section>

    <section class="card shadow-sm">
      <div class="card-header fw-semibold">修改密碼</div>
      <div class="card-body">
        <form method="post" action="/account/password">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="mb-3">
            <label for="pw-current" class="form-label">目前密碼</label>
            <input id="pw-current" type="password" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <div class="mb-3">
            <label for="pw-new" class="form-label">新密碼</label>
            <input id="pw-new" type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required aria-describedby="pw-help">
            <div id="pw-help" class="form-text">至少 <?= (int)$minLength ?> 個字元。</div>
          </div>
          <div class="mb-3">
            <label for="pw-confirm" class="form-label">再次輸入新密碼</label>
            <input id="pw-confirm" type="password" name="new_password_confirm" class="form-control" autocomplete="new-password" minlength="<?= (int)$minLength ?>" required>
          </div>
          <button type="submit" class="btn btn-primary">更新密碼</button>
          <p class="form-text mb-0 mt-2">修改成功後，其他裝置上的登入狀態會立即失效。</p>
        </form>
      </div>
    </section>
  </div>

  <div class="col-12 col-lg-6">
    <section class="card shadow-sm">
      <div class="card-header fw-semibold">兩步驟驗證（2FA）</div>
      <div class="card-body">
        <p>
          狀態：
          <?php if ((int)$user['totp_enabled'] === 1): ?>
            <span class="badge text-bg-success">已綁定 Google Authenticator</span>
          <?php else: ?>
            <span class="badge text-bg-warning">未綁定</span>
          <?php endif; ?>
        </p>
        <h2 class="h6 mt-4">更換驗證器</h2>
        <p class="small text-body-secondary">需要目前的密碼與目前驗證器的驗證碼。完成新驗證器確認前，舊驗證器仍然有效。手機遺失無法取得驗證碼時，請聯絡最高權限管理者重設。</p>
        <form method="post" action="/account/2fa/change">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <div class="mb-3">
            <label for="tf-password" class="form-label">目前密碼</label>
            <input id="tf-password" type="password" name="current_password" class="form-control" autocomplete="current-password" required>
          </div>
          <div class="mb-3">
            <label for="tf-code" class="form-label">目前驗證器的驗證碼</label>
            <input id="tf-code" name="code" class="form-control code-input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
          </div>
          <button type="submit" class="btn btn-outline-primary">開始更換</button>
        </form>
      </div>
    </section>
  </div>
</div>
